<?php

use App\Models\UnmatchedLookup;
use Illuminate\Support\Facades\Artisan;

/**
 * Capture the rendered command output.
 *
 * The artisan() expectation helpers match per write call, so several substrings
 * living on the same table row cannot all be asserted that way.
 */
function runUnmatched(string $arguments = ''): string
{
    /** Force the lazy migration before capturing, see CreateScanHostTest. */
    UnmatchedLookup::query()->exists();

    Artisan::call(trim("nvd:unmatched {$arguments}"));

    return Artisan::output();
}

test('it reports when nothing is unmatched', function () {
    expect(runUnmatched())->toContain('No unmatched CPE lookups recorded');
});

test('it lists unmatched pairs', function () {
    UnmatchedLookup::factory()->create([
        'cpe_vendor' => 'acme',
        'cpe_product' => 'widget',
        'hit_count' => 12,
    ]);

    expect(runUnmatched())
        ->toContain('acme')
        ->toContain('widget')
        ->toContain('12');
});

/** The worklist is only useful if the most requested gaps come first. */
test('it orders by hit count descending', function () {
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'rarevendor', 'cpe_product' => 'thing', 'hit_count' => 2]);
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'commonvendor', 'cpe_product' => 'thing', 'hit_count' => 99]);

    $output = runUnmatched();

    expect(strpos($output, 'commonvendor'))->toBeLessThan(strpos($output, 'rarevendor'));
});

test('the limit option caps the number of rows', function () {
    UnmatchedLookup::factory()->count(5)->create();

    expect(runUnmatched('--limit=2'))->toContain('Showing 2 of 5');
});

test('the min-hits option filters out rarely seen pairs', function () {
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'rarevendor', 'cpe_product' => 'thing', 'hit_count' => 1]);
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'commonvendor', 'cpe_product' => 'thing', 'hit_count' => 40]);

    expect(runUnmatched('--min-hits=10'))
        ->toContain('commonvendor')
        ->not->toContain('rarevendor');
});

test('it reports when nothing meets the min-hits threshold', function () {
    UnmatchedLookup::factory()->create(['hit_count' => 1]);

    expect(runUnmatched('--min-hits=500'))->toContain('No unmatched CPE lookups recorded');
});

test('the command exits successfully', function () {
    UnmatchedLookup::factory()->create();

    $this->artisan('nvd:unmatched')->assertSuccessful();
});
