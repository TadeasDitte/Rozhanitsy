<?php

use App\Models\ScanHost;
use Illuminate\Support\Facades\Artisan;

function runCreateScanHost(string $hostname, bool $rotate = false): string
{
    /**
     * Touch the database first so the lazy test-suite migration runs outside
     * the captured call — it dispatches its own Artisan command, which would
     * otherwise replace the buffered output we are about to read.
     */
    ScanHost::query()->exists();

    Artisan::call('scan-host:create', array_filter([
        'hostname' => $hostname,
        '--rotate' => $rotate,
    ]));

    return Artisan::output();
}

test('it registers a host and prints a usable token', function () {
    $output = runCreateScanHost('scanner-01.example.com');

    $host = ScanHost::sole();

    expect($host->hostname)->toBe('scanner-01.example.com')
        ->and($host->is_active)->toBeTrue()
        ->and($host->tokens()->count())->toBe(1)
        ->and($output)->toContain('SCAN_TOKEN=');

    preg_match('/SCAN_TOKEN=(\S+)/', $output, $matches);

    /** The printed token must actually authenticate the scanner endpoint. */
    $this->withToken($matches[1])
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertOk();
});

test('it does not reissue a token for an existing host by default', function () {
    runCreateScanHost('scanner-01.example.com');
    $output = runCreateScanHost('scanner-01.example.com');

    expect(ScanHost::count())->toBe(1)
        ->and(ScanHost::sole()->tokens()->count())->toBe(1)
        ->and($output)->not->toContain('SCAN_TOKEN=');
});

test('rotate revokes the old token and issues a new one', function () {
    $first = runCreateScanHost('scanner-01.example.com');
    preg_match('/SCAN_TOKEN=(\S+)/', $first, $old);

    $second = runCreateScanHost('scanner-01.example.com', rotate: true);
    preg_match('/SCAN_TOKEN=(\S+)/', $second, $new);

    expect(ScanHost::sole()->tokens()->count())->toBe(1)
        ->and($new[1])->not->toBe($old[1]);

    $payload = ['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]];

    $this->withToken($old[1])->postJson(route('api.vulns.check'), $payload)->assertUnauthorized();
    $this->withToken($new[1])->postJson(route('api.vulns.check'), $payload)->assertOk();
});

test('it reactivates a deactivated host', function () {
    ScanHost::factory()->inactive()->create(['hostname' => 'scanner-01.example.com']);

    runCreateScanHost('scanner-01.example.com', rotate: true);

    expect(ScanHost::sole()->is_active)->toBeTrue();
});
