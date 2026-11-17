<?php

use App\Http\Controllers\Api\V1\Auth\UnifiedAuthController;
use Illuminate\Support\Facades\Route;

Route::post('login', [UnifiedAuthController::class, 'login'])
    ->middleware('throttle:unified-login')->name('login');

Route::post('handoff/exchange', [UnifiedAuthController::class, 'exchange'])
    ->middleware('throttle:unified-handoff')->name('handoff.exchange');

Route::middleware(['auth:sanctum', 'unified.active'])->group(function (): void {
    Route::get('me', [UnifiedAuthController::class, 'me'])->name('me');
});

// Session revocation must always succeed for any bearer of a valid token,
// even when the account itself is suspended or deactivated.
Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::post('logout', [UnifiedAuthController::class, 'logout'])->name('logout');
});
