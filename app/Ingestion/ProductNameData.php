<?php

namespace App\Ingestion;

final readonly class ProductNameData
{
    /**
     * @param  ?string  $cnaVendor  the vendor CNA ranges of the product are filed under when NVD has not analysed a record
     * @param  string  $cnaProduct  the product those ranges are filed under
     * @param  ?string  $vendor  the CPE vendor NVD uses for the product
     * @param  string  $product  the CPE product NVD uses for it
     */
    public function __construct(
        public ?string $cnaVendor,
        public string $cnaProduct,
        public ?string $vendor,
        public string $product,
    ) {}
}
