<?php

namespace App\Console\Commands;

use App\Models\CpeMap;
use App\Services\NvdCpeResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class PruneVariantCpeMappings extends Command
{
    protected $signature = 'cpe:prune-variants {--dry-run : Preview what would be removed without writing}';

    protected $description = 'Delete fuzzy cpe_map rows the resolver would no longer learn, such as an edition or variant mapped onto its base product';

    public function handle(NvdCpeResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $pruned = [];

        CpeMap::query()
            ->where('match_type', CpeMap::TYPE_FUZZY)
            ->with('product')
            ->chunkById(200, function (Collection $mappings) use ($resolver, $dryRun, &$pruned): void {
                foreach ($mappings as $mapping) {
                    $candidate = $resolver->fuzzyCandidate($mapping->cpe_vendor, $mapping->cpe_product);

                    if ($candidate?->id === $mapping->product_id) {
                        continue;
                    }

                    $pruned[] = [
                        $mapping->cpe_vendor,
                        $mapping->cpe_product,
                        $mapping->product->name,
                        $candidate === null ? 'no match' : $candidate->name,
                    ];

                    if (! $dryRun) {
                        $mapping->delete();
                    }
                }
            });

        if ($pruned === []) {
            $this->info('Every learned mapping still matches under the current policy.');

            return self::SUCCESS;
        }

        $this->table(['CPE Vendor', 'CPE Product', 'Was Mapped To', 'Would Now Match'], $pruned);

        $verb = $dryRun ? 'Would remove' : 'Removed';
        $this->info("{$verb} ".count($pruned).' learned mapping(s).');

        if (! $dryRun) {
            $this->newLine();
            $this->warn('Stored ranges still point at the old products. Run nvd:rebuild-ranges to re-resolve them.');
        }

        return self::SUCCESS;
    }
}
