<?php

use App\Models\CpeMap;
use App\Models\Product;
use App\Models\ScanHost;
use App\Models\ScanLog;
use App\Models\UnmatchedLookup;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

function mapCpe(string $cpeVendor = 'acme', string $cpeProduct = 'widget'): Product
{
    $product = Product::factory()->plugin()->create();

    CpeMap::factory()->forPair($cpeVendor, $cpeProduct, $product)->create();

    return $product;
}

/**
 * @param  array<string, mixed>  $vulnerability
 */
function affectedRange(Product $product, ?string $start, ?string $end, array $vulnerability = []): VulnerabilityRange
{
    return VulnerabilityRange::factory()
        ->for(Vulnerability::factory()->state($vulnerability))
        ->affecting($start, $end)
        ->create(['product_id' => $product->id]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postCheck(array $payload, ?ScanHost $host = null): TestResponse
{
    $host ??= ScanHost::factory()->create();

    return test()->withToken($host->createToken('scanner')->plainTextToken)
        ->postJson(route('api.vulns.check'), $payload);
}

test('the endpoint rejects unauthenticated requests', function () {
    $this->postJson(route('api.vulns.check'), [
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])->assertUnauthorized();
});

/**
 * The sanctum guard is bound to the scan_hosts provider, so a token belonging
 * to any other model must not authenticate this endpoint even if one is minted.
 */
test('a token owned by a user cannot authenticate the scanner endpoint', function () {
    $user = User::factory()->create();
    $plainTextToken = Str::random(40);

    PersonalAccessToken::forceCreate([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->id,
        'name' => 'web',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
    ]);

    $this->withToken($plainTextToken)
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertUnauthorized();
});

test('a logged in web session cannot authenticate the scanner endpoint', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertUnauthorized();
});

test('an affected component is reported as vulnerable', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0001', 'cvss_score' => 9.8]);

    $response = postCheck([
        'tenant_id' => 'p1234',
        'components' => [
            ['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0', 'local_id' => 'wp-content/plugins/widget'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('tenant_id', 'p1234')
        ->assertJsonPath('unmatched', [])
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2026-0001')
        ->assertJsonPath('vulnerable.0.cvss_score', 9.8)
        ->assertJsonPath('vulnerable.0.installed_version', '1.5.0')
        ->assertJsonPath('vulnerable.0.local_id', 'wp-content/plugins/widget')
        ->assertJsonPath('vulnerable.0.confidence', 'bounded');

    expect($response->json('checked_at'))->not->toBeNull();
});

test('a patched component outside the range is not reported', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0');

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '2.0.0']],
    ])
        ->assertOk()
        ->assertJsonPath('vulnerable', [])
        ->assertJsonPath('unmatched', []);
});

test('tenant_id is echoed back as null for standalone installs', function () {
    mapCpe();

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])
        ->assertOk()
        ->assertJsonPath('tenant_id', null);
});

