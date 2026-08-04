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

/**
 * Like cveEntry(), but lets the caller supply `configurations` directly
 * instead of the single-node-single-configuration shape cveEntry() builds.
 *
 * @param  array<int, array<string, mixed>>  $configurations
 * @return array<string, mixed>
 */
function cveEntryWithConfigurations(string $cveId, array $configurations): array
{
    $entry = cveEntry($cveId);
    $entry['cve']['configurations'] = $configurations;

    return $entry;
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

/**
 * NVD's "vulnerable only if A and B are both present" shape: a configuration
 * with operator AND and more than one node. Each node becomes its own clause.
 */
test('an AND configuration assigns each node its own clause index', function () {
    fakeNvd([cveEntryWithConfigurations('CVE-2026-5001', [
        [
            'operator' => 'AND',
            'nodes' => [
                ['cpeMatch' => [cpeMatch('cpe:2.3:a:westerndeal:advanced_dewplayer:1.2:*:*:*:*:*:*:*')]],
                ['cpeMatch' => [cpeMatch('cpe:2.3:a:wordpress:wordpress:-:*:*:*:*:*:*:*')]],
            ],
        ],
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $ranges = VulnerabilityRange::orderBy('id')->get();

    expect($ranges)->toHaveCount(2)
        ->and($ranges[0]->group_index)->toBe(0)
        ->and($ranges[0]->clause_index)->toBe(0)
        ->and($ranges[0]->raw_cpe)->toContain('advanced_dewplayer')
        ->and($ranges[1]->group_index)->toBe(0)
        ->and($ranges[1]->clause_index)->toBe(1)
        ->and($ranges[1]->raw_cpe)->toContain('wordpress');
});

/** A single-node config, or a config with no explicit AND operator, collapses to one clause — today's behavior, unchanged. */
test('an OR configuration keeps every node in the same clause', function () {
    fakeNvd([cveEntryWithConfigurations('CVE-2026-5002', [
        [
            'operator' => 'OR',
            'nodes' => [
                ['cpeMatch' => [cpeMatch('cpe:2.3:a:acme:widget:1.0:*:*:*:*:*:*:*')]],
                ['cpeMatch' => [cpeMatch('cpe:2.3:a:acme:widget:2.0:*:*:*:*:*:*:*')]],
            ],
        ],
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $ranges = VulnerabilityRange::get();

    expect($ranges)->toHaveCount(2)
        ->and($ranges->pluck('group_index')->unique()->all())->toBe([0])
        ->and($ranges->pluck('clause_index')->unique()->all())->toBe([0]);
});

/** Multiple independent configurations are implicitly OR'd at the top level and get their own group. */
test('multiple configurations increment the group index', function () {
    fakeNvd([cveEntryWithConfigurations('CVE-2026-5003', [
        ['nodes' => [['cpeMatch' => [cpeMatch('cpe:2.3:a:acme:widget:1.0:*:*:*:*:*:*:*')]]]],
        ['nodes' => [['cpeMatch' => [cpeMatch('cpe:2.3:a:acme:widget:2.0:*:*:*:*:*:*:*')]]]],
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    expect(VulnerabilityRange::pluck('group_index')->sort()->values()->all())->toBe([0, 1]);
});

/**
 * The reported bug: NVD's CPE match data can be incomplete at any point in a
 * CVE's life (not just shortly after publish), so a configuration can arrive
 * with an end bound but no start bound yet even though the real advisory has
 * one. Trusting that as "no lower bound" made e.g. very old Joomla installs
 * look affected by CVEs that only started at a much later version. Such a
 * range is held back the moment we first see this shape.
 */
test('an end bound with no start bound is held back as unmatched the moment we first see it', function () {
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla', 'slug' => 'joomla']);
    CpeMap::factory()->forPair('joomla', 'joomla', $product)->create();

    fakeNvd([cveEntry('CVE-2026-9001', [
        cpeMatch('cpe:2.3:a:joomla:joomla:*:*:*:*:*:*:*:*', ['versionEndExcluding' => '6.1.1']),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->product_id)->toBe($product->id)
        ->and($range->match_confidence)->toBe('unmatched')
        ->and($range->version_start)->toBeNull()
        ->and($range->version_end)->toBe('6.1.1')
        ->and($range->version_start_missing_since)->not->toBeNull();
});

/**
 * The self-healing story: the same null-start/end-set shape re-confirmed by
 * our own resyncs across the grace period graduates to trusted — no reliance
 * on NVD's published/lastModified metadata, just our own observation history
 * surviving across build() calls via upsert-by-identity.
 */
test('a null-start shape observed stably across the grace period graduates to trusted', function () {
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla', 'slug' => 'joomla']);
    CpeMap::factory()->forPair('joomla', 'joomla', $product)->create();

    $entry = fn () => [cveEntry('CVE-2026-9002', [
        cpeMatch('cpe:2.3:a:joomla:joomla:*:*:*:*:*:*:*:*', ['versionEndExcluding' => '6.1.1']),
    ])];

    $this->travelTo(now()->subDays(20));
    fakeNvd($entry());
    $this->artisan('nvd:sync --full')->assertSuccessful();

    expect(VulnerabilityRange::sole()->match_confidence)->toBe('unmatched');

    $this->travelBack();
    fakeNvd($entry());
    $this->artisan('nvd:sync --full')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->match_confidence)->toBe('exact')
        ->and($range->version_start)->toBeNull()
        ->and($range->version_end)->toBe('6.1.1');
});

/**
 * Self-healing, the other direction: once NVD fills in the real start bound
 * on a later resync, the range is trusted immediately — it never has to wait
 * out the grace period. Uses fakeSequence() rather than two fakeNvd() calls:
 * Http::fake() registered a second time does not replace the first stub for
 * an already-matched URL pattern within the same test, so a genuinely
 * different second response needs the sequence form.
 */
test('a start bound filled in on a later resync is trusted immediately, no grace period needed', function () {
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla', 'slug' => 'joomla']);
    CpeMap::factory()->forPair('joomla', 'joomla', $product)->create();

    Http::fakeSequence()
        ->push([
            'totalResults' => 1,
            'vulnerabilities' => [cveEntry('CVE-2026-9004', [
                cpeMatch('cpe:2.3:a:joomla:joomla:*:*:*:*:*:*:*:*', ['versionEndExcluding' => '6.1.1']),
            ])],
        ])
        ->push([
            'totalResults' => 1,
            'vulnerabilities' => [cveEntry('CVE-2026-9004', [
                cpeMatch('cpe:2.3:a:joomla:joomla:*:*:*:*:*:*:*:*', [
                    'versionStartIncluding' => '3.2.1',
                    'versionEndExcluding' => '6.1.1',
                ]),
            ])],
        ]);

    $this->artisan('nvd:sync --full')->assertSuccessful();

    expect(VulnerabilityRange::sole()->match_confidence)->toBe('unmatched');

    $this->artisan('nvd:sync --full')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->match_confidence)->toBe('exact')
        ->and($range->version_start)->toBe('3.2.1')
        ->and($range->version_start_missing_since)->toBeNull();
});

/** Control case mirroring CVE-2026-40383 from the incident report: a CVE with both bounds present from the start is unaffected by the stability guard. */
test('a cve with both bounds present is unaffected by the stability guard', function () {
    $vendor = Vendor::factory()->create(['name' => 'joomla', 'slug' => 'joomla']);
    $product = Product::factory()->for($vendor)->create(['name' => 'Joomla', 'slug' => 'joomla']);
    CpeMap::factory()->forPair('joomla', 'joomla', $product)->create();

    fakeNvd([cveEntry('CVE-2026-9003', [
        cpeMatch('cpe:2.3:a:joomla:joomla:*:*:*:*:*:*:*:*', [
            'versionStartIncluding' => '3.2.1',
            'versionEndExcluding' => '5.4.6',
        ]),
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->match_confidence)->toBe('exact')
        ->and($range->version_start)->toBe('3.2.1')
        ->and($range->version_end)->toBe('5.4.6');
});

test('a negated node is skipped without affecting its siblings', function () {
    fakeNvd([cveEntryWithConfigurations('CVE-2026-5004', [
        [
            'operator' => 'AND',
            'nodes' => [
                ['negate' => true, 'cpeMatch' => [cpeMatch('cpe:2.3:a:acme:excluded:*:*:*:*:*:*:*:*')]],
                ['cpeMatch' => [cpeMatch('cpe:2.3:a:acme:widget:1.0:*:*:*:*:*:*:*')]],
            ],
        ],
    ])]);

    $this->artisan('nvd:sync')->assertSuccessful();

    $range = VulnerabilityRange::sole();

    expect($range->raw_cpe)->toContain('widget')
        ->and($range->clause_index)->toBe(0);
});
