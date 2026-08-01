<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class Source extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('sources')->upsert([
            ['slug' => 'nvd', 'name' => 'NIST NVD', 'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0'],
        ]);
    }
}