test('unresolvable components are returned as unmatched with their version and local_id', function () {
    postCheck([
        'components' => [
            ['vendor' => 'unknown', 'product' => 'mystery', 'version' => '3.1.0', 'local_id' => 'plugins/mystery'],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('vulnerable', [])
        ->assertJsonCount(1, 'unmatched')
        ->assertJsonPath('unmatched.0.vendor', 'unknown')
        ->assertJsonPath('unmatched.0.product', 'mystery')
        ->assertJsonPath('unmatched.0.version', '3.1.0')
        ->assertJsonPath('unmatched.0.local_id', 'plugins/mystery');
});

test('unmatched lookups are recorded once per pair', function () {
    postCheck([
        'components' => [
            ['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0'],
            ['vendor' => 'unknown', 'product' => 'mystery', 'version' => '2.0.0'],
        ],
    ])->assertOk();

    $lookup = UnmatchedLookup::sole();

    expect($lookup->cpe_vendor)->toBe('unknown')
        ->and($lookup->cpe_product)->toBe('mystery')
        ->and($lookup->hit_count)->toBe(1);
});

/**
 * The batched raw upsert must increment on conflict. A plain Eloquent create()
 * would throw on the unique (cpe_vendor, cpe_product) constraint instead.
 */
test('a repeat unmatched pair increments hit_count instead of failing', function () {
    $payload = [
        'components' => [['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0']],
    ];

    postCheck($payload)->assertOk();
    postCheck($payload)->assertOk();
    postCheck($payload)->assertOk();

    $lookup = UnmatchedLookup::sole();

    expect($lookup->hit_count)->toBe(3)
        ->and(UnmatchedLookup::count())->toBe(1);
});

test('a repeat unmatched pair advances last_seen_at but keeps first_seen_at', function () {
    $payload = [
        'components' => [['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0']],
    ];

    $this->travelTo(now()->subDay());
    postCheck($payload)->assertOk();
    $firstSeen = UnmatchedLookup::sole()->first_seen_at;

    $this->travelBack();
    postCheck($payload)->assertOk();

    $lookup = UnmatchedLookup::sole();

    expect($lookup->first_seen_at->timestamp)->toBe($firstSeen->timestamp)
        ->and($lookup->last_seen_at->timestamp)->toBeGreaterThan($firstSeen->timestamp);
});

test('a scan log row is written for every request', function () {
    $host = ScanHost::factory()->create();
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0');

    postCheck([
        'tenant_id' => 'p9999',
        'components' => [
            ['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0'],
            ['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0'],
        ],
    ], $host)->assertOk();

    $log = ScanLog::sole();

    expect($log->scan_host_id)->toBe($host->id)
        ->and($log->tenant_id)->toBe('p9999')
        ->and($log->component_count)->toBe(2)
        ->and($log->vulnerable_count)->toBe(1)
        ->and($log->unmatched_count)->toBe(1)
        ->and($log->scanned_at)->not->toBeNull();
});

test('a successful check stamps the host as last seen', function () {
    $host = ScanHost::factory()->create();

    expect($host->last_seen_at)->toBeNull();

    postCheck([
        'components' => [['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0']],
    ], $host)->assertOk();

    expect($host->fresh()->last_seen_at)->not->toBeNull();
});

/**
 * The stamp lives on the host rather than the token so that regenerating a
 * token — which deletes the row Sanctum writes `last_used_at` to — does not
 * reset the host back to "Never".
 */
test('the last seen stamp survives a token regeneration', function () {
    $host = ScanHost::factory()->create();

    postCheck([
        'components' => [['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0']],
    ], $host)->assertOk();

    $seenAt = $host->fresh()->last_seen_at;

    $host->tokens()->delete();

    expect($host->fresh()->last_seen_at?->timestamp)->toBe($seenAt?->timestamp);
});

test('an unauthenticated request does not stamp any host', function () {
    $host = ScanHost::factory()->create();

    $this->postJson(route('api.vulns.check'), [
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])->assertUnauthorized();

    expect($host->fresh()->last_seen_at)->toBeNull();
});

test('a scan log records a null tenant for standalone installs', function () {
    postCheck([
        'components' => [['vendor' => 'unknown', 'product' => 'mystery', 'version' => '1.0.0']],
    ])->assertOk();

    expect(ScanLog::sole()->tenant_id)->toBeNull();
});

test('unmatched ranges are never used for matching', function () {
    $product = mapCpe();

    VulnerabilityRange::factory()
        ->affecting('1.0.0', '2.0.0')
        ->create(['product_id' => $product->id, 'match_confidence' => 'unmatched']);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonPath('vulnerable', []);
});

/**
 * The reported bug's exact shape: a CVE with two disjoint version bands as
 * plain OR alternatives in the same clause, where one band's lower bound
 * couldn't be trusted (see VulnerabilityRangeBuilder's recency guard) and
 * was stored as `unmatched` confidence. It must not contribute a match just
 * because a sibling row in the same clause is valid.
 */
test('an unmatched-confidence range does not contribute a match even when a sibling range in the same clause is valid', function () {
    $product = mapCpe();
    $vulnerability = Vulnerability::factory()->create(['cve_id' => 'CVE-2026-9010']);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 0)
        ->affecting(null, '5.4.5', true, true)
        ->create(['product_id' => $product->id, 'match_confidence' => 'unmatched']);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 0)
        ->affecting('6.0.0', '6.1.0', true, true)
        ->create(['product_id' => $product->id, 'match_confidence' => 'exact']);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '3.6.4']],
    ])->assertOk()->assertJsonPath('vulnerable', []);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '6.0.5']],
    ])->assertOk()->assertJsonCount(1, 'vulnerable');
});

/**
 * The reported bug: joomla/database (the standalone Framework library) and
 * Joomla CMS share a cpe_vendor but are versioned independently and must
 * resolve to different products, so a CVE against one never leaks onto the
 * other just because both cpe_map rows start with "joomla".
 */
