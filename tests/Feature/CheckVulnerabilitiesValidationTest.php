<?php

use App\Models\ScanHost;

function postRaw(array $payload)
{
    $host = ScanHost::factory()->create();

    return test()->withToken($host->createToken('scanner')->plainTextToken)
        ->postJson(route('api.vulns.check'), $payload);
}

test('components are required', function () {
    postRaw([])->assertUnprocessable()->assertJsonValidationErrors('components');
});

test('an empty component list is rejected', function () {
    postRaw(['components' => []])->assertUnprocessable()->assertJsonValidationErrors('components');
});

/** Abuse/DoS cap: the batch endpoint must not accept unbounded payloads. */
test('more than 2000 components are rejected', function () {
    $components = array_fill(0, 2001, ['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']);

    postRaw(['components' => $components])->assertUnprocessable()->assertJsonValidationErrors('components');
});

test('exactly 2000 components are accepted', function () {
    $components = array_fill(0, 2000, ['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']);

    postRaw(['components' => $components])->assertOk();
});

test('vendor, product and version are each required', function (string $missing) {
    $component = ['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0'];
    unset($component[$missing]);

    postRaw(['components' => [$component]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("components.0.{$missing}");
})->with(['vendor', 'product', 'version']);

test('local_id is optional', function () {
    postRaw(['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]])
        ->assertOk();
});

test('an over-long local_id is rejected', function () {
    postRaw(['components' => [[
        'vendor' => 'acme',
        'product' => 'widget',
        'version' => '1.0.0',
        'local_id' => str_repeat('a', 192),
    ]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('components.0.local_id');
});

test('tenant_id is optional so standalone installs are supported', function () {
    postRaw(['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]])
        ->assertOk();
});

/**
 * tenant_id must not be constrained to the hosting panel's pXXXX shape:
 * personal and standalone installs choose their own identifier.
 */
test('a non panel tenant_id is accepted', function (string $tenantId) {
    postRaw([
        'tenant_id' => $tenantId,
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])
        ->assertOk()
        ->assertJsonPath('tenant_id', $tenantId);
})->with(['p1234', 'my-home-server', 'acme_customer_42', 'standalone']);

test('an over-long tenant_id is rejected', function () {
    postRaw([
        'tenant_id' => str_repeat('a', 65),
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tenant_id');
});

test('min_cvss_score is optional', function () {
    postRaw(['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]])
        ->assertOk();
});

test('min_cvss_score must be within the 0-10 cvss range', function (float $score) {
    postRaw([
        'min_cvss_score' => $score,
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('min_cvss_score');
})->with([-1, 10.1]);

test('min_cvss_score accepts the full 0-10 range', function (float $score) {
    postRaw([
        'min_cvss_score' => $score,
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])->assertOk();
})->with([0, 10]);

test('severity is optional', function () {
    postRaw(['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]])
        ->assertOk();
});

test('severity accepts every known level case-insensitively', function (string $severity) {
    postRaw([
        'severity' => [$severity],
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])->assertOk();
})->with(['low', 'Medium', 'HIGH', 'critical']);

test('an unknown severity level is rejected', function () {
    postRaw([
        'severity' => ['apocalyptic'],
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('severity.0');
});

test('confidence is optional', function () {
    postRaw(['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]])
        ->assertOk();
});

test('confidence accepts bounded and all case-insensitively', function (string $confidence) {
    postRaw([
        'confidence' => $confidence,
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])->assertOk();
})->with(['bounded', 'BOUNDED', 'all', 'All']);

test('an unknown confidence value is rejected', function () {
    postRaw([
        'confidence' => 'unbounded',
        'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confidence');
});
