<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScanHost;
use App\Models\ScanLog;
use App\Models\SyncState;
use App\Models\UnmatchedLookup;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $since = now()->subDays(30);

        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'users' => User::count(),
                'admins' => User::where('is_admin', true)->count(),
                'hosts' => ScanHost::count(),
                'activeHosts' => ScanHost::where('is_active', true)->count(),
                'vulnerabilities' => Vulnerability::count(),
                'resolvedRanges' => VulnerabilityRange::resolved()->count(),
                'unmatchedRanges' => VulnerabilityRange::unmatched()->count(),
                'unmatchedLookups' => UnmatchedLookup::count(),
            ],
            'scans' => [
                'total' => ScanLog::count(),
                'recent' => ScanLog::where('scanned_at', '>=', $since)->count(),
                'components' => (int) ScanLog::where('scanned_at', '>=', $since)->sum('component_count'),
                'vulnerable' => (int) ScanLog::where('scanned_at', '>=', $since)->sum('vulnerable_count'),
                'unmatched' => (int) ScanLog::where('scanned_at', '>=', $since)->sum('unmatched_count'),
            ],
            'lastSyncedAt' => SyncState::query()->max('last_synced_at'),
            'topUnmatched' => UnmatchedLookup::query()
                ->orderByDesc('hit_count')
                ->limit(10)
                ->get()
                ->map(fn (UnmatchedLookup $lookup): array => [
                    'id' => $lookup->id,
                    'vendor' => $lookup->cpe_vendor,
                    'product' => $lookup->cpe_product,
                    'hits' => $lookup->hit_count,
                ])
                ->values()
                ->all(),
            'recentScans' => ScanLog::query()
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
