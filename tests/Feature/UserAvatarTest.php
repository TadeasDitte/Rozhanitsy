<?php

use App\Models\User;

test('avatar is a gravatar url hashed from the lowercased email', function () {
    $user = User::factory()->make(['email' => 'Jane.Doe@Example.com']);

    $hash = hash('sha256', 'jane.doe@example.com');

    expect($user->avatar)
        ->toContain("gravatar.com/avatar/{$hash}")
        ->toContain('d=404');
});

test('avatar is appended when the user is serialized', function () {
    $user = User::factory()->make(['email' => 'jane@example.com']);

    expect($user->toArray())->toHaveKey('avatar');
});
