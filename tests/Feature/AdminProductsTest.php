<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;

test('guests are redirected away from the admin products page', function () {
    $this->get(route('admin.products.index'))->assertRedirect(route('login'));
});

test('a non admin is forbidden', function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get(route('admin.products.index'))
        ->assertForbidden();
});

test('an admin sees vendors grouped with their products', function () {
    $vendor = Vendor::factory()->create(['name' => 'WordPress']);
    Product::factory()->for($vendor)->create(['name' => 'WordPress', 'type' => 'core']);

    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Products')
            ->has('vendors', 1)
            ->has('vendors.0.products', 1)
            ->has('types'));
});

test('an admin can add a product under a new vendor', function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))->post(route('admin.products.store'), [
        'vendor_name' => 'Joomla',
        'product_name' => 'JCE',
        'type' => 'plugin',
    ])->assertRedirect();

    $vendor = Vendor::where('slug', 'joomla')->sole();

    expect(Product::where('vendor_id', $vendor->id)->where('slug', 'jce')->exists())->toBeTrue();
});

test('an admin can add a second product under an existing vendor without duplicating it', function () {
    $vendor = Vendor::factory()->create(['name' => 'WordPress', 'slug' => 'wordpress']);
    Product::factory()->for($vendor)->create(['name' => 'WordPress', 'slug' => 'wordpress', 'type' => 'core']);

    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))->post(route('admin.products.store'), [
        'vendor_name' => 'WordPress',
        'product_name' => 'Akismet',
        'type' => 'plugin',
    ])->assertRedirect();

    expect(Vendor::where('slug', 'wordpress')->count())->toBe(1)
        ->and(Product::where('vendor_id', $vendor->id)->count())->toBe(2);
});

test('adding a duplicate product for the same vendor fails validation', function () {
    $vendor = Vendor::factory()->create(['name' => 'WordPress', 'slug' => 'wordpress']);
    Product::factory()->for($vendor)->create(['name' => 'WordPress', 'slug' => 'wordpress', 'type' => 'core']);

    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))->post(route('admin.products.store'), [
        'vendor_name' => 'WordPress',
        'product_name' => 'WordPress',
        'type' => 'core',
    ])->assertSessionHasErrors('product_name');

    expect(Product::where('vendor_id', $vendor->id)->count())->toBe(1);
});

test('a non admin cannot add a product', function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->post(route('admin.products.store'), [
        'vendor_name' => 'WordPress',
        'product_name' => 'WordPress',
        'type' => 'core',
    ])->assertForbidden();
});

test('an admin can delete a product and its cpe mappings cascade away', function () {
    $vendor = Vendor::factory()->create(['name' => 'WordPress', 'slug' => 'wordpress']);
    $product = Product::factory()->for($vendor)->create(['name' => 'WordPress', 'slug' => 'wordpress', 'type' => 'core']);
    CpeMap::factory()->create(['product_id' => $product->id, 'cpe_vendor' => 'wordpress', 'cpe_product' => 'wordpress']);

    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]))->delete(route('admin.products.destroy', $product))->assertRedirect();

    expect(Product::find($product->id))->toBeNull()
        ->and(CpeMap::where('product_id', $product->id)->exists())->toBeFalse();
});
