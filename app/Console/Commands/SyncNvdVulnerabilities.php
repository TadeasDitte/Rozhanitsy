<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SyncState;
use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use App\Services\NvdCpeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncNvdVulnerabilities extends Command
{
    protected $signature = 'nvd:sync {--full : Ignore watermark, resync everything}';

    protected $description = 'Pull CVE/CPE data from NVD and upsert into vulnerabilities + vulnerability_ranges';

    /**
     * The one irreducible binding between this parser and its sources row.
     * Everything else about the feed — endpoint, page size, rate limits — is
     * read from that row rather than hardcoded here.
     */
    private const DRIVER = 'nvd';

    /** Increasing backoff; the array length sets the retry count. */
    private const RETRY_BACKOFF_MILLISECONDS = [2_000, 5_000];

    public function handle(NvdCpeResolver $resolver): int
    {
        $source = Source::where('driver', self::DRIVER)->first();

        if ($source === null) {
            $this->error('No source row with driver "'.self::DRIVER.'". Run: php artisan db:seed --class=SourceSeeder');

            return self::FAILURE;
        }

        $missing = collect(['url', 'page_size', 'request_delay_ms', 'unauthenticated_request_delay_ms'])
            ->filter(fn (string $column): bool => blank($source->{$column}));

        if ($missing->isNotEmpty()) {
            $this->error('Source "'.$source->slug.'" is missing: '.$missing->implode(', ').'.');

            return self::FAILURE;
        }

        $endpoint = (string) $source->url;
        $pageSize = (int) $source->page_size;

        $state = SyncState::firstOrCreate(['source_id' => $source->id]);
        $since = $this->option('full') ? null : $state->last_synced_at;

        $runStartedAt = now();

        $startIndex = 0;
        $totalResults = null;
        $apiKey = config('services.nvd.api_key');

        $delayMilliseconds = (int) ($apiKey
            ? $source->request_delay_ms
            : $source->unauthenticated_request_delay_ms);

        do {
            $params = [
                'resultsPerPage' => $pageSize,
                'startIndex' => $startIndex,
            ];

            if ($since !== null) {
                $params['lastModStartDate'] = $since->toIso8601String();
                $params['lastModEndDate'] = $runStartedAt->toIso8601String();
            }

            $response = Http::withHeaders(array_filter(['apiKey' => $apiKey]))
                ->retry(self::RETRY_BACKOFF_MILLISECONDS, throw: false)
                ->connectTimeout(10)
                ->timeout(120)
                ->get($endpoint, $params);

            if ($response->failed()) {
                $this->error("NVD request failed at index {$startIndex}: {$response->status()}");

                return self::FAILURE;
            }

            $data = $response->json();
            $totalResults ??= (int) ($data['totalResults'] ?? 0);
            $page = $data['vulnerabilities'] ?? [];

            foreach ($page as $entry) {
                $cve = $entry['cve'] ?? null;

                /** One malformed entry must not abort the run and lose the rest of the page. */
                if (! is_array($cve) || ! is_string($cve['id'] ?? null)) {
                    $this->warn("Skipping malformed CVE entry near index {$startIndex}.");

                    continue;
                }

                $this->processCve($cve, $source, $resolver);
            }

            /**
             * Advance by what NVD actually returned. It degrades resultsPerPage
             * under load, and stepping by the requested page size would skip the
             * shortfall while still reporting success.
             */
            $received = count($page);

            if ($received === 0) {
                if ($startIndex < $totalResults) {
                    $this->error("NVD returned an empty page at index {$startIndex} of {$totalResults}.");

                    return self::FAILURE;
                }

                break;
            }

            $startIndex += $received;
            $this->info("Processed {$startIndex} / {$totalResults}");

            if ($startIndex < $totalResults) {
                usleep($delayMilliseconds * 1000);
            }
        } while ($startIndex < $totalResults);

        $state->update(['last_synced_at' => $runStartedAt]);

        $this->info("NVD sync complete ({$totalResults} CVEs).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $cve
     */
    private function processCve(array $cve, Source $source, NvdCpeResolver $resolver): void
    {
        DB::transaction(function () use ($cve, $source, $resolver): void {
            $vulnerability = Vulnerability::updateOrCreate(
                ['cve_id' => $cve['id']],
                [
                    'source_id' => $source->id,
                    'description' => $this->extractEnglishDescription($cve),
                    'cvss_score' => $this->extractCvss($cve, 'baseScore'),
                    'cvss_vector' => $this->extractCvss($cve, 'vectorString'),
                    'cvss_version' => $this->extractCvss($cve, 'version'),
                    'cvss_severity' => $this->extractCvss($cve, 'baseSeverity'),
                    'published_at' => $cve['published'] ?? null,
                    'last_modified_at' => $cve['lastModified'] ?? null,
                    'raw_data' => $cve,
                ]
            );

            VulnerabilityRange::where('vulnerability_id', $vulnerability->id)->delete();

            foreach ($cve['configurations'] ?? [] as $configuration) {
                foreach ($configuration['nodes'] ?? [] as $node) {
                    foreach ($node['cpeMatch'] ?? [] as $match) {
                        if (! ($match['vulnerable'] ?? true)) {
                            continue;
                        }

                        $this->storeRange($vulnerability, $match, $resolver);
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function storeRange(Vulnerability $vulnerability, array $match, NvdCpeResolver $resolver): void
    {
        $criteria = $match['criteria'] ?? null;

        if (! is_string($criteria)) {
            return;
        }

        $resolved = $resolver->resolve($criteria);

        $versionStart = $match['versionStartIncluding'] ?? $match['versionStartExcluding'] ?? null;
        $versionEnd = $match['versionEndIncluding'] ?? $match['versionEndExcluding'] ?? null;
        $startIncl = isset($match['versionStartIncluding']);
        $endIncl = isset($match['versionEndIncluding']);

        /**
         * NVD states a single affected release as a concrete version inside the
         * CPE itself, with no range keys at all. Storing that as an unbounded
         * range would mark every installed version vulnerable, so pin it to an
         * inclusive point range. A "*" version really does mean all versions.
         */
        if ($versionStart === null && $versionEnd === null) {
            $exactVersion = $this->exactVersionFrom($criteria);

            if ($exactVersion !== null) {
                $versionStart = $exactVersion;
                $versionEnd = $exactVersion;
                $startIncl = true;
                $endIncl = true;
            }
        }

        VulnerabilityRange::create([
            'vulnerability_id' => $vulnerability->id,
            'product_id' => $resolved['product_id'],
            'match_confidence' => $resolved['confidence'],
            'version_start' => $versionStart,
            'version_start_incl' => $startIncl,
            'version_end' => $versionEnd,
            'version_end_incl' => $endIncl,
            'raw_cpe' => $criteria,
        ]);
    }

    /**
     * Field 5 of a CPE 2.3 URI is the version. "*" (ANY) and "-" (NA) are
     * wildcards rather than real versions.
     */
    private function exactVersionFrom(string $criteria): ?string
    {
        $version = explode(':', $criteria)[5] ?? null;

        if (! is_string($version) || $version === '' || $version === '*' || $version === '-') {
            return null;
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>  $cve
     */
    private function extractEnglishDescription(array $cve): ?string
    {
        $descriptions = $cve['descriptions'] ?? [];

        if (! is_array($descriptions)) {
            return null;
        }

        foreach ($descriptions as $description) {
            if (! is_array($description) || ($description['lang'] ?? null) !== 'en') {
                continue;
            }

            return is_string($description['value'] ?? null) ? $description['value'] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cve
     */
    private function extractCvss(array $cve, string $field): string|float|null
    {
        $metrics = $cve['metrics'] ?? [];

        foreach (['cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'] as $key) {
            if (empty($metrics[$key])) {
                continue;
            }

            $entry = $metrics[$key][0];

            return $entry['cvssData'][$field] ?? $entry[$field] ?? null;
        }

        return null;
    }
}
