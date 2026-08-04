<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VendorSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const NAMES = [
        'WordPress',
        'Automattic',
        'WooCommerce',
        'Yoast',
        'Elementor',
        'Rocklobster',
        'Joomla',
        'Drupal',
        'PHP',
        'OpenSSL',
        'nginx',
        'Apache',
        'MySQL',
        'phpMyAdmin',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Vendor::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            );
        }
    }
}
