<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Source::updateOrCreate(
            ['slug' => 'nvd'],
            [
                'name' => 'NIST NVD',
                'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0',
            ],
        );
    }
}
