<?php

namespace App\Console\Commands;

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\UnmatchedLookup;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PromoteUnmatchedCatalogEntries extends Command
{
    protected $signature = 'nvd:promote-unmatched
        {--min-hits=5 : Minimum hit_count required to promote a pair}
        {--limit=0 : Maximum pairs to process this run, 0 for every qualifying pair}
        {--type=plugin : Product type assigned to promoted pairs}
        {--dry-run : Preview what would be promoted without writing}';

    protected $description = 'Promote frequently-seen unmatched vendor/product pairs into the product catalog (Vendor, Product, CpeMap), closing scan coverage gaps without manual admin entry';

    private const MAX_LISTED = 25;

    public function handle(): int
    {
        $minHits = max(1, (int) $this->option('min-hits'));
        $limit = max(0, (int) $this->option('limit'));
        $type = $this->option('type');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($type, Product::TYPES, true)) {
            $this->error("Invalid --type \"{$type}\". Must be one of: ".implode(', ', Product::TYPES));

            return self::FAILURE;
        }

        $pairs = UnmatchedLookup::query()
            ->where('hit_count', '>=', $minHits)
            ->orderByDesc('hit_count')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        if ($pairs->isEmpty()) {
            $this->info("No unmatched pairs meet the --min-hits={$minHits} threshold.");

            return self::SUCCESS;
        }

        $promoted = [];

        foreach ($pairs as $pair) {
            if ($dryRun) {
                $promoted[] = [$pair->cpe_vendor, $pair->cpe_product, $pair->hit_count];

                continue;
            }

            try {
                DB::transaction(function () use ($pair, $type, &$promoted): void {
                    $vendor = Vendor::firstOrCreate(
                        ['slug' => Str::slug($pair->cpe_vendor)],
                        ['name' => Str::headline($pair->cpe_vendor)],
                    );

                    $product = Product::firstOrCreate(
                        ['vendor_id' => $vendor->id, 'slug' => Str::slug($pair->cpe_product)],
                        ['name' => Str::headline($pair->cpe_product), 'type' => $type],
                    );

                    CpeMap::firstOrCreate(
                        ['cpe_vendor' => $pair->cpe_vendor, 'cpe_product' => $pair->cpe_product],
                        ['product_id' => $product->id, 'match_type' => CpeMap::TYPE_EXACT],
                    );

                    $pair->delete();

                    $promoted[] = [$pair->cpe_vendor, $pair->cpe_product, $pair->hit_count];
                });
            } catch (QueryException $exception) {
                Log::warning('Failed to promote unmatched CPE pair to the catalog.', [
                    'cpe_vendor' => $pair->cpe_vendor,
                    'cpe_product' => $pair->cpe_product,
                    'exception' => $exception->getMessage(),
                ]);

                $this->warn("Skipped {$pair->cpe_vendor}/{$pair->cpe_product}: {$exception->getMessage()}");
            }
        }

        if ($promoted !== []) {
            $this->table(['CPE Vendor', 'CPE Product', 'Hits'], array_slice($promoted, 0, self::MAX_LISTED));

            if (count($promoted) > self::MAX_LISTED) {
                $this->line('Listing the '.self::MAX_LISTED.' highest-hit pairs of '.count($promoted).'.');
            }
        }

        $verb = $dryRun ? 'Would promote' : 'Promoted';
        $this->info("{$verb} ".count($promoted).' pair(s).');

        if (! $dryRun && $promoted !== []) {
            $this->call('nvd:relink');
        }

        return self::SUCCESS;
    }
}
