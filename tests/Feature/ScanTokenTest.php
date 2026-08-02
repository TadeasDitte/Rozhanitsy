<?php

use App\Models\ScanHost;
use App\Models\User;

function verifiedUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

test('guests cannot reach the tokens page', function () {
    $this->get(route('tokens.index'))->assertRedirect(route('login'));
});

test('a user sees only their own scan hosts', function () {
    $user = verifiedUser();
    $mine = ScanHost::factory()->create(['user_id' => $user->id, 'hostname' => 'mine.example.com']);
    ScanHost::factory()->create(['user_id' => verifiedUser()->id, 'hostname' => 'theirs.example.com']);
    ScanHost::factory()->create(['hostname' => 'cli-created.example.com']);

    $this->actingAs($user)->get(route('tokens.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tokens')
            ->has('hosts', 1)
            ->where('hosts.0.id', $mine->id));
});

test('creating a scan host issues a working token exactly once', function () {
    $user = verifiedUser();

    $response = $this->actingAs($user)
        ->post(route('tokens.store'), ['hostname' => 'scanner-01.example.com']);

    $response->assertRedirect()->assertSessionHas('scanToken');

    $issued = session('scanToken');
    $host = ScanHost::sole();

    expect($host->user_id)->toBe($user->id)
        ->and($host->hostname)->toBe('scanner-01.example.com')
        ->and($issued['hostname'])->toBe('scanner-01.example.com');

    $this->withToken($issued['token'])
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertOk();
});

test('hostnames must be unique and well formed', function (string $hostname) {
    ScanHost::factory()->create(['hostname' => 'taken.example.com']);

    $this->actingAs(verifiedUser())
        ->post(route('tokens.store'), ['hostname' => $hostname])
        ->assertSessionHasErrors('hostname');
})->with(['taken.example.com', 'has spaces', '', 'bad/slash']);

test('revoking deactivates the host and kills the token', function () {
    $user = verifiedUser();
    $this->actingAs($user)->post(route('tokens.store'), ['hostname' => 'scanner-01.example.com']);
    $token = session('scanToken')['token'];
    $host = ScanHost::sole();

    $this->actingAs($user)->delete(route('tokens.destroy', $host))->assertRedirect();

    expect($host->fresh()->is_active)->toBeFalse()
        ->and($host->tokens()->count())->toBe(0);

    $this->withToken($token)
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertUnauthorized();
});

test('regenerating replaces the token and reactivates the host', function () {
    $user = verifiedUser();
    $this->actingAs($user)->post(route('tokens.store'), ['hostname' => 'scanner-01.example.com']);
    $old = session('scanToken')['token'];
    $host = ScanHost::sole();

    $this->actingAs($user)->delete(route('tokens.destroy', $host));
    $this->actingAs($user)->post(route('tokens.regenerate', $host))->assertRedirect();

    $new = session('scanToken')['token'];
    $payload = ['components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']]];

    expect($host->fresh()->is_active)->toBeTrue()
        ->and($new)->not->toBe($old);

    $this->withToken($old)->postJson(route('api.vulns.check'), $payload)->assertUnauthorized();
    $this->withToken($new)->postJson(route('api.vulns.check'), $payload)->assertOk();
});

test('a user cannot touch another users scan host', function () {
    $victim = ScanHost::factory()->create(['user_id' => verifiedUser()->id]);
    $attacker = verifiedUser();

    $this->actingAs($attacker)->delete(route('tokens.destroy', $victim))->assertForbidden();
    $this->actingAs($attacker)->post(route('tokens.regenerate', $victim))->assertForbidden();
});

test('a user cannot revoke a cli created host they do not own', function () {
    $host = ScanHost::factory()->create(['user_id' => null]);

    $this->actingAs(verifiedUser())->delete(route('tokens.destroy', $host))->assertForbidden();
});
