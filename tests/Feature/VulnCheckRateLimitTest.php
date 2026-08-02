<?php

use App\Models\ScanHost;

/**
 * The pilot ships a single shared scanner token, so throttling must be keyed by
 * tenant (falling back to IP), never by the token or the ScanHost.
 */
beforeEach(function () {
    $this->host = ScanHost::factory()->create();
    $this->token = $this->host->createToken('scanner')->plainTextToken;
});

function scan(string $token, ?string $tenantId = null, array $server = [])
{
    $payload = ['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]];

    if ($tenantId !== null) {
        $payload['tenant_id'] = $tenantId;
    }

    return test()->withToken($token)->withServerVariables($server)->postJson(route('api.vulns.check'), $payload);
}

test('a tenant is throttled after exhausting its own budget', function () {
    foreach (range(1, 30) as $ignored) {
        scan($this->token, 'p1000')->assertOk();
    }

    scan($this->token, 'p1000')->assertStatus(429);
});

/**
 * The defect this guards against: keying on the shared token would make one
 * busy tenant consume the budget for every other tenant on the same panel.
 */
test('exhausting one tenant does not throttle another on the same token', function () {
    foreach (range(1, 30) as $ignored) {
        scan($this->token, 'p1000')->assertOk();
    }

    scan($this->token, 'p1000')->assertStatus(429);
    scan($this->token, 'p2000')->assertOk();
});

test('a standalone install with no tenant_id falls back to its IP', function () {
    foreach (range(1, 30) as $ignored) {
        scan($this->token, null, ['REMOTE_ADDR' => '203.0.113.10'])->assertOk();
    }

    scan($this->token, null, ['REMOTE_ADDR' => '203.0.113.10'])->assertStatus(429);
    scan($this->token, null, ['REMOTE_ADDR' => '203.0.113.11'])->assertOk();
});

test('a standalone install is not throttled by an unrelated tenant', function () {
    foreach (range(1, 30) as $ignored) {
        scan($this->token, 'p1000')->assertOk();
    }

    scan($this->token, 'p1000')->assertStatus(429);
    scan($this->token, null, ['REMOTE_ADDR' => '203.0.113.55'])->assertOk();
});
