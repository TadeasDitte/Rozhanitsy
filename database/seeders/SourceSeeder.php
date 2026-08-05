<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        Source::updateOrCreate(
            ['slug' => 'nvd'],
            [
                'name' => 'NIST NVD',
                'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0',
                'driver' => 'nvd',
                'page_size' => 2000,
                'request_delay_ms' => 600,
                'unauthenticated_request_delay_ms' => 6000,
            ],
        );
    }
}
