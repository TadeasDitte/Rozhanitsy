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

    private const PAGE_SIZE = 2000;

    private const THROTTLE_WITH_KEY_MICROSECONDS = 600_000;

    private const THROTTLE_WITHOUT_KEY_MICROSECONDS = 6_000_000;

    public function handle(NvdCpeResolver $resolver): int
    {
        $source = Source::where('slug', 'nvd')->first();

        if ($source === null) {
            $this->error('No "nvd" source row found. Run: php artisan db:seed --class=SourceSeeder');

            return self::FAILURE;
        }

        $endpoint = $source->url;

        if (! is_string($endpoint) || $endpoint === '') {
            $this->error('The "nvd" source row has no url. Set sources.url to the NVD 2.0 API endpoint.');

            return self::FAILURE;
        }

        $state = SyncState::firstOrCreate(['source_id' => $source->id]);
        $since = $this->option('full') ? null : $state->last_synced_at;

        $runStartedAt = now();

        $startIndex = 0;
        $totalResults = null;
        $apiKey = config('services.nvd.api_key');

        do {
            $params = [
                'resultsPerPage' => self::PAGE_SIZE,
                'startIndex' => $startIndex,
            ];

            if ($since !== null) {
                $params['lastModStartDate'] = $since->toIso8601String();
                $params['lastModEndDate'] = $runStartedAt->toIso8601String();
            }

            $response = Http::withHeaders(array_filter(['apiKey' => $apiKey]))
                ->retry(3, 5000, throw: false)
                ->timeout(120)
                ->get(self::NVD_ENDPOINT, $params);

            if ($response->failed()) {
                $this->error("NVD request failed at index {$startIndex}: {$response->status()}");

                $state->update(['last_index' => $startIndex]);

                return self::FAILURE;
            }

            $data = $response->json();
            $totalResults ??= (int) ($data['totalResults'] ?? 0);
            $page = $data['vulnerabilities'] ?? [];

            foreach ($page as $entry) {
                $this->processCve($entry['cve'], $source, $resolver);
            }

            $startIndex += self::PAGE_SIZE;
            $this->info('Processed '.min($startIndex, $totalResults)." / {$totalResults}");

            $state->update(['last_index' => $startIndex]);

            if ($startIndex < $totalResults) {
                usleep($apiKey ? self::THROTTLE_WITH_KEY_MICROSECONDS : self::THROTTLE_WITHOUT_KEY_MICROSECONDS);
            }
        } while ($startIndex < $totalResults);

        $state->update(['last_synced_at' => $runStartedAt, 'last_index' => null]);

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

        VulnerabilityRange::create([
            'vulnerability_id' => $vulnerability->id,
            'product_id' => $resolved['product_id'],
            'match_confidence' => $resolved['confidence'],
            'version_start' => $match['versionStartIncluding'] ?? $match['versionStartExcluding'] ?? null,
            'version_start_incl' => isset($match['versionStartIncluding']),
            'version_end' => $match['versionEndIncluding'] ?? $match['versionEndExcluding'] ?? null,
            'version_end_incl' => isset($match['versionEndIncluding']),
            'raw_cpe' => $criteria,
        ]);
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
