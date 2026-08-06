<?php

namespace Database\Seeders;

use App\Models\CpeMap;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CpeMapSeeder extends Seeder
{
    /**
     * cpe_vendor => [cpe_product => product slug].
     *
     * @var array<string, array<string, string>>
     */
    public const PAIRS = [
        'joomla' => [
            'joomla' => 'joomla',
            'joomla\!' => 'joomla',
            'database' => 'joomla-framework-database',
        ],
        'automattic' => [
            'akismet' => 'akismet',
        ],
        'woocommerce' => [
            'woocommerce' => 'woocommerce',
        ],
        'yoast' => [
            'yoast_seo' => 'yoast-seo',
            'wordpress_seo' => 'yoast-seo',
        ],
        'elementor' => [
            'elementor' => 'elementor',
            'elementor_page_builder' => 'elementor',
            'website_builder' => 'elementor',
        ],
        'rocklobster' => [
            'contact_form_7' => 'contact-form-7',
        ],
        'wordfence' => [
            'wordfence' => 'wordfence',
            'wordfence_security' => 'wordfence',
        ],
    ];

    public function run(): void
    {
        foreach (self::PAIRS as $vendor => $products) {
            foreach ($products as $cpeProduct => $productSlug) {
                $product = Product::where('slug', $productSlug)->first();

                if ($product === null) {
                    continue;
                }

                CpeMap::firstOrCreate(
                    ['cpe_vendor' => $vendor, 'cpe_product' => $cpeProduct],
                    ['product_id' => $product->id, 'match_type' => CpeMap::TYPE_EXACT],
                );
            }
        }
    }
}
