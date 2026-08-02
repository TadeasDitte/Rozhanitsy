<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\NvdCpeResolver;

beforeEach(function () {
    $this->resolver = new NvdCpeResolver;
});

function cpe(string $vendor, string $product): string
{
    return "cpe:2.3:a:{$vendor}:{$product}:1.0:*:*:*:*:*:*:*";
}

function productNamed(string $vendorSlug, string $productSlug): Product
{
    $vendor = Vendor::factory()->create(['name' => $vendorSlug, 'slug' => $vendorSlug]);

    return Product::factory()->for($vendor)->create(['name' => $productSlug, 'slug' => $productSlug]);
}

test('an existing exact mapping resolves without fuzzy matching', function () {
    $product = productNamed('automattic', 'woocommerce');
    CpeMap::factory()->forPair('automattic', 'woocommerce', $product)->create();

    $resolved = $this->resolver->resolve(cpe('automattic', 'woocommerce'));

    expect($resolved['product_id'])->toBe($product->id)
        ->and($resolved['confidence'])->toBe('exact');
});

test('an unresolvable pair is reported as unmatched and learns nothing', function () {
    productNamed('automattic', 'woocommerce');

    $resolved = $this->resolver->resolve(cpe('totally', 'different-thing'));

    expect($resolved['product_id'])->toBeNull()
        ->and($resolved['confidence'])->toBe('unmatched')
        ->and(CpeMap::count())->toBe(0);
});

test('a near miss is fuzzy matched and written back into cpe_map', function () {
    $product = productNamed('automattic', 'woocommerce');

    $resolved = $this->resolver->resolve(cpe('automattic', 'woo-commerce'));

    expect($resolved['product_id'])->toBe($product->id)
        ->and($resolved['confidence'])->toBe('fuzzy');

    $learned = CpeMap::sole();

    expect($learned->cpe_vendor)->toBe('automattic')
        ->and($learned->cpe_product)->toBe('woo-commerce')
        ->and($learned->product_id)->toBe($product->id)
        ->and($learned->match_type)->toBe('fuzzy');
});

/** The 0.87 threshold must reject merely plausible matches, not just gibberish. */
test('a distant name is not fuzzy matched', function () {
    productNamed('automattic', 'woocommerce');

    expect($this->resolver->resolve(cpe('automattic', 'woocommerce-subscriptions'))['confidence'])
        ->toBe('unmatched');
});

/**
 * The point of writing fuzzy results back: the live check must only ever need
 * an exact cpe_map lookup, never fuzzy resolution at request time.
 */
test('a learned pair is served from cpe_map on the next resolve', function () {
    $product = productNamed('automattic', 'woocommerce');

    $this->resolver->resolve(cpe('automattic', 'woo-commerce'));
    $this->resolver->flush();

    $resolved = $this->resolver->resolve(cpe('automattic', 'woo-commerce'));

    expect($resolved['product_id'])->toBe($product->id)
        ->and(CpeMap::count())->toBe(1);
});

/** A guess must not be laundered into a certainty by a later re-sync. */
test('a pair learned by fuzzy matching stays fuzzy', function () {
    $product = productNamed('automattic', 'woocommerce');
    CpeMap::factory()->fuzzy()->forPair('automattic', 'woo-commerce', $product)->create();

    expect($this->resolver->resolve(cpe('automattic', 'woo-commerce'))['confidence'])->toBe('fuzzy');
});

test('a malformed cpe string is unmatched', function (string $criteria) {
    expect($this->resolver->resolve($criteria)['confidence'])->toBe('unmatched');
})->with([
    'not-a-cpe',
    'cpe:2.3:a',
    'cpe:2.3:a:::1.0:*:*:*:*:*:*:*',
]);

/** NVD uses "*" as a wildcard; it must never be treated as a real vendor. */
test('wildcard vendor or product is unmatched', function () {
    productNamed('automattic', 'woocommerce');

    expect($this->resolver->resolve(cpe('*', '*'))['confidence'])->toBe('unmatched')
        ->and($this->resolver->resolve(cpe('automattic', '*'))['confidence'])->toBe('unmatched')
        ->and(CpeMap::count())->toBe(0);
});

test('repeated resolves of the same pair do not requery the database', function () {
    $product = productNamed('automattic', 'woocommerce');
    CpeMap::factory()->forPair('automattic', 'woocommerce', $product)->create();

    $this->resolver->resolve(cpe('automattic', 'woocommerce'));

    DB::enableQueryLog();
    $this->resolver->resolve(cpe('automattic', 'woocommerce'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});
