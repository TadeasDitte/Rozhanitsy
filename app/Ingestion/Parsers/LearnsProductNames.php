<?php

namespace App\Ingestion\Parsers;

use App\Ingestion\ProductNameData;

interface LearnsProductNames
{
    /**
     * The names a source's own data uses for a product, tied to the names the
     * source's analysed data uses for it, as one ParsedRecord::raw_ranges value shows them.
     *
     * @param  array<int, array<string, mixed>>  $rawRanges
     * @return list<ProductNameData>
     */
    public function productNames(array $rawRanges): array;
}