test('cpe_map rows sharing a vendor but different products keep their vulnerability ranges separate', function () {
    $cms = Product::factory()->create(['name' => 'Joomla']);
    $framework = Product::factory()->create(['name' => 'Joomla Framework Database']);
    CpeMap::factory()->forPair('joomla', 'joomla', $cms)->create();
    CpeMap::factory()->forPair('joomla', 'database', $framework)->create();

    affectedRange($framework, '1.0.0', '2.2.0', ['cve_id' => 'CVE-2025-25226']);

    postCheck([
        'components' => [['vendor' => 'joomla', 'product' => 'joomla', 'version' => '1.5.0']],
    ])->assertOk()->assertJsonPath('vulnerable', []);

    postCheck([
        'components' => [['vendor' => 'joomla', 'product' => 'database', 'version' => '1.5.0']],
    ])->assertOk()->assertJsonCount(1, 'vulnerable');
});

test('a fuzzy range is still used for matching', function () {
    $product = mapCpe();

    VulnerabilityRange::factory()
        ->affecting('1.0.0', '2.0.0')
        ->create(['product_id' => $product->id, 'match_confidence' => 'fuzzy']);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable');
});

test('one CVE is reported once per component even with several matching ranges', function () {
    $product = mapCpe();
    $vulnerability = Vulnerability::factory()->create(['cve_id' => 'CVE-2026-4242']);

    VulnerabilityRange::factory()->for($vulnerability)->affecting('1.0.0', '2.0.0')->create(['product_id' => $product->id]);
    VulnerabilityRange::factory()->for($vulnerability)->affecting('1.4.0', '1.9.0')->create(['product_id' => $product->id]);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2026-4242');
});

/**
 * The core performance guarantee: lookup cost must not scale with the number
 * of components in the payload.
 */
test('the component matching does not issue a query per component', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0');

    $host = ScanHost::factory()->create();
    $token = $host->createToken('scanner')->plainTextToken;

    $components = [];
    for ($i = 0; $i < 200; $i++) {
        $components[] = ['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0', 'local_id' => "plugin-{$i}"];
    }

    DB::enableQueryLog();

    $this->withToken($token)
        ->postJson(route('api.vulns.check'), ['components' => $components])
        ->assertOk()
        ->assertJsonCount(200, 'vulnerable');

    /** Ignore the guard's own token/host resolution; only the matching reads matter here. */
    $dataReads = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_starts_with(strtolower($query), 'select'))
        ->filter(fn (string $query): bool => str_contains($query, 'cpe_map') || str_contains($query, 'vulnerability_ranges'));

    DB::disableQueryLog();

    /** One cpe_map lookup, one joined vulnerability_ranges pull. */
    expect($dataReads)->toHaveCount(2);
});

test('a component whose product has no ranges is neither vulnerable nor unmatched', function () {
    mapCpe();

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonPath('vulnerable', [])
        ->assertJsonPath('unmatched', []);
});

