<?php

use App\Http\Controllers\Api\VulnCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:vuln-check'])
    ->post('/vulns/check', [VulnCheckController::class, 'check'])
    ->name('api.vulns.check');
