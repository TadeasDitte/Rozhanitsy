<?php

namespace App\Console\Commands;

use App\Models\UnmatchedLookup;
use Illuminate\Console\Command;

class ShowUnmatchedCpe extends Command
{
    protected $signature = 'nvd:unmatched {--limit=50 : Maximum rows to display} {--min-hits=1 : Only show pairs seen at least this many times}';

    protected $description = 'List vendor/product pairs the live check could not resolve, most requested first';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $minHits = max(1, (int) $this->option('min-hits'));

        $lookups = UnmatchedLookup::query()
            ->where('hit_count', '>=', $minHits)
            ->orderByDesc('hit_count')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();

        if ($lookups->isEmpty()) {
            $this->info('No unmatched CPE lookups recorded'.($minHits > 1 ? " with at least {$minHits} hits." : '.'));

            return self::SUCCESS;
        }

        $this->table(
            ['CPE Vendor', 'CPE Product', 'Hits', 'First Seen', 'Last Seen'],
            $lookups->map(fn (UnmatchedLookup $lookup): array => [
                $lookup->cpe_vendor,
                $lookup->cpe_product,
                $lookup->hit_count,
                $lookup->first_seen_at->toDateTimeString(),
                $lookup->last_seen_at->toDateTimeString(),
            ])->all(),
        );

        $this->newLine();
        $this->line("Showing {$lookups->count()} of ".UnmatchedLookup::where('hit_count', '>=', $minHits)->count().' unmatched pairs.');

        return self::SUCCESS;
    }
}
