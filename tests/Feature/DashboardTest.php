<?php

use App\Models\ScanHost;
use App\Models\ScanLog;
use App\Models\UnmatchedLookup;
use App\Models\User;
use App\Models\Vulnerability;

function panelUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

test('the user dashboard only counts the signed in users scans', function () {
    $user = panelUser();
    $mine = ScanHost::factory()->create(['user_id' => $user->id]);
    $theirs = ScanHost::factory()->create(['user_id' => panelUser()->id]);

    ScanLog::factory()->create([
        'scan_host_id' => $mine->id,
        'component_count' => 10,
        'vulnerable_count' => 3,
        'unmatched_count' => 2,
    ]);
    ScanLog::factory()->create([
        'scan_host_id' => $theirs->id,
        'component_count' => 99,
        'vulnerable_count' => 99,
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.hosts', 1)
            ->where('stats.components', 10)
            ->where('stats.vulnerable', 3)
            ->where('stats.unmatched', 2)
            ->has('recentScans', 1));
});

test('scans older than thirty days fall out of the dashboard window', function () {
    $user = panelUser();
    $host = ScanHost::factory()->create(['user_id' => $user->id]);

    ScanLog::factory()->create([
        'scan_host_id' => $host->id,
        'component_count' => 5,
        'scanned_at' => now()->subDays(45),
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('stats.scans', 0)
            ->where('stats.components', 0)
            ->has('recentScans', 1));
});

test('a new user sees an empty dashboard', function () {
    $this->actingAs(panelUser())->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.hosts', 0)
            ->has('hosts', 0)
            ->has('recentScans', 0));
});

test('the admin dashboard is admin only', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->actingAs(panelUser())->get(route('admin.dashboard'))->assertForbidden();
});

test('the admin dashboard aggregates across every user', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $host = ScanHost::factory()->create(['user_id' => panelUser()->id]);

    ScanLog::factory()->create([
        'scan_host_id' => $host->id,
        'component_count' => 7,
        'vulnerable_count' => 4,
    ]);
    Vulnerability::factory()->count(3)->create();
    UnmatchedLookup::factory()->create(['cpe_vendor' => 'acme', 'cpe_product' => 'widget', 'hit_count' => 42]);

    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Dashboard')
            ->where('stats.users', 2)
            ->where('stats.admins', 1)
            ->where('stats.hosts', 1)
            ->where('stats.vulnerabilities', 3)
            ->where('scans.components', 7)
            ->where('scans.vulnerable', 4)
            ->where('topUnmatched.0.hits', 42)
            ->has('recentScans', 1));
});
