<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('redirect route sends the user to authentik', function () {
    Socialite::fake('authentik');

    $response = $this->get(route('authentik.redirect'));

    $response->assertRedirect('https://socialite.fake/authentik/authorize');
});

test('callback creates and logs in a new user', function () {
    Socialite::fake('authentik', SocialiteUser::fake([
        'id' => 'authentik-123',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]));

    $response = $this->get(route('authentik.callback'));

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'jane@example.com')->sole();

    expect($user->authentik_id)->toBe('authentik-123')
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('callback links an existing user by email', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    Socialite::fake('authentik', SocialiteUser::fake([
        'id' => 'authentik-123',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]));

    $response = $this->get(route('authentik.callback'));

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->count())->toBe(1);
    expect($user->fresh()->authentik_id)->toBe('authentik-123');
});

test('callback logs in an existing authentik-linked user', function () {
    $user = User::factory()->create(['authentik_id' => 'authentik-123']);

    Socialite::fake('authentik', SocialiteUser::fake([
        'id' => 'authentik-123',
        'name' => $user->name,
        'email' => $user->email,
    ]));

    $this->get(route('authentik.callback'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

test('callback redirects back to login when authentication fails', function () {
    Socialite::fake('authentik', function (): never {
        throw new Exception('access_denied');
    });

    $response = $this->get(route('authentik.callback'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});
