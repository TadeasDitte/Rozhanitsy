<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GhsaCoreCrossChecker
{
    private const BASE_URL = 'https://api.github.com/advisories';

    /**
     * Cross-check a CVE against GitHub's Security Advisory database.
     *
     * Returns true if GHSA independently tags this CVE under a package
     * ecosystem (composer, npm, pip, ...) — strong evidence NVD's CPE match
     * pinned it to the wrong product (a library mistagged against the
     * platform it happens to share a vendor slug with; see CVE-2025-25226,
     * tagged by NVD as cpe:2.3:a:joomla:joomla\!:* — the CMS itself — while
     * GHSA correctly lists it as composer package joomla/database).
     *
     * Returns false if GHSA has a record for the CVE but no ecosystem
     * package on it. Returns null if the check could not be completed
     * (network failure, rate limit, no GHSA record at all) so the caller can
     * leave the CVE unchecked and retry on a later run rather than treating
     * "we don't know" as "it's fine".
     */
    public function hasEcosystemPackage(string $cveId): ?bool
    {
        $token = config('services.github.token');

        try {
            $response = Http::withHeaders(array_filter([
                'Accept' => 'application/vnd.github+json',
                'Authorization' => $token ? "Bearer {$token}" : null,
            ]))
                ->connectTimeout(5)
                ->timeout(15)
                ->get(self::BASE_URL, ['cve_id' => $cveId]);
        } catch (Throwable $e) {
            Log::warning("GHSA cross-check request failed for {$cveId}: {$e->getMessage()}");

            return null;
        }

        if ($response->status() === 404) {
            return false;
        }

        if (! $response->successful()) {
            Log::warning("GHSA cross-check for {$cveId} returned HTTP {$response->status()}.");

            return null;
        }

        $advisories = $response->json();

        if (! is_array($advisories)) {
            return null;
        }

        foreach ($advisories as $advisory) {
            foreach ($advisory['vulnerabilities'] ?? [] as $vulnerability) {
                $ecosystem = $vulnerability['package']['ecosystem'] ?? null;

                if (is_string($ecosystem) && $ecosystem !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
