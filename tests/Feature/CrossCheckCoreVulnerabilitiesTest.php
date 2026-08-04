<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function coreRange(string $cveId, ?string $productType = 'core'): array
{
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla', 'slug' => 'joomla', 'type' => $productType]);
    CpeMap::factory()->forPair('joomla', 'joomla', $product)->create();

    $vulnerability = Vulnerability::factory()->create(['cve_id' => $cveId]);
    $range = VulnerabilityRange::factory()->for($vulnerability)->affecting('1.0.0', '2.2.0')->create([
        'product_id' => $product->id,
        'match_confidence' => 'exact',
    ]);

    return [$vulnerability, $range, $product];
}

function fakeGhsa(array $body, int $status = 200): void
{
    Http::fake(['api.github.com/*' => Http::response($body, $status)]);
}

test('a cve ghsa tags under a package ecosystem gets its core-attributed ranges downgraded', function () {
    [$vulnerability, $range] = coreRange('CVE-2025-25226');

    fakeGhsa([
        ['vulnerabilities' => [['package' => ['ecosystem' => 'composer', 'name' => 'joomla/database']]]],
    ]);

    $this->artisan('nvd:cross-check-core')->assertSuccessful();

    expect($vulnerability->fresh()->ghsa_ecosystem_mismatch)->toBeTrue()
        ->and($vulnerability->fresh()->ghsa_checked_at)->not->toBeNull()
        ->and($range->fresh()->match_confidence)->toBe('unmatched');
});

test('a genuine core cve with no ghsa ecosystem package is left untouched', function () {
    [$vulnerability, $range] = coreRange('CVE-2026-40383');

    fakeGhsa([]);

    $this->artisan('nvd:cross-check-core')->assertSuccessful();

    expect($vulnerability->fresh()->ghsa_ecosystem_mismatch)->toBeFalse()
        ->and($range->fresh()->match_confidence)->toBe('exact');
});

test('a cve on a non-core product is never considered', function () {
    [$vulnerability] = coreRange('CVE-2026-0001', productType: 'plugin');

    fakeGhsa([
        ['vulnerabilities' => [['package' => ['ecosystem' => 'npm', 'name' => 'whatever']]]],
    ]);

    $this->artisan('nvd:cross-check-core')->assertSuccessful();

    expect($vulnerability->fresh()->ghsa_checked_at)->toBeNull();
    Http::assertNothingSent();
});

test('a cve already checked is skipped on a plain run but re-checked with --force', function () {
    [$vulnerability, $range] = coreRange('CVE-2026-0002');
    $vulnerability->update(['ghsa_checked_at' => now()->subDay(), 'ghsa_ecosystem_mismatch' => false]);

    fakeGhsa([
        ['vulnerabilities' => [['package' => ['ecosystem' => 'composer', 'name' => 'whatever/whatever']]]],
    ]);

    $this->artisan('nvd:cross-check-core')->assertSuccessful();
    Http::assertNothingSent();
    expect($range->fresh()->match_confidence)->toBe('exact');

    $this->artisan('nvd:cross-check-core --force')->assertSuccessful();

    expect($vulnerability->fresh()->ghsa_ecosystem_mismatch)->toBeTrue()
        ->and($range->fresh()->match_confidence)->toBe('unmatched');
});

test('a ghsa request that fails leaves the cve unchecked for a later retry instead of crashing', function () {
    [$vulnerability, $range] = coreRange('CVE-2026-0003');

    fakeGhsa([], status: 503);

    $this->artisan('nvd:cross-check-core')->assertSuccessful();

    expect($vulnerability->fresh()->ghsa_checked_at)->toBeNull()
        ->and($range->fresh()->match_confidence)->toBe('exact');
});

/**
 * The downgrade must survive a later resync/rebuild: VulnerabilityRangeBuilder
 * itself has to respect ghsa_ecosystem_mismatch, otherwise the very next
 * nvd:rebuild-ranges would recompute confidence from the resolver alone and
 * silently undo the fix.
 */
test('the downgrade sticks across a rebuild', function () {
    [$vulnerability, $range] = coreRange('CVE-2025-25226');
    $vulnerability->update([
        'raw_data' => [
            'id' => 'CVE-2025-25226',
            'descriptions' => [['lang' => 'en', 'value' => 'A vulnerability']],
            'configurations' => [
                ['nodes' => [['cpeMatch' => [[
                    'vulnerable' => true,
                    'criteria' => $range->raw_cpe,
                    'versionStartIncluding' => $range->version_start,
                    'versionEndExcluding' => $range->version_end,
                ]]]]],
            ],
        ],
    ]);

    fakeGhsa([
        ['vulnerabilities' => [['package' => ['ecosystem' => 'composer', 'name' => 'joomla/database']]]],
    ]);
    $this->artisan('nvd:cross-check-core')->assertSuccessful();

    expect($range->fresh()->match_confidence)->toBe('unmatched');

    $this->artisan('nvd:rebuild-ranges')->assertSuccessful();

    expect(VulnerabilityRange::where('vulnerability_id', $vulnerability->id)->sole()->match_confidence)->toBe('unmatched');
});
