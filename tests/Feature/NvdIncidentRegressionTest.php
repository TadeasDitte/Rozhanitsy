<?php

use App\Models\Product;
use App\Models\ScanHost;
use App\Models\Source;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use Database\Seeders\CpeMapSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\VendorSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    Source::factory()->nvd()->create();

    $this->seed([VendorSeeder::class, ProductSeeder::class, CpeMapSeeder::class]);

    Http::fake([
        'services.nvd.nist.gov/*' => Http::response(
            json_decode((string) file_get_contents(base_path('tests/Fixtures/nvd/incident-cves.json')), true),
        ),
    ]);
});

function checkInstall(string $vendor, string $product, string $version): array
{
    $host = ScanHost::factory()->create();

    $response = test()->withToken($host->createToken('scanner')->plainTextToken)
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => $vendor, 'product' => $product, 'version' => $version]],
        ]);

    $response->assertOk();

    return array_column($response->json('vulnerable'), 'cve_id');
}

test('every maintenance branch of a repeated cpe survives a resync', function () {
    $this->artisan('nvd:sync')->assertSuccessful();
    $this->artisan('nvd:sync')->assertSuccessful();

    $vulnerability = Vulnerability::where('cve_id', 'CVE-2022-21661')->sole();

    $wordpressRanges = VulnerabilityRange::where('vulnerability_id', $vulnerability->id)
        ->where('raw_cpe', 'like', '%:wordpress:wordpress:%')
        ->get();

    expect($wordpressRanges)->toHaveCount(22)
        ->and($wordpressRanges->pluck('version_start')->filter()->unique())->toHaveCount(22);
});

test('an install on an old branch matches a cve patched across many branches', function () {
    $this->artisan('nvd:sync')->assertSuccessful();
    $this->artisan('nvd:sync')->assertSuccessful();

    expect(checkInstall('wordpress', 'wordpress', '4.9.3'))->toContain('CVE-2022-21661');
});

test('a paid edition cve never reaches the free plugin', function () {
    $this->artisan('nvd:sync')->assertSuccessful();

    $elementor = Product::whereRelation('vendor', 'slug', 'elementor')->sole();

    $proRanges = VulnerabilityRange::whereRelation('vulnerability', 'cve_id', 'CVE-2024-35656')->get();

    expect($proRanges)->not->toBeEmpty()
        ->and($proRanges->pluck('product_id')->unique()->all())->toBe([null])
        ->and($proRanges->pluck('match_confidence')->unique()->all())->toBe(['unmatched'])
        ->and(checkInstall('elementor', 'elementor', '3.18.3'))->not->toContain('CVE-2024-35656');

    expect(VulnerabilityRange::where('product_id', $elementor->id)
        ->whereRelation('vulnerability', 'cve_id', 'CVE-2024-35656')
        ->exists())->toBeFalse();
});

test('a wordpress mu cve never reaches wordpress core', function () {
    $this->artisan('nvd:sync')->assertSuccessful();

    expect(checkInstall('wordpress', 'wordpress', '2.7'))->not->toContain('CVE-2009-1030');
});

test('a cve filed under an aliased cpe name is matched once it settles', function () {
    $this->artisan('nvd:sync')->assertSuccessful();

    $elementor = Product::whereRelation('vendor', 'slug', 'elementor')->sole();
    $range = VulnerabilityRange::whereRelation('vulnerability', 'cve_id', 'CVE-2024-24934')->sole();

    expect($range->product_id)->toBe($elementor->id)
        ->and($range->match_confidence)->toBe('unmatched');

    $this->travel(15)->days();
    $this->artisan('nvd:sync')->assertSuccessful();

    expect($range->fresh()->match_confidence)->toBe('exact')
        ->and(checkInstall('elementor', 'elementor', '3.18.3'))->toContain('CVE-2024-24934');
});

test('a cve carries the score nvd itself assigned it', function () {
    $this->artisan('nvd:sync')->assertSuccessful();

    expect(Vulnerability::where('cve_id', 'CVE-2024-31211')->sole())
        ->cvss_score->toBe(9.8)
        ->cvss_severity->toBe('CRITICAL');
});
