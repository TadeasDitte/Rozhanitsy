<?php

namespace App\Console\Commands;

use App\Models\VulnerabilityRange;
use Illuminate\Console\Command;

class ShowPendingVersionReview extends Command
{
    protected $signature = 'nvd:pending-review {--limit=50 : Maximum rows to display}';

    protected $description = 'List vulnerability ranges held back from live matching: either their version data looked incomplete (no lower bound yet, still within the stability grace period) or GHSA flagged the CVE as a mistagged library/core-product mismatch';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $pending = VulnerabilityRange::unmatched()->whereNotNull('product_id');
        $total = $pending->count();

        if ($total === 0) {
            $this->info('No ranges are pending review.');

            return self::SUCCESS;
        }

        $ranges = (clone $pending)
            ->with(['vulnerability', 'product'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $this->table(
            ['CVE', 'Product', 'Reason', 'Version End', 'Raw CPE'],
            $ranges->map(fn (VulnerabilityRange $range): array => [
                $range->vulnerability?->cve_id,
                $range->product?->name,
                $this->reason($range),
                $range->version_end,
                $range->raw_cpe,
            ])->all(),
        );

        $this->newLine();
        $this->line('Missing-floor rows self-heal once NVD fills in the bound or the stability grace period elapses (nvd:sync / nvd:rebuild-ranges). GHSA-mismatch rows stay held back permanently unless the mismatch is cleared with nvd:cross-check-core --force.');
        $this->line("Showing {$ranges->count()} of {$total}.");

        return self::SUCCESS;
    }

    private function reason(VulnerabilityRange $range): string
    {
        if ($range->vulnerability?->ghsa_ecosystem_mismatch) {
            return 'GHSA: library, not core product';
        }

        if ($range->version_start_missing_since !== null) {
            return 'Missing lower bound';
        }

        return 'Unresolved';
    }
}
