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
        ->assertJsonPath('vulnerable.0.local_id', 'wp-content/plugins/widget');

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

test('unresolvable components are returned as unmatched with their local_id', function () {
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
