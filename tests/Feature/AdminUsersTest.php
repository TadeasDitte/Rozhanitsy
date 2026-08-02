<?php

use App\Models\ScanHost;
use App\Models\User;

function admin(): User
{
    return User::factory()->admin()->create(['email_verified_at' => now()]);
}

function member(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

test('guests are redirected away from the admin dashboard', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('a non admin is forbidden', function () {
    $this->actingAs(member())->get(route('admin.users.index'))->assertForbidden();
});

test('an admin sees every user and their hosts', function () {
    $admin = admin();
    $other = member();
    ScanHost::factory()->create(['user_id' => $other->id, 'hostname' => 'theirs.example.com']);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Users')
            ->has('users', 2)
            ->where('adminCount', 1)
            ->has('users.0.hosts'));
});

test('an admin can revoke any hosts token', function () {
    $owner = member();
    $host = ScanHost::factory()->create(['user_id' => $owner->id]);
    $token = $host->createToken('scanner')->plainTextToken;

    $this->actingAs(admin())->delete(route('admin.scan-hosts.revoke', $host))->assertRedirect();

    expect($host->fresh()->is_active)->toBeFalse()
        ->and($host->tokens()->count())->toBe(0);

    $this->withToken($token)
        ->postJson(route('api.vulns.check'), [
            'components' => [['vendor' => 'acme', 'product' => 'widget', 'version' => '1.0.0']],
        ])
        ->assertUnauthorized();
});

test('a non admin cannot revoke tokens', function () {
    $host = ScanHost::factory()->create(['user_id' => member()->id]);

    $this->actingAs(member())->delete(route('admin.scan-hosts.revoke', $host))->assertForbidden();

    expect($host->fresh()->is_active)->toBeTrue();
});

test('the first registered user becomes an admin and later ones do not', function () {
    $this->post(route('register.store'), [
        'name' => 'First',
        'email' => 'first@example.com',
        'password' => 'Str0ng-Password!42',
        'password_confirmation' => 'Str0ng-Password!42',
    ]);

    $this->post('/logout');

    $this->post(route('register.store'), [
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'Str0ng-Password!42',
        'password_confirmation' => 'Str0ng-Password!42',
    ]);

    expect(User::where('email', 'first@example.com')->sole()->is_admin)->toBeTrue()
        ->and(User::where('email', 'second@example.com')->sole()->is_admin)->toBeFalse();
});
