<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use Illuminate\Support\Facades\Artisan;

function runPendingReview(string $arguments = ''): string
{
    Artisan::call(trim("nvd:pending-review {$arguments}"));

    return Artisan::output();
}

test('it reports when nothing is pending review', function () {
    expect(runPendingReview())->toContain('No ranges are pending review.');
});

test('it lists ranges with a resolved product but incomplete version data', function () {
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla']);
    CpeMap::factory()->forPair('joomla', 'joomla', $product)->create();

    VulnerabilityRange::factory()
        ->for(Vulnerability::factory()->state(['cve_id' => 'CVE-2026-9020']))
        ->affecting(null, '6.1.1')
        ->create(['product_id' => $product->id, 'match_confidence' => 'unmatched']);

    $output = runPendingReview();

    expect($output)
        ->toContain('CVE-2026-9020')
        ->toContain('Joomla')
        ->toContain('6.1.1');
});

test('it labels a ghsa-flagged mismatch differently from a missing lower bound', function () {
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla']);

    VulnerabilityRange::factory()
        ->for(Vulnerability::factory()->state(['cve_id' => 'CVE-2025-25226', 'ghsa_ecosystem_mismatch' => true]))
        ->affecting('1.0.0', '2.2.0')
        ->create(['product_id' => $product->id, 'match_confidence' => 'unmatched']);

    expect(runPendingReview())->toContain('GHSA: library, not core product');
});

/** A range that never resolved a product at all is a different kind of gap, tracked via nvd:unmatched instead. */
test('a range with no resolved product is not listed', function () {
    VulnerabilityRange::factory()->unmatched()->create([
        'raw_cpe' => 'cpe:2.3:a:nobody:nothing:*:*:*:*:*:*:*:*',
    ]);

    expect(runPendingReview())->toContain('No ranges are pending review.');
});

test('the limit option caps the number of rows', function () {
    $vendor = Vendor::factory()->create();
    $product = Product::factory()->for($vendor)->create();

    VulnerabilityRange::factory()->count(5)->affecting(null, '2.0.0')->create([
        'product_id' => $product->id,
        'match_confidence' => 'unmatched',
    ]);

    expect(runPendingReview('--limit=2'))->toContain('Showing 2 of 5.');
});

test('the command exits successfully', function () {
    VulnerabilityRange::factory()->affecting(null, '2.0.0')->create(['match_confidence' => 'unmatched']);

    $this->artisan('nvd:pending-review')->assertSuccessful();
});
