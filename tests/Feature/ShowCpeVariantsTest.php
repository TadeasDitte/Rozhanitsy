<?php

use App\Models\Product;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;

function catalogued(string $vendorSlug, string $productSlug): Product
{
    $vendor = Vendor::factory()->create(['name' => $vendorSlug, 'slug' => $vendorSlug]);

    return Product::factory()->for($vendor)->create(['name' => $productSlug, 'slug' => $productSlug]);
}

function unresolvedRange(string $criteria): VulnerabilityRange
{
    return VulnerabilityRange::factory()->unmatched()->create([
        'vulnerability_id' => Vulnerability::factory()->create()->id,
        'product_id' => null,
        'raw_cpe' => $criteria,
    ]);
}

test('it surfaces an unmapped cpe belonging to a catalogued vendor', function () {
    catalogued('elementor', 'elementor');
    unresolvedRange('cpe:2.3:a:elementor:website_builder:*:*:*:*:*:*:*:*');

    $this->artisan('cpe:variants')
        ->expectsOutputToContain('website_builder')
        ->assertSuccessful();
});

test('it surfaces a variant of a catalogued product under another vendor name', function () {
    catalogued('wordpress', 'wordpress');
    unresolvedRange('cpe:2.3:a:someone_else:wordpress_mu:*:*:*:*:*:*:*:*');

    $this->artisan('cpe:variants')
        ->expectsOutputToContain('wordpress_mu')
        ->assertSuccessful();
});

test('it ignores cpes unrelated to anything catalogued', function () {
    catalogued('elementor', 'elementor');
    unresolvedRange('cpe:2.3:a:nobody:nothing:*:*:*:*:*:*:*:*');

    $this->artisan('cpe:variants')
        ->expectsOutputToContain('No unresolved CPE name relates to anything in the catalog.')
        ->assertSuccessful();
});

test('it ignores a wildcard cpe', function () {
    catalogued('elementor', 'elementor');
    unresolvedRange('cpe:2.3:a:*:*:*:*:*:*:*:*:*:*');

    $this->artisan('cpe:variants')
        ->expectsOutputToContain('No unresolved CPE name relates to anything in the catalog.')
        ->assertSuccessful();
});

test('it counts distinct cves rather than ranges', function () {
    catalogued('elementor', 'elementor');

    $vulnerability = Vulnerability::factory()->create();

    foreach (['1.0', '2.0'] as $version) {
        VulnerabilityRange::factory()->unmatched()->create([
            'vulnerability_id' => $vulnerability->id,
            'product_id' => null,
            'raw_cpe' => 'cpe:2.3:a:elementor:website_builder:*:*:*:*:*:*:*:*',
            'version_start' => $version,
        ]);
    }

    $this->artisan('cpe:variants')
        ->expectsOutputToContain('website_builder')
        ->expectsOutputToContain('Showing 1 of 1')
        ->assertSuccessful();
});

test('it reports an empty catalog rather than scanning', function () {
    unresolvedRange('cpe:2.3:a:elementor:website_builder:*:*:*:*:*:*:*:*');

    $this->artisan('cpe:variants')
        ->expectsOutputToContain('The product catalog is empty')
        ->assertSuccessful();
});
