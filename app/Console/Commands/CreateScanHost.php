<?php

namespace App\Console\Commands;

use App\Models\ScanHost;
use Illuminate\Console\Command;

class CreateScanHost extends Command
{
    protected $signature = 'scan-host:create {hostname : Identifier for the machine running the scanner} {--rotate : Revoke existing tokens and issue a fresh one}';

    protected $description = 'Register a scan host and issue a bearer token for the scanner API';

    public function handle(): int
    {
        $hostname = (string) $this->argument('hostname');

        $host = ScanHost::firstOrCreate(['hostname' => $hostname]);

        if ($host->wasRecentlyCreated) {
            $this->info("Registered scan host \"{$hostname}\".");
        } else {
            $this->info("Scan host \"{$hostname}\" already exists.");

            if (! $this->option('rotate') && $host->tokens()->exists()) {
                $this->warn('It already has a token. Re-run with --rotate to revoke and reissue.');

                return self::SUCCESS;
            }
        }

        if ($this->option('rotate')) {
            $revoked = $host->tokens()->delete();
            $this->warn("Revoked {$revoked} existing token(s).");
        }

        if (! $host->is_active) {
            $host->update(['is_active' => true]);
            $this->warn('Host was inactive and has been reactivated.');
        }

        $this->newLine();
        $this->line('SCAN_TOKEN='.$host->createToken('scanner')->plainTextToken);
        $this->newLine();
        $this->warn('Copy this now — it cannot be shown again.');

        return self::SUCCESS;
    }
}
