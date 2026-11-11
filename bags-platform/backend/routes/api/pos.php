<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Pos\PosAuthController;
use App\Http\Controllers\Api\V1\Pos\PosBootstrapController;
use App\Http\Controllers\Api\V1\Pos\PosCustomerController;
use App\Http\Controllers\Api\V1\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Pos\PosSaleController;
use App\Http\Controllers\Api\V1\Pos\PosShiftController;
use App\Http\Controllers\Api\V1\Pos\PosStockController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use Illuminate\Support\Facades\Route;

// Numeric route identifier patterns
Route::pattern('sale', '[0-9]{1,18}');
Route::pattern('transfer', '[0-9]{1,18}');
Route::pattern('stocktake', '[0-9]{1,18}');
Route::pattern('held_sale', '[0-9]{1,18}');

// Legacy POS cashier login; disabled when LEGACY_LOGIN_ENABLED=false in favor of /api/v1/auth/login.
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [PosAuthController::class, 'login'])
        ->middleware('throttle:pos-login')
        ->name('auth.login');
}

Route::middleware(['auth:sanctum', 'auth.staff', 'permission:pos.access'])->group(function (): void {
    Route::post('auth/logout', [PosAuthController::class, 'logout'])->name('auth.logout');
    Route::get('bootstrap', [PosBootstrapController::class, 'show'])->name('bootstrap');
    Route::get('treasuries/available', [PosShiftController::class, 'availableDrawers'])->name('treasuries.available');

    // Shift management: opening and inspecting shifts do not require an active shift.
    Route::post('shifts/open', [PosShiftController::class, 'open'])->name('shifts.open');
    Route::get('shifts/current', [PosShiftController::class, 'current'])->name('shifts.current');

    // Product catalog, lookup, customers, and held carts accessible prior to shift opening.
    Route::get('catalog', [PosProductController::class, 'catalog'])->name('catalog');
    Route::get('customers', [PosCustomerController::class, 'index'])->name('customers.index');
    Route::get('products/lookup', [PosProductController::class, 'lookup'])->name('products.lookup');
    Route::get('sales/held', [PosSaleController::class, 'held'])->name('sales.held');

    // Protected endpoints requiring an active shift (pos.shift middleware injects current shift).
    Route::middleware('pos.shift')->group(function (): void {
        Route::get('shifts/current/summary', [PosShiftController::class, 'summary'])->name('shifts.current.summary');
        Route::post('shifts/close', [PosShiftController::class, 'close'])->name('shifts.close');

        // POS sales, held carts, refunds, and exchanges.
        Route::post('sales', [PosSaleController::class, 'store'])->name('sales.store');
        Route::post('sales/hold', [PosSaleController::class, 'hold'])->name('sales.hold');
        Route::post('sales/quote', [PosSaleController::class, 'quote'])->name('sales.quote');
        Route::get('sales/lookup', [PosSaleController::class, 'lookupForReturn'])->middleware('permission:pos.sales.refund')->name('sales.lookup');
        Route::post('sales/held/{held_sale}/resume', [PosSaleController::class, 'resumeHeld'])->name('sales.held.resume');
        Route::delete('sales/held/{held_sale}', [PosSaleController::class, 'deleteHeld'])->name('sales.held.delete');
        Route::post('sales/{sale}/return', [PosSaleController::class, 'processReturn'])->middleware('permission:pos.sales.refund')->name('sales.return');
        Route::post('sales/{sale}/exchange', [PosSaleController::class, 'processExchange'])->middleware('permission:pos.sales.refund')->name('sales.exchange');

        // Customers quick creation during active shift.
        Route::post('customers', [PosCustomerController::class, 'store'])->name('customers.store');

        // Branch inventory, transfers, and barcode scanning.
        Route::get('stock', [PosStockController::class, 'index'])->name('stock.index');
        Route::get('transfers/incoming', [PosStockController::class, 'incoming'])->name('transfers.incoming');
        Route::get('transfer-sources', [PosStockController::class, 'sources'])->name('transfer-sources.index');
        Route::post('transfer-requests', [PosStockController::class, 'requestTransfer'])->name('transfer-requests.store');
        Route::post('transfers/{transfer}/receive', [PosStockController::class, 'receiveTransfer'])->name('transfers.receive');
        Route::get('stocktakes/current', [PosStockController::class, 'currentStocktake'])->name('stocktakes.current');
        Route::post('stocktakes/{stocktake}/scan', [PosStockController::class, 'scan'])->name('stocktakes.scan');
    });

    // Staff notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
});
