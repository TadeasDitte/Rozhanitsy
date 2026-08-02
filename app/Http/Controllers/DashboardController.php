<?php

namespace App\Http\Controllers;

use App\Models\ScanHost;
use App\Models\ScanLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user('web');
        $hostIds = $user->scanHosts()->pluck('id');
        $since = now()->subDays(30);

        $logs = ScanLog::whereIn('scan_host_id', $hostIds);

        return Inertia::render('Dashboard', [
            'stats' => [
                'hosts' => $hostIds->count(),
                'activeHosts' => $user->scanHosts()->where('is_active', true)->count(),
                'scans' => (clone $logs)->where('scanned_at', '>=', $since)->count(),
                'components' => (int) (clone $logs)->where('scanned_at', '>=', $since)->sum('component_count'),
                'vulnerable' => (int) (clone $logs)->where('scanned_at', '>=', $since)->sum('vulnerable_count'),
                'unmatched' => (int) (clone $logs)->where('scanned_at', '>=', $since)->sum('unmatched_count'),
            ],
            'hosts' => $user->scanHosts()
                ->withCount('tokens')
                ->latest('id')
                ->get()
                ->map(fn (ScanHost $host): array => [
                    'id' => $host->id,
                    'hostname' => $host->hostname,
                    'is_active' => $host->is_active,
                    'has_token' => $host->tokens_count > 0,
                    'last_seen_at' => $host->last_seen_at?->toDayDateTimeString(),
                ])
                ->values()
                ->all(),
            'recentScans' => (clone $logs)
                ->with('scanHost:id,hostname')
                ->latest('scanned_at')
                ->limit(10)
                ->get()
                ->map(fn (ScanLog $log): array => [
                    'id' => $log->id,
                    'hostname' => $log->scanHost?->hostname,
                    'tenant_id' => $log->tenant_id,
                    'components' => $log->component_count,
                    'vulnerable' => $log->vulnerable_count,
                    'unmatched' => $log->unmatched_count,
                    'scanned_at' => $log->scanned_at->toDayDateTimeString(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
