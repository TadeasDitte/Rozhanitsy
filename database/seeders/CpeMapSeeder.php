<?php

namespace Database\Seeders;

use App\Models\CpeMap;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CpeMapSeeder extends Seeder
{
    /**
     * Explicit, hand-verified NVD CPE vendor/product pairs.
     *
     * These exist so an exact lookup always wins over fuzzy matching for
     * cases known to be ambiguous — most notably `joomla`, where the CMS
     * (`cpe:2.3:a:joomla:joomla\!:*`) and the standalone `joomla/database`
     * Framework library (`cpe:2.3:a:joomla:database:*`) are versioned and
     * released completely independently but share a vendor and a very
     * similar name. Letting either fall through to fuzzy matching risks a
     * CVE against one being attributed to the other.
     *
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
        ],
        'rocklobster' => [
            'contact_form_7' => 'contact-form-7',
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
