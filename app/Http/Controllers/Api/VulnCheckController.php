<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckVulnerabilitiesRequest;
use App\Models\CpeMap;
use App\Models\ScanLog;
use App\Services\VersionComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type ScannedComponent from CheckVulnerabilitiesRequest
 */
class VulnCheckController extends Controller
{
    public function check(CheckVulnerabilitiesRequest $request, VersionComparator $comparator): JsonResponse
    {
        $components = $request->components();
        $tenantId = $request->tenantId();

        $cpeRows = CpeMap::query()
            ->whereIn('cpe_vendor', array_unique(array_column($components, 'vendor')))
            ->whereIn('cpe_product', array_unique(array_column($components, 'product')))
            ->get(['cpe_vendor', 'cpe_product', 'product_id'])
            ->keyBy(fn (CpeMap $row): string => $row->cpe_vendor.'|'.$row->cpe_product);

        /** @var list<array{vendor: string, product: string, local_id: string|null}> $unmatched */
        $unmatched = [];

        /** @var array<int, list<ScannedComponent>> $componentsByProduct */
        $componentsByProduct = [];

        foreach ($components as $component) {
            $cpeRow = $cpeRows->get($component['vendor'].'|'.$component['product']);

            if ($cpeRow === null) {
                $unmatched[] = [
                    'vendor' => $component['vendor'],
                    'product' => $component['product'],
                    'local_id' => $component['local_id'] ?? null,
                ];

                continue;
            }

            $componentsByProduct[$cpeRow->product_id][] = $component;
        }

        $vulnerable = $componentsByProduct === []
            ? []
            : $this->matchRanges($componentsByProduct, $comparator);

        if ($unmatched !== []) {
            $this->recordUnmatched($unmatched);
        }

        ScanLog::create([
            'scan_host_id' => $request->user('sanctum')->id,
            'tenant_id' => $tenantId,
            'component_count' => count($components),
            'vulnerable_count' => count($vulnerable),
            'unmatched_count' => count($unmatched),
            'scanned_at' => now(),
        ]);

        return response()->json([
            'tenant_id' => $tenantId,
            'vulnerable' => $vulnerable,
            'unmatched' => $unmatched,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<int, list<ScannedComponent>>  $componentsByProduct
     * @return list<array<string, mixed>>
     */
    private function matchRanges(array $componentsByProduct, VersionComparator $comparator): array
    {
        $ranges = DB::table('vulnerability_ranges')
            ->join('vulnerabilities', 'vulnerabilities.id', '=', 'vulnerability_ranges.vulnerability_id')
            ->whereIn('vulnerability_ranges.product_id', array_keys($componentsByProduct))
            ->where('vulnerability_ranges.match_confidence', '!=', 'unmatched')
            ->get([
                'vulnerability_ranges.product_id',
                'vulnerability_ranges.version_start',
                'vulnerability_ranges.version_start_incl',
                'vulnerability_ranges.version_end',
                'vulnerability_ranges.version_end_incl',
                'vulnerabilities.cve_id',
                'vulnerabilities.cvss_score',
                'vulnerabilities.cvss_vector',
                'vulnerabilities.cvss_severity',
            ])
            ->groupBy('product_id');

        $vulnerable = [];

        foreach ($componentsByProduct as $productId => $componentsForProduct) {
            $rangesForProduct = $ranges->get($productId);

            if ($rangesForProduct === null) {
                continue;
            }

            foreach ($componentsForProduct as $component) {
                $seenCveIds = [];

                foreach ($rangesForProduct as $range) {
                    $cveId = (string) $range->cve_id;

                    if (isset($seenCveIds[$cveId])) {
                        continue;
                    }

                    $isAffected = $comparator->isAffected(
                        $component['version'],
                        $range->version_start !== null ? (string) $range->version_start : null,
                        (bool) $range->version_start_incl,
                        $range->version_end !== null ? (string) $range->version_end : null,
                        (bool) $range->version_end_incl,
                    );

                    if (! $isAffected) {
                        continue;
                    }

                    $seenCveIds[$cveId] = true;

                    $vulnerable[] = [
                        'vendor' => $component['vendor'],
                        'product' => $component['product'],
                        'local_id' => $component['local_id'] ?? null,
                        'installed_version' => $component['version'],
                        'cve_id' => $cveId,
                        'cvss_score' => $range->cvss_score !== null ? (float) $range->cvss_score : null,
                        'cvss_vector' => $range->cvss_vector,
                        'cvss_severity' => $range->cvss_severity,
                    ];
                }
            }
        }

        return $vulnerable;
    }

    /**
     * @param  list<array{vendor: string, product: string, local_id: string|null}>  $unmatched
     */
    private function recordUnmatched(array $unmatched): void
    {
        $now = now();

        $rows = collect($unmatched)
            ->unique(fn (array $row): string => $row['vendor'].'|'.$row['product'])
            ->values();

        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, 1, ?, ?, ?, ?)';
            array_push($bindings, $row['vendor'], $row['product'], $now, $now, $now, $now);
        }

        DB::statement(
            'INSERT INTO unmatched_lookups (cpe_vendor, cpe_product, hit_count, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES '.implode(', ', $placeholders).'
             ON CONFLICT (cpe_vendor, cpe_product)
             DO UPDATE SET
                 hit_count = unmatched_lookups.hit_count + 1,
                 last_seen_at = EXCLUDED.last_seen_at,
                 updated_at = EXCLUDED.updated_at',
            $bindings,
        );
    }
}
