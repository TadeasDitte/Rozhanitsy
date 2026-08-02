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
        ->and($state->last_synced_at)->not->toBeNull();
});

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

test('an exact version cpe becomes an inclusive point range', function () {
    fakeNvd([cveEntry('CVE-2026-1212', [
        cpeMatch('cpe:2.3:a:apache:log4j:2.14.1:*:*:*:*:*:*:*'),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->version_start)->toBe('2.14.1')
        ->and($range->version_end)->toBe('2.14.1')
        ->and($range->version_start_incl)->toBeTrue()
        ->and($range->version_end_incl)->toBeTrue();
});

test('a wildcard version cpe stays an unbounded range', function () {
    fakeNvd([cveEntry('CVE-2026-1213', [
        cpeMatch('cpe:2.3:a:apache:log4j:*:*:*:*:*:*:*:*'),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->version_start)->toBeNull()
        ->and($range->version_end)->toBeNull();
});

test('explicit range keys win over the cpe version field', function () {
    fakeNvd([cveEntry('CVE-2026-1214', [
        cpeMatch('cpe:2.3:a:apache:log4j:2.14.1:*:*:*:*:*:*:*', [
            'versionStartIncluding' => '2.0.0',
            'versionEndExcluding' => '2.17.0',
        ]),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->version_start)->toBe('2.0.0')
        ->and($range->version_end)->toBe('2.17.0');
});

test('a malformed entry is skipped without aborting the page', function () {
    fakeNvd([
        ['cve' => null],
        ['not_a_cve' => true],
        cveEntry('CVE-2026-1215'),
    ], totalResults: 3);

    $this->artisan('nvd:sync')->assertSuccessful();

    expect(Vulnerability::count())->toBe(1)
        ->and(Vulnerability::sole()->cve_id)->toBe('CVE-2026-1215');
});

test('the page cursor advances by rows received', function () {
    Http::fakeSequence()
        ->push(['totalResults' => 3, 'vulnerabilities' => [cveEntry('CVE-2026-1216'), cveEntry('CVE-2026-1217')]])
        ->push(['totalResults' => 3, 'vulnerabilities' => [cveEntry('CVE-2026-1218')]]);

    $this->artisan('nvd:sync')->assertSuccessful();

    expect(Vulnerability::count())->toBe(3);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'startIndex=2'));
});

test('an unexpected empty page fails instead of reporting success', function () {
    fakeNvd([], totalResults: 500);

    $this->artisan('nvd:sync')->assertFailed();

    expect(SyncState::sole()->last_synced_at)->toBeNull();
});

test('cpe vendor and product are stored lowercased', function () {
    $vendor = Vendor::factory()->create(['name' => 'acme', 'slug' => 'acme']);
    $product = Product::factory()->for($vendor)->create(['name' => 'widget', 'slug' => 'widget']);
    CpeMap::factory()->forPair('acme', 'widget', $product)->create();

    fakeNvd([cveEntry('CVE-2026-1219', [
        cpeMatch('cpe:2.3:a:ACME:Widget:*:*:*:*:*:*:*:*'),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    expect(VulnerabilityRange::sole()->product_id)->toBe($product->id);
});

test('the source is found by driver not by slug', function () {
    $this->source->update(['slug' => 'nvd-mirror', 'name' => 'NVD Mirror']);

    fakeNvd([cveEntry('CVE-2026-3001')]);

    $this->artisan('nvd:sync')->assertSuccessful();

    expect(Vulnerability::sole()->source_id)->toBe($this->source->id);
});

test('it fails when no row declares the nvd driver', function () {
    Http::fake();

    $this->source->update(['driver' => null]);

    $this->artisan('nvd:sync')->assertFailed();

    Http::assertNothingSent();
});

test('the page size comes from the source row', function () {
    $this->source->update(['page_size' => 500]);

    fakeNvd([cveEntry('CVE-2026-3002')]);

    $this->artisan('nvd:sync')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'resultsPerPage=500'));
});

test('it fails when the source row is missing its sync settings', function (string $column) {
    Http::fake();

    $this->source->update([$column => null]);

    $this->artisan('nvd:sync')->assertFailed();

    Http::assertNothingSent();
})->with(['url', 'page_size', 'request_delay_ms', 'unauthenticated_request_delay_ms']);
