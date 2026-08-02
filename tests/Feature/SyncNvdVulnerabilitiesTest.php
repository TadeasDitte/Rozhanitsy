<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\Source;
use App\Models\SyncState;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->source = Source::factory()->nvd()->create();
});

/**
 * @param  array<int, array<string, mixed>>  $cpeMatches
 * @return array<string, mixed>
 */
function cveEntry(string $cveId, array $cpeMatches = []): array
{
    return [
        'cve' => [
            'id' => $cveId,
            'published' => '2026-01-01T00:00:00.000',
            'lastModified' => '2026-02-01T00:00:00.000',
            'descriptions' => [
                ['lang' => 'es', 'value' => 'Una vulnerabilidad'],
                ['lang' => 'en', 'value' => 'A vulnerability'],
            ],
            'metrics' => [
                'cvssMetricV31' => [[
                    'cvssData' => [
                        'baseScore' => 9.8,
                        'vectorString' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H',
                        'version' => '3.1',
                        'baseSeverity' => 'CRITICAL',
                    ],
                ]],
            ],
            'configurations' => [
                ['nodes' => [['cpeMatch' => $cpeMatches]]],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function cpeMatch(string $criteria, array $overrides = []): array
{
    return array_merge(['vulnerable' => true, 'criteria' => $criteria], $overrides);
}

function fakeNvd(array $vulnerabilities, int $totalResults = 1): void
{
    Http::fake([
        'services.nvd.nist.gov/*' => Http::response([
            'totalResults' => $totalResults,
            'vulnerabilities' => $vulnerabilities,
        ]),
    ]);
}

test('it fails cleanly when the nvd source row is missing', function () {
    Source::query()->delete();

    $this->artisan('nvd:sync')->assertFailed();
});

/**
 * The endpoint lives on the source row, not in the command. Keeping a constant
 * here as well would be a second source of truth free to drift from the seeder.
 */
test('it requests the endpoint stored on the source row', function () {
    $this->source->update(['url' => 'https://mirror.example.test/cves/2.0']);

    Http::fake([
        'mirror.example.test/*' => Http::response(['totalResults' => 0, 'vulnerabilities' => []]),
    ]);

    $this->artisan('nvd:sync')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://mirror.example.test/cves/2.0'));
});

test('it fails cleanly when the source row has no endpoint url', function () {
    Http::fake();

    $this->source->update(['url' => null]);

    $this->artisan('nvd:sync')->assertFailed();

    Http::assertNothingSent();
});

test('it stores a cve with its normalized cvss fields', function () {
    fakeNvd([cveEntry('CVE-2026-1111')]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $vulnerability = Vulnerability::sole();

    expect($vulnerability->cve_id)->toBe('CVE-2026-1111')
        ->and($vulnerability->source_id)->toBe($this->source->id)
        ->and($vulnerability->description)->toBe('A vulnerability')
        ->and($vulnerability->cvss_score)->toBe(9.8)
        ->and($vulnerability->cvss_version)->toBe('3.1')
        ->and($vulnerability->cvss_severity)->toBe('CRITICAL')
        ->and($vulnerability->raw_data)->toBeArray()
        ->and($vulnerability->published_at)->not->toBeNull();
});

test('it resolves cpe matches against cpe_map', function () {
    $vendor = Vendor::factory()->create(['name' => 'acme', 'slug' => 'acme']);
    $product = Product::factory()->for($vendor)->create(['name' => 'widget', 'slug' => 'widget']);
    CpeMap::factory()->forPair('acme', 'widget', $product)->create();

    fakeNvd([cveEntry('CVE-2026-2222', [
        cpeMatch('cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*', [
            'versionStartIncluding' => '1.0.0',
            'versionEndExcluding' => '2.0.0',
        ]),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->product_id)->toBe($product->id)
        ->and($range->match_confidence)->toBe('exact')
        ->and($range->version_start)->toBe('1.0.0')
        ->and($range->version_start_incl)->toBeTrue()
        ->and($range->version_end)->toBe('2.0.0')
        ->and($range->version_end_incl)->toBeFalse()
        ->and($range->raw_cpe)->toBe('cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*');
});

test('an unresolvable cpe is stored as an unmatched range', function () {
    fakeNvd([cveEntry('CVE-2026-3333', [
        cpeMatch('cpe:2.3:a:nobody:nothing:*:*:*:*:*:*:*:*'),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->product_id)->toBeNull()
        ->and($range->match_confidence)->toBe('unmatched');
});

test('non vulnerable cpe matches are skipped', function () {
    fakeNvd([cveEntry('CVE-2026-4444', [
        cpeMatch('cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*', ['vulnerable' => false]),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    expect(VulnerabilityRange::count())->toBe(0)
        ->and(Vulnerability::count())->toBe(1);
});

/** Re-syncing must rebuild ranges wholesale, not accrete duplicates. */
test('resyncing a cve rebuilds its ranges instead of duplicating them', function () {
    fakeNvd([cveEntry('CVE-2026-5555', [
        cpeMatch('cpe:2.3:a:nobody:nothing:*:*:*:*:*:*:*:*'),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();
    $this->artisan('nvd:sync')->assertSuccessful();

    expect(Vulnerability::count())->toBe(1)
        ->and(VulnerabilityRange::count())->toBe(1);
});

test('it records the watermark on success', function () {
    fakeNvd([cveEntry('CVE-2026-6666')]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $state = SyncState::sole();

    expect($state->source_id)->toBe($this->source->id)
        ->and($state->last_synced_at)->not->toBeNull()
        ->and($state->last_index)->toBeNull();
});

/**
 * The watermark must not advance past CVEs that were never received, or the
 * next run would silently skip them forever.
 */
test('a failed request leaves the watermark untouched', function () {
    Http::fake(['services.nvd.nist.gov/*' => Http::response(status: 503)]);

    $this->artisan('nvd:sync')->assertFailed();

    expect(SyncState::sole()->last_synced_at)->toBeNull();
});

test('an incremental run sends the watermark as lastModStartDate', function () {
    SyncState::create([
        'source_id' => $this->source->id,
        'last_synced_at' => now()->subDays(3),
    ]);

    fakeNvd([cveEntry('CVE-2026-7777')]);

    $this->artisan('nvd:sync')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'lastModStartDate'));
});

test('a full run ignores the watermark', function () {
    SyncState::create([
        'source_id' => $this->source->id,
        'last_synced_at' => now()->subDays(3),
    ]);

    fakeNvd([cveEntry('CVE-2026-8888')]);

    $this->artisan('nvd:sync --full')->assertSuccessful();

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'lastModStartDate'));
});

test('it requests pages of 2000 results', function () {
    fakeNvd([cveEntry('CVE-2026-9999')]);

    $this->artisan('nvd:sync')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'resultsPerPage=2000')
        && str_contains($request->url(), 'startIndex=0'));
});
