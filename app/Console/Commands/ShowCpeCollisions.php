<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShowCpeCollisions extends Command
{
    protected $signature = 'cpe:collisions';

    protected $description = 'List cpe_vendor values whose distinct cpe_product entries resolve to the same product_id, for manual review';

    public function handle(): int
    {
        $collisions = DB::table('cpe_map')
            ->select('cpe_vendor', 'product_id', DB::raw('COUNT(DISTINCT cpe_product) AS distinct_cpe_products'))
            ->groupBy('cpe_vendor', 'product_id')
            ->havingRaw('COUNT(DISTINCT cpe_product) > 1')
            ->get();

        if ($collisions->isEmpty()) {
            $this->info('No cpe_vendor collapses multiple cpe_product values onto one product.');

            return self::SUCCESS;
        }

        $rows = $collisions->flatMap(function (\stdClass $collision): Collection {
            $products = DB::table('cpe_map')
                ->join('products', 'products.id', '=', 'cpe_map.product_id')
                ->where('cpe_map.cpe_vendor', $collision->cpe_vendor)
                ->where('cpe_map.product_id', $collision->product_id)
                ->select('cpe_map.cpe_product', 'cpe_map.match_type', 'products.name')
                ->get();

            return $products->map(fn (\stdClass $row): array => [
                $collision->cpe_vendor,
                $row->name,
                $row->cpe_product,
                $row->match_type,
            ]);
        });

        $this->table(['Vendor', 'Product', 'CPE Product', 'Match Type'], $rows->all());

        $this->newLine();
        $this->warn('Not every row above is wrong — some are legitimately-merged name variants. Verify each product_id genuinely represents one independently-versioned thing before treating it as a bug.');

        return self::SUCCESS;
    }
}
