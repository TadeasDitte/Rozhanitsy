<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckVulnerabilitiesRequest;
use App\Models\CpeMap;
use App\Models\ScanLog;
use App\Models\UnmatchedLookup;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use App\Services\VersionComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-import-type ScannedComponent from CheckVulnerabilitiesRequest
 */
class VulnCheckController extends Controller
{
    public function check(CheckVulnerabilitiesRequest $request, VersionComparator $comparator): JsonResponse
    {
        $components = $request->components();
        $tenantId = $request->tenantId();

        $vendors = array_unique(array_map(Str::lower(...), array_column($components, 'vendor')));
        $products = array_unique(array_map(Str::lower(...), array_column($components, 'product')));

        $cpeRows = CpeMap::query()
            ->whereIn('cpe_vendor', $vendors)
            ->whereIn('cpe_product', $products)
            ->get(['cpe_vendor', 'cpe_product', 'product_id'])
            ->keyBy(fn (CpeMap $row): string => Str::lower($row->cpe_vendor).'|'.Str::lower($row->cpe_product));

        /** @var list<array{vendor: string, product: string, local_id: string|null}> $unmatched */
        $unmatched = [];

        /** @var array<string, array{0: string, 1: string}> $unmatchedPairs */
        $unmatchedPairs = [];

        /** @var array<int, array<int, ScannedComponent>> $componentsByProduct */
        $componentsByProduct = [];

        foreach ($components as $index => $component) {
            $vendor = Str::lower($component['vendor']);
            $product = Str::lower($component['product']);

            $cpeRow = $cpeRows->get($vendor.'|'.$product);

            if ($cpeRow === null) {
                $unmatched[] = [
                    'vendor' => $component['vendor'],
                    'product' => $component['product'],
                    'local_id' => $component['local_id'] ?? null,
                ];

                $unmatchedPairs[$vendor.'|'.$product] = [$vendor, $product];

                continue;
            }

            $componentsByProduct[$cpeRow->product_id][$index] = $component;
        }

        $vulnerable = $componentsByProduct === []
            ? []
            : $this->matchRanges($componentsByProduct, $comparator, $request->minCvssScore(), $request->severities());

        if ($unmatchedPairs !== []) {
            $this->recordUnmatched(array_values($unmatchedPairs));
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
     * A CVE's ranges are grouped by `group_index` (independently-OR'd
     * applicability scenarios, i.e. matching the source CVE's own
     * `configurations` array) and, within a group, by `clause_index` (nodes
     * an "AND" configuration combined — every clause must be satisfied,
     * possibly by different products, for the group to apply). Rows sharing
     * both indexes are plain OR alternatives, which is the whole of what
     * every CVE looked like before `clause_index` existed.
     *
     * Attribution ranks matches in three tiers: `bounded` (a real version
     * range) beats `unbounded` (a bare `*` wildcard with no range at all —
     * ambiguous NVD data, could genuinely mean "every version") beats
     * `platform` (CPE version field literally `-`, CPE 2.3's "not
     * applicable" marker — almost always a co-requirement like "plugin X
     * requires WordPress installed", never itself a claim that the platform
     * is the vulnerable thing). Reports the highest tier present; if only
     * `platform`-tier matches exist, reports nothing for that group rather
     * than attributing a vulnerability to a component NVD never actually
     * named as vulnerable. The winning tier is echoed on the response entry
     * as `confidence` (`platform` never appears there, since it's never
     * attributed) — see api.md.
     *
     * `minCvssScore` and `severities` filter on the vulnerability as a whole
     * (both columns live on `vulnerabilities`, one row per CVE) rather than
     * per-range, so applying them to the outer query before grouping is
     * equivalent to filtering the final results and cheaper. A CVE with a
     * `null` `cvss_score`/`cvss_severity` never satisfies either filter when
     * active — an unscored CVE can't be proven to meet a threshold, so it's
     * excluded rather than assumed to pass.
     *
     * @param  array<int, array<int, ScannedComponent>>  $componentsByProduct
     * @param  list<string>  $severities
     * @return list<array<string, mixed>>
     */
    private function matchRanges(array $componentsByProduct, VersionComparator $comparator, ?float $minCvssScore = null, array $severities = []): array
    {
        $rangeTable = (new VulnerabilityRange)->getTable();
        $vulnerabilityTable = (new Vulnerability)->getTable();
        $productIds = array_keys($componentsByProduct);

        // The outer fetch must NOT exclude unmatched rows: an AND-group clause
        // whose product never resolved has to still be visible as a clause
        // that exists, so it correctly fails to complete, rather than being
        // invisible and letting the group look complete by omission.
        $rows = DB::table($rangeTable)
            ->join($vulnerabilityTable, "{$vulnerabilityTable}.id", '=', "{$rangeTable}.vulnerability_id")
            ->whereIn("{$rangeTable}.vulnerability_id", function ($query) use ($rangeTable, $productIds): void {
                $query->select('vulnerability_id')
                    ->from($rangeTable)
                    ->whereIn('product_id', $productIds)
                    ->where('match_confidence', '!=', VulnerabilityRange::MATCH_UNMATCHED);
            })
            ->when($minCvssScore !== null, fn ($query) => $query->where("{$vulnerabilityTable}.cvss_score", '>=', $minCvssScore))
            ->when($severities !== [], fn ($query) => $query->whereIn("{$vulnerabilityTable}.cvss_severity", $severities))
            ->get([
                "{$rangeTable}.vulnerability_id",
                "{$rangeTable}.product_id",
                "{$rangeTable}.group_index",
                "{$rangeTable}.clause_index",
                "{$rangeTable}.version_start",
                "{$rangeTable}.version_start_incl",
                "{$rangeTable}.version_end",
                "{$rangeTable}.version_end_incl",
                "{$rangeTable}.raw_cpe",
                "{$vulnerabilityTable}.cve_id",
                "{$vulnerabilityTable}.cvss_score",
                "{$vulnerabilityTable}.cvss_vector",
                "{$vulnerabilityTable}.cvss_severity",
            ]);

        $vulnerable = [];
        $seen = [];

        foreach ($rows->groupBy('vulnerability_id') as $vulnerabilityRows) {
            $cveMeta = $vulnerabilityRows->first();
            $cveId = (string) $cveMeta->cve_id;

            foreach ($vulnerabilityRows->groupBy('group_index') as $groupRows) {
                $matchesByClause = [];
                $groupSatisfied = true;

                foreach ($groupRows->groupBy('clause_index') as $clauseIndex => $clauseRows) {
                    $clauseMatches = [];

                    foreach ($clauseRows as $range) {
                        foreach ($componentsByProduct[$range->product_id] ?? [] as $componentIndex => $component) {
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

                            $clauseMatches[] = [
                                'index' => $componentIndex,
                                'component' => $component,
                                'tier' => $this->attributionTier($range),
                            ];
                        }
                    }

                    if ($clauseMatches === []) {
                        $groupSatisfied = false;

                        break;
                    }

                    $matchesByClause[$clauseIndex] = $clauseMatches;
                }

                if (! $groupSatisfied) {
                    continue;
                }

                $allMatches = collect($matchesByClause)->flatten(1);
                $attribution = $allMatches->where('tier', 'bounded');

                if ($attribution->isEmpty()) {
                    $attribution = $allMatches->where('tier', 'unbounded');
                }

                foreach ($attribution as $match) {
                    $seenKey = $match['index'].'|'.$cveId;

                    if (isset($seen[$seenKey])) {
                        continue;
                    }

                    $seen[$seenKey] = true;

                    $component = $match['component'];

                    $vulnerable[] = [
                        'vendor' => $component['vendor'],
                        'product' => $component['product'],
                        'local_id' => $component['local_id'] ?? null,
                        'installed_version' => $component['version'],
                        'cve_id' => $cveId,
                        'cvss_score' => $cveMeta->cvss_score !== null ? (float) $cveMeta->cvss_score : null,
                        'cvss_vector' => $cveMeta->cvss_vector,
                        'cvss_severity' => $cveMeta->cvss_severity,
                        'confidence' => $match['tier'],
                    ];
                }
            }
        }

        return $vulnerable;
    }

    private function attributionTier(\stdClass $range): string
    {
        if ($range->version_start !== null || $range->version_end !== null) {
            return 'bounded';
        }

        $versionField = is_string($range->raw_cpe) ? (explode(':', $range->raw_cpe)[5] ?? null) : null;

        return $versionField === '-' ? 'platform' : 'unbounded';
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  Normalized cpe_vendor / cpe_product pairs.
     */
    private function recordUnmatched(array $pairs): void
    {
        $now = now();

        $placeholders = [];
        $bindings = [];

        foreach ($pairs as [$cpeVendor, $cpeProduct]) {
            $placeholders[] = '(?, ?, 1, ?, ?, ?, ?)';
            array_push($bindings, $cpeVendor, $cpeProduct, $now, $now, $now, $now);
        }

        $table = (new UnmatchedLookup)->getTable();

        DB::statement(
            "INSERT INTO {$table} (cpe_vendor, cpe_product, hit_count, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES ".implode(', ', $placeholders)."
             ON CONFLICT (cpe_vendor, cpe_product)
             DO UPDATE SET
                 hit_count = {$table}.hit_count + 1,
                 last_seen_at = EXCLUDED.last_seen_at,
                 updated_at = EXCLUDED.updated_at",
            $bindings,
        );
    }
}