test('vendor and product match case insensitively', function () {
    $product = mapCpe('automattic', 'akismet');
    affectedRange($product, '1.0.0', '2.0.0');

    postCheck([
        'components' => [['vendor' => 'Automattic', 'product' => 'Akismet', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('unmatched', []);
});

test('the unmatched response echoes the caller original casing', function () {
    postCheck([
        'components' => [['vendor' => 'UnknownCo', 'product' => 'MysteryPlugin', 'version' => '1.0.0']],
    ])
        ->assertOk()
        ->assertJsonPath('unmatched.0.vendor', 'UnknownCo')
        ->assertJsonPath('unmatched.0.product', 'MysteryPlugin');
});

/** Case variants must not create duplicate rows in the triage worklist. */
test('unmatched lookups are recorded lowercased and deduplicated across casings', function () {
    postCheck([
        'components' => [
            ['vendor' => 'UnknownCo', 'product' => 'Mystery', 'version' => '1.0.0'],
            ['vendor' => 'unknownco', 'product' => 'mystery', 'version' => '2.0.0'],
        ],
    ])->assertOk();

    $lookup = UnmatchedLookup::sole();

    expect($lookup->cpe_vendor)->toBe('unknownco')
        ->and($lookup->cpe_product)->toBe('mystery')
        ->and($lookup->hit_count)->toBe(1);
});

/**
 * The reported bug: a CVE that only applies when a plugin AND WordPress are
 * both present must not be reported against WordPress core alone just
 * because WordPress happens to be one of the AND clauses.
 */
test('an AND-group CVE requires every clause satisfied before it applies', function () {
    $plugin = Product::factory()->plugin()->create();
    $wordpress = Product::factory()->create();
    CpeMap::factory()->forPair('westerndeal', 'advanced_dewplayer', $plugin)->create();
    CpeMap::factory()->forPair('wordpress', 'wordpress', $wordpress)->create();

    $vulnerability = Vulnerability::factory()->create(['cve_id' => 'CVE-2013-7240']);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 0)
        ->affecting('1.2', '1.2', true, true)
        ->create(['product_id' => $plugin->id]);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 1)
        ->affecting(null, null)
        ->create(['product_id' => $wordpress->id]);

    postCheck([
        'components' => [
            ['vendor' => 'wordpress', 'product' => 'wordpress', 'version' => '6.9.0'],
        ],
    ])->assertOk()->assertJsonPath('vulnerable', []);

    postCheck([
        'components' => [
            ['vendor' => 'westerndeal', 'product' => 'advanced_dewplayer', 'version' => '1.2'],
        ],
    ])->assertOk()->assertJsonPath('vulnerable', []);
});

test('an AND-group CVE applies once every clause is satisfied, attributed to the bounded clause', function () {
    $plugin = Product::factory()->plugin()->create();
    $wordpress = Product::factory()->create();
    CpeMap::factory()->forPair('westerndeal', 'advanced_dewplayer', $plugin)->create();
    CpeMap::factory()->forPair('wordpress', 'wordpress', $wordpress)->create();

    $vulnerability = Vulnerability::factory()->create(['cve_id' => 'CVE-2013-7240']);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 0)
        ->affecting('1.2', '1.2', true, true)
        ->create(['product_id' => $plugin->id]);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 1)
        ->affecting(null, null)
        ->create(['product_id' => $wordpress->id]);

    postCheck([
        'components' => [
            ['vendor' => 'westerndeal', 'product' => 'advanced_dewplayer', 'version' => '1.2', 'local_id' => 'plugins/dewplayer'],
            ['vendor' => 'wordpress', 'product' => 'wordpress', 'version' => '6.9.0', 'local_id' => 'core'],
        ],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.product', 'advanced_dewplayer')
        ->assertJsonPath('vulnerable.0.local_id', 'plugins/dewplayer')
        ->assertJsonPath('vulnerable.0.confidence', 'bounded');
});

/** A clause whose product was never scanned at all can never be satisfied. */
test('an AND-group clause referencing an uncataloged product never completes', function () {
    $plugin = Product::factory()->plugin()->create();
    CpeMap::factory()->forPair('westerndeal', 'advanced_dewplayer', $plugin)->create();
    // No CpeMap/Product for wordpress/wordpress at all — the platform clause is unresolvable.

    $vulnerability = Vulnerability::factory()->create(['cve_id' => 'CVE-2013-7240']);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 0)
        ->affecting('1.2', '1.2', true, true)
        ->create(['product_id' => $plugin->id]);

    VulnerabilityRange::factory()->for($vulnerability)->inGroup(0, 1)
        ->affecting(null, null)
        ->unmatched()
        ->create(['raw_cpe' => 'cpe:2.3:a:wordpress:wordpress:-:*:*:*:*:*:*:*']);

    postCheck([
        'components' => [
            ['vendor' => 'westerndeal', 'product' => 'advanced_dewplayer', 'version' => '1.2'],
        ],
    ])->assertOk()->assertJsonPath('vulnerable', []);
});

test('a single-clause cve still matches exactly like before the AND-group change', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-7001']);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2026-7001');
});

/**
 * CVE-2012-3414's real NVD data: a bounded WordPress range (<=3.3.1, doesn't
 * match 6.9) sits alongside a redundant `-`-version WordPress entry in the
 * same flat OR node. CPE version `-` means "not applicable" — it's never
 * itself a claim that this product/version is vulnerable, so it must not be
 * used to attribute a finding when nothing else in the group matched.
 */
test('a bare "-" version cpe is never attributed on its own', function () {
    $product = mapCpe();

    VulnerabilityRange::factory()->for(Vulnerability::factory()->state(['cve_id' => 'CVE-2012-3414']))
        ->affecting(null, null)
        ->create(['product_id' => $product->id, 'raw_cpe' => 'cpe:2.3:a:acme:widget:-:*:*:*:*:*:*:*']);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '6.9.0']],
    ])->assertOk()->assertJsonPath('vulnerable', []);
});

