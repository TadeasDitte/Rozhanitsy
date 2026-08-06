<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\UnmatchedLookup;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;

test('a pair below the min-hits threshold is left untouched', function () {
    $pair = UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 4]);

    $this->artisan('nvd:promote-unmatched --min-hits=5')->assertSuccessful();

    expect(UnmatchedLookup::find($pair->id))->not->toBeNull()
        ->and(Vendor::where('slug', 'acme')->exists())->toBeFalse();
});

test('a qualifying pair is promoted into vendor, product, and an exact cpe_map row', function () {
    $pair = UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --min-hits=5')->assertSuccessful();

    $vendor = Vendor::where('slug', 'acme')->sole();
    $product = Product::where('vendor_id', $vendor->id)->where('slug', 'widget')->sole();
    $cpeMap = CpeMap::where('cpe_vendor', 'acme')->where('cpe_product', 'widget')->sole();

    expect($vendor->name)->toBe('Acme')
        ->and($product->name)->toBe('Widget')
        ->and($product->type)->toBe('plugin')
        ->and($cpeMap->product_id)->toBe($product->id)
        ->and($cpeMap->match_type)->toBe(CpeMap::TYPE_EXACT)
        ->and(UnmatchedLookup::find($pair->id))->toBeNull();
});

test('the --type option controls the promoted product type', function () {
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --min-hits=5 --type=library')->assertSuccessful();

    expect(Product::where('slug', 'widget')->sole()->type)->toBe('library');
});

test('an invalid --type is rejected without touching the catalog', function () {
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --type=not-a-real-type')->assertFailed();

    expect(Vendor::where('slug', 'acme')->exists())->toBeFalse();
});

test('promoting reuses an existing vendor and product instead of duplicating them', function () {
    $vendor = Vendor::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Widget', 'slug' => 'widget', 'type' => 'plugin']);

    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --min-hits=5')->assertSuccessful();

    expect(Vendor::where('slug', 'acme')->count())->toBe(1)
        ->and(Product::where('vendor_id', $vendor->id)->count())->toBe(1)
        ->and(CpeMap::where('cpe_vendor', 'acme')->where('cpe_product', 'widget')->sole()->product_id)->toBe($product->id);
});

test('--dry-run previews without writing anything', function () {
    $pair = UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);

    $output = $this->artisan('nvd:promote-unmatched --min-hits=5 --dry-run')->assertSuccessful();

    expect(Vendor::where('slug', 'acme')->exists())->toBeFalse()
        ->and(UnmatchedLookup::find($pair->id))->not->toBeNull();
});

test('--limit caps how many pairs are processed in one run', function () {
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'one', 'hit_count' => 30]);
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'two', 'hit_count' => 20]);
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'three', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --min-hits=5 --limit=2')->assertSuccessful();

    expect(UnmatchedLookup::count())->toBe(1)
        ->and(Product::count())->toBe(2);
});

test('a unique-constraint collision on one pair does not abort the rest of the batch', function () {
    $vendor = Vendor::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Product::factory()->for($vendor)->create(['name' => 'Widget', 'slug' => 'widget-legacy', 'type' => 'plugin']);

    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'other', 'cpe_product' => 'gadget', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --min-hits=5')->assertSuccessful();

    expect(CpeMap::where('cpe_vendor', 'acme')->where('cpe_product', 'widget')->exists())->toBeFalse()
        ->and(UnmatchedLookup::where('cpe_vendor', 'acme')->exists())->toBeTrue()
        ->and(CpeMap::where('cpe_vendor', 'other')->where('cpe_product', 'gadget')->exists())->toBeTrue()
        ->and(UnmatchedLookup::where('cpe_vendor', 'other')->exists())->toBeFalse();
});

test('promotion immediately relinks matching previously-unmatched vulnerability ranges', function () {
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 10]);

    $range = VulnerabilityRange::factory()
        ->for(Vulnerability::factory())
        ->unmatched()
        ->create(['raw_cpe' => 'cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*']);

    $this->artisan('nvd:promote-unmatched --min-hits=5')->assertSuccessful();

    $product = Product::where('slug', 'widget')->sole();

    expect($range->fresh()->product_id)->toBe($product->id)
        ->and($range->fresh()->match_confidence)->toBe(VulnerabilityRange::MATCH_EXACT);
});

test('one run promotes every qualifying pair', function () {
    UnmatchedLookup::factory()->count(120)->sequence(
        fn ($sequence) => ['cpe_vendor' => 'acme', 'cpe_product' => 'widget-'.$sequence->index, 'hit_count' => 10],
    )->create();

    $this->artisan('nvd:promote-unmatched --min-hits=10')->assertSuccessful();

    expect(UnmatchedLookup::count())->toBe(0)
        ->and(Product::count())->toBe(120);
});

test('an explicit --limit still caps a run', function () {
    UnmatchedLookup::factory()->count(5)->sequence(
        fn ($sequence) => ['cpe_vendor' => 'acme', 'cpe_product' => 'widget-'.$sequence->index, 'hit_count' => 10],
    )->create();

    $this->artisan('nvd:promote-unmatched --min-hits=10 --limit=2')->assertSuccessful();

    expect(UnmatchedLookup::count())->toBe(3)
        ->and(Product::count())->toBe(2);
});

test('a pair that cannot be promoted does not block the rest', function () {
    $vendor = Vendor::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    Product::factory()->for($vendor)->create(['slug' => 'widget', 'name' => 'Taken']);

    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'Widget', 'hit_count' => 99]);
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'gadget', 'hit_count' => 10]);

    $this->artisan('nvd:promote-unmatched --min-hits=5')->assertSuccessful();

    expect(Product::where('slug', 'gadget')->exists())->toBeTrue()
        ->and(UnmatchedLookup::where('cpe_product', 'gadget')->exists())->toBeFalse();
});
