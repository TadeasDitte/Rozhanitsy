<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\VulnerabilityRange;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ShowCpeVariants extends Command
{
    protected $signature = 'cpe:variants {--limit=25 : Maximum rows to display}';

    protected $description = 'List CPE names on unresolved ranges that belong to a catalogued vendor or read as a variant of a catalogued product, ranked by the CVEs a mapping would unlock';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $products = Product::with('vendor')->get();

        if ($products->isEmpty()) {
            $this->info('The product catalog is empty; nothing to compare against.');

            return self::SUCCESS;
        }

        /** @var array<string, array{vendor: string, product: string, cves: array<int, true>, nearest: string}> $candidates */
        $candidates = [];

        VulnerabilityRange::unmatched()
            ->whereNull('product_id')
            ->whereNotNull('raw_cpe')
            ->chunkById(1000, function (Collection $ranges) use ($products, &$candidates): void {
                foreach ($ranges as $range) {
                    [$vendor, $product] = $this->parseCpe((string) $range->raw_cpe);

                    if ($vendor === null || $product === null) {
                        continue;
                    }

                    $key = $vendor.'|'.$product;

                    if (! array_key_exists($key, $candidates)) {
                        $nearest = $this->nearestCatalogProduct($vendor, $product, $products);

                        if ($nearest === null) {
                            continue;
                        }

                        $candidates[$key] = ['vendor' => $vendor, 'product' => $product, 'cves' => [], 'nearest' => $nearest];
                    }

                    $candidates[$key]['cves'][$range->vulnerability_id] = true;
                }
            });

        if ($candidates === []) {
            $this->info('No unresolved CPE name relates to anything in the catalog.');

            return self::SUCCESS;
        }

        $candidates = array_values($candidates);

        usort($candidates, fn (array $first, array $second): int => count($second['cves']) <=> count($first['cves']));

        $rows = array_map(
            fn (array $candidate): array => [$candidate['vendor'], $candidate['product'], count($candidate['cves']), $candidate['nearest']],
            array_slice($candidates, 0, $limit),
        );

        $this->table(['CPE Vendor', 'CPE Product', 'CVEs', 'Nearest Catalog Product'], $rows);

        $this->newLine();
        $this->line('Each row is a CPE name no product claims. Verify one against NVD before adding it to CpeMapSeeder: a separately released edition of a catalogued product belongs on this list permanently and must not be mapped.');
        $this->line('Showing '.count($rows).' of '.count($candidates).'.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function nearestCatalogProduct(string $vendor, string $product, Collection $products): ?string
    {
        $vendorWords = $this->words($vendor);
        $productWords = $this->words($product);

        foreach ($products as $candidate) {
            $catalogWords = $this->words($candidate->slug);

            $sharesVendor = $this->words($candidate->vendor->slug) === $vendorWords;
            $isVariant = $catalogWords !== $productWords
                && (array_diff($catalogWords, $productWords) === [] || array_diff($productWords, $catalogWords) === []);

            if ($sharesVendor || $isVariant) {
                return $candidate->vendor->name.' / '.$candidate->name;
            }
        }

        return null;
    }

    /**
     * cpe:2.3:a:vendor:product:version:...
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseCpe(string $criteria): array
    {
        $parts = explode(':', $criteria);

        $vendor = $parts[3] ?? null;
        $product = $parts[4] ?? null;

        if ($vendor === null || $product === null || $vendor === '' || $product === '' || $vendor === '*' || $product === '*') {
            return [null, null];
        }

        return [Str::lower($vendor), Str::lower($product)];
    }

    /**
     * @return list<string>
     */
    private function words(string $name): array
    {
        return array_values(array_unique(array_filter(explode('-', Str::slug($name)))));
    }
}
