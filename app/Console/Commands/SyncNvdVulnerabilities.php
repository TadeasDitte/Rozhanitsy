<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SyncState;
use App\Models\Vulnerability;
use App\Services\NvdCveMapper;
use App\Services\NvdPageReader;
use App\Services\VulnerabilityRangeBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class SyncNvdVulnerabilities extends Command
{
    protected $signature = 'nvd:sync {--full : Ignore watermark, resync everything}';

    protected $description = 'Pull CVE/CPE data from NVD and upsert into vulnerabilities + vulnerability_ranges';

    private const DRIVER = 'nvd';

    private const RETRY_BACKOFF_MILLISECONDS = [2_000, 5_000];

    public function handle(VulnerabilityRangeBuilder $builder, NvdCveMapper $mapper): int
    {
        ini_set('memory_limit', '256M');

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

            $reader = new NvdPageReader($response->toPsrResponse()->getBody());
            $received = 0;

            try {
                foreach ($reader->vulnerabilities() as $entry) {
                    $received++;

                    $cve = is_array($entry) ? ($entry['cve'] ?? null) : null;

                    if (! is_array($cve) || ! is_string($cve['id'] ?? null)) {
                        $this->warn("Skipping malformed CVE entry near index {$startIndex}.");

                        continue;
                    }

                    $this->processCve($cve, $source, $builder, $mapper);
                }
            } catch (JsonException|RuntimeException $exception) {
                $this->error("Unreadable NVD response at index {$startIndex}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $totalResults ??= $reader->totalResults();

            if ($received === 0) {
                if ($startIndex < $totalResults) {
                    $this->error("NVD returned an empty page at index {$startIndex} of {$totalResults}.");

                    return self::FAILURE;
                }

                break;
            }

            $startIndex += $received;
            $this->info("Processed {$startIndex} / {$totalResults}");

            unset($response, $reader);
            gc_collect_cycles();

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
    private function processCve(array $cve, Source $source, VulnerabilityRangeBuilder $builder, NvdCveMapper $mapper): void
    {
        DB::transaction(function () use ($cve, $source, $builder, $mapper): void {
            $vulnerability = Vulnerability::updateOrCreate(
                ['cve_id' => $cve['id']],
                ['source_id' => $source->id, 'raw_data' => $cve] + $mapper->attributes($cve),
            );

            $builder->build($vulnerability, $cve);
        });
    }
}
