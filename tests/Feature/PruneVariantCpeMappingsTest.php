<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\Vendor;

function elementorProduct(): Product
{
    $vendor = Vendor::factory()->create(['name' => 'Elementor', 'slug' => 'elementor']);

    return Product::factory()->for($vendor)->create(['name' => 'Elementor', 'slug' => 'elementor']);
}

test('it removes a learned mapping of a variant onto its base product', function () {
    $product = elementorProduct();
    CpeMap::factory()->fuzzy()->forPair('elementor', 'elementor_pro', $product)->create();

    $this->artisan('cpe:prune-variants')
        ->expectsOutputToContain('elementor_pro')
        ->assertSuccessful();

    expect(CpeMap::count())->toBe(0);
});

test('it keeps a learned mapping the resolver would still make', function () {
    $vendor = Vendor::factory()->create(['name' => 'Automattic', 'slug' => 'automattic']);
    $product = Product::factory()->for($vendor)->create(['name' => 'WooCommerce', 'slug' => 'woocommerce']);

    CpeMap::factory()->fuzzy()->forPair('automattic', 'woo-commerce', $product)->create();

    $this->artisan('cpe:prune-variants')->assertSuccessful();

    expect(CpeMap::count())->toBe(1);
});

test('it never touches an exact mapping', function () {
    $product = elementorProduct();
    CpeMap::factory()->forPair('elementor', 'elementor_pro', $product)->create();

    $this->artisan('cpe:prune-variants')->assertSuccessful();

    expect(CpeMap::count())->toBe(1);
});

test('a dry run reports without deleting', function () {
    $product = elementorProduct();
    CpeMap::factory()->fuzzy()->forPair('elementor', 'elementor_pro', $product)->create();

    $this->artisan('cpe:prune-variants', ['--dry-run' => true])
        ->expectsOutputToContain('Would remove')
        ->assertSuccessful();

    expect(CpeMap::count())->toBe(1);
});

test('it reports when every learned mapping still holds', function () {
    elementorProduct();

    $this->artisan('cpe:prune-variants')
        ->expectsOutputToContain('Every learned mapping still matches')
        ->assertSuccessful();
});

test('it points the operator at the rebuild that finishes the repair', function () {
    $product = elementorProduct();
    CpeMap::factory()->fuzzy()->forPair('elementor', 'elementor_pro', $product)->create();

    $this->artisan('cpe:prune-variants')
        ->expectsOutputToContain('nvd:rebuild-ranges')
        ->assertSuccessful();
});
