<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nvd:sync')
    ->hourly()
    ->withoutOverlapping(expiresAt: 6 * 60)
    ->runInBackground();

Schedule::command('nvd:cross-check-core')
    ->daily()
    ->withoutOverlapping(expiresAt: 6 * 60)
    ->runInBackground();

Schedule::command('nvd:promote-unmatched')
    ->daily()
    ->withoutOverlapping(expiresAt: 6 * 60)
    ->runInBackground();
