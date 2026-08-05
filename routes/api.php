<?php

use App\Http\Controllers\Api\VulnCheckController;
use App\Http\Middleware\TouchScanHostLastSeen;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:vuln-check', TouchScanHostLastSeen::class])
    ->post('/vulns/check', [VulnCheckController::class, 'check'])
    ->name('api.vulns.check');