/** A genuine `*` wildcard with no structured bounds is NVD's formal "any version" reading — still reported, unlike the `-` marker above. */
test('a bare wildcard version cpe with no bounds is still reported', function () {
    $product = mapCpe();

    VulnerabilityRange::factory()->for(Vulnerability::factory()->state(['cve_id' => 'CVE-2007-2627']))
        ->affecting(null, null)
        ->create(['product_id' => $product->id, 'raw_cpe' => 'cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*']);

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '6.9.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2007-2627')
        ->assertJsonPath('vulnerable.0.confidence', 'unbounded');
});

test('min_cvss_score excludes vulnerabilities below the threshold', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0001', 'cvss_score' => 4.5, 'cvss_severity' => 'MEDIUM']);
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0002', 'cvss_score' => 9.8, 'cvss_severity' => 'CRITICAL']);

    postCheck([
        'min_cvss_score' => 7,
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2026-0002');
});

test('min_cvss_score excludes vulnerabilities with no cvss score at all', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0003', 'cvss_score' => null, 'cvss_severity' => null]);

    postCheck([
        'min_cvss_score' => 0,
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonPath('vulnerable', []);
});

test('severity filters to only the requested levels', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0004', 'cvss_score' => 4.5, 'cvss_severity' => 'MEDIUM']);
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0005', 'cvss_score' => 9.8, 'cvss_severity' => 'CRITICAL']);

    postCheck([
        'severity' => ['critical'],
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2026-0005');
});

test('severity accepts multiple levels combined as an OR', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0006', 'cvss_score' => 2.0, 'cvss_severity' => 'LOW']);
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0007', 'cvss_score' => 7.5, 'cvss_severity' => 'HIGH']);
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0008', 'cvss_score' => 9.8, 'cvss_severity' => 'CRITICAL']);

    $response = postCheck([
        'severity' => ['HIGH', 'CRITICAL'],
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])->assertOk()->assertJsonCount(2, 'vulnerable');

    expect(collect($response->json('vulnerable'))->pluck('cve_id')->sort()->values()->all())
        ->toBe(['CVE-2026-0007', 'CVE-2026-0008']);
});

test('min_cvss_score and severity combine as an AND', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0009', 'cvss_score' => 7.1, 'cvss_severity' => 'HIGH']);
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0010', 'cvss_score' => 9.8, 'cvss_severity' => 'CRITICAL']);

    postCheck([
        'min_cvss_score' => 9.0,
        'severity' => ['high', 'critical'],
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.cve_id', 'CVE-2026-0010');
});

test('confidence=bounded excludes unbounded wildcard matches', function () {
    $product = mapCpe();

    VulnerabilityRange::factory()->for(Vulnerability::factory()->state(['cve_id' => 'CVE-2026-0011']))
        ->affecting(null, null)
        ->create(['product_id' => $product->id, 'raw_cpe' => 'cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*']);

    postCheck([
        'confidence' => 'bounded',
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonPath('vulnerable', []);
});

test('confidence=bounded keeps real version-range matches', function () {
    $product = mapCpe();
    affectedRange($product, '1.0.0', '2.0.0', ['cve_id' => 'CVE-2026-0012']);

    postCheck([
        'confidence' => 'bounded',
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'vulnerable')
        ->assertJsonPath('vulnerable.0.confidence', 'bounded');
});

test('confidence is case-insensitive and all is the default', function () {
    $product = mapCpe();

    VulnerabilityRange::factory()->for(Vulnerability::factory()->state(['cve_id' => 'CVE-2026-0013']))
        ->affecting(null, null)
        ->create(['product_id' => $product->id, 'raw_cpe' => 'cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*']);

    postCheck([
        'confidence' => 'BOUNDED',
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])->assertOk()->assertJsonPath('vulnerable', []);

    postCheck([
        'confidence' => 'all',
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])->assertOk()->assertJsonCount(1, 'vulnerable');

    postCheck([
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.5.0']],
    ])->assertOk()->assertJsonCount(1, 'vulnerable');
});

test('a deactivated scan host cannot authenticate', function () {
    $host = ScanHost::factory()->inactive()->create();

    $this->withToken($host->createToken('scanner')->plainTextToken)
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertUnauthorized();
});

test('an active scan host still authenticates', function () {
    $host = ScanHost::factory()->create();

    expect($host->is_active)->toBeTrue();

    $this->withToken($host->createToken('scanner')->plainTextToken)
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertOk();
});
