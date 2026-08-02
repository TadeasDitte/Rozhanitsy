<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScanTokenRequest;
use App\Models\ScanHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScanTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Tokens', [
            'hosts' => $this->hostsFor($request),
        ]);
    }

    public function store(StoreScanTokenRequest $request): RedirectResponse
    {
        $host = ScanHost::create([
            'user_id' => $request->user('web')->id,
            'hostname' => $request->validated('hostname'),
            'is_active' => true,
        ]);

        return back()->with('scanToken', [
            'hostname' => $host->hostname,
            'token' => $host->createToken('scanner')->plainTextToken,
        ]);
    }

    public function regenerate(Request $request, ScanHost $scanHost): RedirectResponse
    {
        $this->authorizeHost($request, $scanHost);

        $scanHost->tokens()->delete();
        $scanHost->update(['is_active' => true]);

        return back()->with('scanToken', [
            'hostname' => $scanHost->hostname,
            'token' => $scanHost->createToken('scanner')->plainTextToken,
        ]);
    }

    public function destroy(Request $request, ScanHost $scanHost): RedirectResponse
    {
        $this->authorizeHost($request, $scanHost);

        $scanHost->tokens()->delete();
        $scanHost->update(['is_active' => false]);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hostsFor(Request $request): array
    {
        return $request->user('web')
            ->scanHosts()
            ->withCount('tokens')
            ->latest('id')
            ->get()
            ->map(fn (ScanHost $host): array => [
                'id' => $host->id,
                'hostname' => $host->hostname,
                'is_active' => $host->is_active,
                'has_token' => $host->tokens_count > 0,
                'last_seen_at' => $host->last_seen_at?->toDayDateTimeString(),
                'created_at' => $host->created_at?->toDayDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function authorizeHost(Request $request, ScanHost $scanHost): void
    {
        abort_unless($scanHost->user_id === $request->user('web')->id, 403);
    }
}
