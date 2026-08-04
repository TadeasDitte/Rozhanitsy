<?php

namespace App\Console\Commands;

use App\Models\Vulnerability;
use App\Models\VulnerabilityRange;
use App\Services\GhsaCoreCrossChecker;
use Illuminate\Console\Command;

class CrossCheckCoreVulnerabilities extends Command
{
    protected $signature = 'nvd:cross-check-core
        {--limit=50 : Maximum CVEs to check this run (stays well under GitHub\'s unauthenticated rate limit)}
        {--force : Re-check CVEs that already have a cached GHSA verdict}';

    protected $description = 'Cross-check CVEs resolved to a core (CMS/platform) product against GitHub Security Advisories, catching NVD CPE matches that mistakenly point a library CVE at the platform it shares a vendor slug with';

    public function handle(GhsaCoreCrossChecker $checker): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = Vulnerability::query()
            ->whereHas('ranges', function ($q): void {
                $q->whereNotNull('product_id')
                    ->whereHas('product', fn ($p) => $p->where('type', 'core'));
            });

        if (! $this->option('force')) {
            $query->whereNull('ghsa_checked_at');
        }

        $vulnerabilities = $query->limit($limit)->get();

        if ($vulnerabilities->isEmpty()) {
            $this->info('Nothing to cross-check.');

            return self::SUCCESS;
        }

        $checked = 0;
        $flagged = 0;

        foreach ($vulnerabilities as $vulnerability) {
            $result = $checker->hasEcosystemPackage($vulnerability->cve_id);

            if ($result === null) {
                $this->warn("Could not reach GHSA for {$vulnerability->cve_id}, will retry next run.");

                continue;
            }

            $vulnerability->update([
                'ghsa_checked_at' => now(),
                'ghsa_ecosystem_mismatch' => $result,
            ]);

            $checked++;

            if (! $result) {
                continue;
            }

            $flagged++;

            $downgraded = VulnerabilityRange::where('vulnerability_id', $vulnerability->id)
                ->whereNotNull('product_id')
                ->whereHas('product', fn ($p) => $p->where('type', 'core'))
                ->update(['match_confidence' => VulnerabilityRange::MATCH_UNMATCHED]);

            $this->warn("{$vulnerability->cve_id}: GHSA tags this under a package ecosystem but it resolved to a core product here — downgraded {$downgraded} range(s).");
        }

        $this->info("Checked {$checked} CVE(s), flagged {$flagged} as ecosystem mismatches.");

        return self::SUCCESS;
    }
}
