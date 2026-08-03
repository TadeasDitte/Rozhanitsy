<?php

use App\Http\Controllers\Auth\AuthentikController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest:web'])->group(function () {
    Route::get('auth/redirect', [AuthentikController::class, 'redirect'])->name('authentik.redirect');
    Route::get('auth/callback', [AuthentikController::class, 'callback'])->name('authentik.callback');
});
