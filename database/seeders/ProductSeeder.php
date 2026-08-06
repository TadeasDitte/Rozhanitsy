<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Vendor slug => list of [name, type].
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const CATALOG = [
        'wordpress' => [
            ['WordPress', 'core'],
        ],
        'automattic' => [
            ['Akismet', 'plugin'],
        ],
        'woocommerce' => [
            ['WooCommerce', 'plugin'],
        ],
        'yoast' => [
            ['Yoast SEO', 'plugin'],
        ],
        'elementor' => [
            ['Elementor', 'plugin'],
        ],
        'rocklobster' => [
            ['Contact Form 7', 'plugin'],
        ],
        'wordfence' => [
            ['Wordfence', 'plugin'],
        ],
        'joomla' => [
            ['Joomla', 'core'],
            ['JCE', 'plugin'],
            ['Akeeba Backup', 'plugin'],
            ['Joomla Framework Database', 'library'],
        ],
        'drupal' => [
            ['Drupal', 'core'],
        ],
        'php' => [
            ['PHP', 'package'],
        ],
        'openssl' => [
            ['OpenSSL', 'library'],
        ],
        'nginx' => [
            ['nginx', 'core'],
        ],
        'apache' => [
            ['HTTP Server', 'core'],
        ],
        'mysql' => [
            ['MySQL', 'core'],
        ],
        'phpmyadmin' => [
            ['phpMyAdmin', 'core'],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $vendorSlug => $products) {
            $vendor = Vendor::where('slug', $vendorSlug)->first();

            if ($vendor === null) {
                continue;
            }

            foreach ($products as [$name, $type]) {
                Product::firstOrCreate(
                    ['vendor_id' => $vendor->id, 'slug' => Str::slug($name)],
                    ['name' => $name, 'type' => $type],
                );
            }
        }
    }
}
