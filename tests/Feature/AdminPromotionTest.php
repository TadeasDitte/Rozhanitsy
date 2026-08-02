<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function adminUser(): User
{
    return User::factory()->admin()->create(['email_verified_at' => now()]);
}

function plainUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

test('an admin can promote another user', function () {
    $target = plainUser();

    $this->actingAs(adminUser())
        ->post(route('admin.users.promote', $target))
        ->assertRedirect();

    expect($target->fresh()->is_admin)->toBeTrue();
});

test('a promoted user can immediately reach the admin area', function () {
    $target = plainUser();
    $this->actingAs(adminUser())->post(route('admin.users.promote', $target));

    $this->actingAs($target->fresh())->get(route('admin.users.index'))->assertOk();
});

test('an admin can demote another admin', function () {
    $target = adminUser();

    $this->actingAs(adminUser())
        ->delete(route('admin.users.demote', $target))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($target->fresh()->is_admin)->toBeFalse();

    $this->actingAs($target->fresh())->get(route('admin.users.index'))->assertForbidden();
});

test('the last administrator cannot be demoted', function () {
    $only = adminUser();

    $this->actingAs($only)
        ->delete(route('admin.users.demote', $only))
        ->assertSessionHasErrors('is_admin');

    expect($only->fresh()->is_admin)->toBeTrue();
});

test('an admin cannot demote themselves while others exist', function () {
    $self = adminUser();
    adminUser();

    $this->actingAs($self)
        ->delete(route('admin.users.demote', $self))
        ->assertSessionHasErrors('is_admin');

    expect($self->fresh()->is_admin)->toBeTrue();
});

test('a non admin cannot promote anyone', function () {
    $target = plainUser();

    $this->actingAs(plainUser())
        ->post(route('admin.users.promote', $target))
        ->assertForbidden();

    expect($target->fresh()->is_admin)->toBeFalse();
});

test('the console command grants admin access', function () {
    $user = plainUser();

    Artisan::call('user:admin', ['email' => $user->email]);

    expect($user->fresh()->is_admin)->toBeTrue()
        ->and(Artisan::output())->toContain('is now an administrator');
});

test('the console command revokes admin access', function () {
    adminUser();
    $target = adminUser();

    Artisan::call('user:admin', ['email' => $target->email, '--revoke' => true]);

    expect($target->fresh()->is_admin)->toBeFalse();
});

test('the console command refuses to remove the last administrator', function () {
    $only = adminUser();

    $exit = Artisan::call('user:admin', ['email' => $only->email, '--revoke' => true]);

    expect($exit)->toBe(1)
        ->and($only->fresh()->is_admin)->toBeTrue();
});

test('the console command fails for an unknown email', function () {
    expect(Artisan::call('user:admin', ['email' => 'nobody@example.com']))->toBe(1);
});

test('the users tab reports how many admins exist', function () {
    adminUser();
    plainUser();

    $this->actingAs(adminUser())->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/Users')
            ->where('adminCount', 2)
            ->has('users', 3)
            ->missing('users.0.verified'));
});
