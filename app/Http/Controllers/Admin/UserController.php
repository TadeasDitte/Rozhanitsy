<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScanHost;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with(['scanHosts' => fn ($query) => $query->withCount('tokens')->latest('id')])
            ->latest('id')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'created_at' => $user->created_at?->toDayDateTimeString(),
                'hosts' => $user->scanHosts->map(fn (ScanHost $host): array => [
                    'id' => $host->id,
                    'hostname' => $host->hostname,
                    'is_active' => $host->is_active,
                    'has_token' => $host->tokens_count > 0,
                    'last_seen_at' => $host->last_seen_at?->toDayDateTimeString(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Users', [
            'users' => $users,
            'adminCount' => User::where('is_admin', true)->count(),
        ]);
    }

    public function promote(User $user): RedirectResponse
    {
        $user->update(['is_admin' => true]);

        return back();
    }

    public function demote(Request $request, User $user): RedirectResponse
    {
        if (User::where('is_admin', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'is_admin' => 'There must be at least one administrator.',
            ]);
        }

        if ($user->is($request->user('web'))) {
            throw ValidationException::withMessages([
                'is_admin' => 'You cannot remove your own administrator access.',
            ]);
        }

        $user->update(['is_admin' => false]);

        return back();
    }

    public function revoke(ScanHost $scanHost): RedirectResponse
    {
        $scanHost->tokens()->delete();
        $scanHost->update(['is_active' => false]);

        return back();
    }
}
