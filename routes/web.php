<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ScanTokenController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('tokens', [ScanTokenController::class, 'index'])->name('tokens.index');
    Route::post('tokens', [ScanTokenController::class, 'store'])->name('tokens.store');
    Route::post('tokens/{scanHost}/regenerate', [ScanTokenController::class, 'regenerate'])->name('tokens.regenerate');
    Route::delete('tokens/{scanHost}', [ScanTokenController::class, 'destroy'])->name('tokens.destroy');
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users/{user}/admin', [UserController::class, 'promote'])->name('users.promote');
    Route::delete('users/{user}/admin', [UserController::class, 'demote'])->name('users.demote');
    Route::delete('scan-hosts/{scanHost}/token', [UserController::class, 'revoke'])->name('scan-hosts.revoke');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
});

require __DIR__.'/settings.php';
