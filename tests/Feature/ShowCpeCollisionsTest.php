<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Support\Facades\Artisan;

function runCpeCollisions(): string
{
    Artisan::call('cpe:collisions');

    return Artisan::output();
}

test('it reports when there are no collisions', function () {
    $vendor = Vendor::factory()->create(['slug' => 'joomla']);
    $cms = Product::factory()->for($vendor)->create(['name' => 'Joomla']);
    $framework = Product::factory()->for($vendor)->create(['name' => 'Joomla Framework Database']);

    CpeMap::factory()->forPair('joomla', 'joomla', $cms)->create();
    CpeMap::factory()->forPair('joomla', 'database', $framework)->create();

    expect(runCpeCollisions())->toContain('No cpe_vendor collapses multiple cpe_product values onto one product.');
});

/**
 * The reported bug's general shape: two distinct cpe_product values under
 * the same vendor resolving to the same product_id — the exact collision
 * pattern that let joomla/database's CVE leak onto Joomla CMS.
 */
test('it lists a vendor whose cpe_products collapse onto one product', function () {
    $vendor = Vendor::factory()->create(['slug' => 'joomla']);
    $cms = Product::factory()->for($vendor)->create(['name' => 'Joomla']);

    CpeMap::factory()->forPair('joomla', 'joomla', $cms)->create();
    CpeMap::factory()->forPair('joomla', 'database', $cms)->create();

    $output = runCpeCollisions();

    expect($output)
        ->toContain('joomla')
        ->toContain('Joomla')
        ->toContain('database');
});

test('the command exits successfully', function () {
    $this->artisan('cpe:collisions')->assertSuccessful();
});
