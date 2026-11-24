<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Pos\PosAuthController;
use App\Http\Controllers\Api\V1\Pos\PosBootstrapController;
use App\Http\Controllers\Api\V1\Pos\PosCustomerController;
use App\Http\Controllers\Api\V1\Pos\PosIncomingOrderController;
use App\Http\Controllers\Api\V1\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Pos\PosSaleController;
use App\Http\Controllers\Api\V1\Pos\PosShiftController;
use App\Http\Controllers\Api\V1\Pos\PosStockController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use Illuminate\Support\Facades\Route;

// Legacy POS cashier login; disabled when LEGACY_LOGIN_ENABLED=false in favor of /api/v1/auth/login.
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [PosAuthController::class, 'login'])
        ->middleware([\App\Http\Middleware\PreventLoginWithActiveSession::class, 'throttle:pos-login'])
        ->name('auth.login');
}

Route::middleware(['auth:sanctum', 'auth.staff', 'permission:pos.access', 'throttle:pos-api'])->group(function (): void {
    Route::put('me/password', [\App\Http\Controllers\Api\V1\Admin\Auth\UserController::class, 'updateOwnPassword'])
        ->middleware('throttle:own-password')->name('me.password');
    Route::post('auth/logout', [PosAuthController::class, 'logout'])->name('auth.logout');
    Route::get('bootstrap', [PosBootstrapController::class, 'show'])->name('bootstrap');
    Route::get('treasuries/available', [PosShiftController::class, 'availableDrawers'])
        ->middleware('permission:pos.sales.create')
        ->name('treasuries.available');

    // Shift management: opening, closing, and inspecting shifts do not require
    // an active shift (F-10: close resolves its own shift so idempotent
    // retries replay the close summary instead of hitting pos.shift 403).
    Route::post('shifts/open', [PosShiftController::class, 'open'])
        ->middleware('permission:pos.sales.create')
        ->name('shifts.open');
    Route::post('shifts/close', [PosShiftController::class, 'close'])
        ->middleware('permission:pos.sales.create')
        ->name('shifts.close');
    Route::get('shifts/current', [PosShiftController::class, 'current'])->name('shifts.current');

    // Product catalog, lookup, and held carts accessible prior to shift opening.
    // Shielded with permission:pos.sales.create|inventory.view to prevent blind stocktake data leaks.
    Route::get('catalog', [PosProductController::class, 'catalog'])
        ->middleware('permission:pos.sales.create|inventory.view')
        ->name('catalog');
    Route::get('products/lookup', [PosProductController::class, 'lookup'])
        ->middleware('permission:pos.sales.create|inventory.view')
        ->name('products.lookup');
    Route::get('sales/held', [PosSaleController::class, 'held'])
        ->middleware('permission:pos.sales.create')
        ->name('sales.held');

    // Stocktake blind scanning without requiring an open cashier shift
    Route::get('stocktakes/current', [PosStockController::class, 'currentStocktake'])
        ->middleware('permission:stocktakes.view|stocktakes.update|stocktakes.approve')
        ->name('stocktakes.current');
    Route::post('stocktakes/{stocktake}/scan', [PosStockController::class, 'scan'])
        ->middleware('permission:stocktakes.update')
        ->name('stocktakes.scan');

    // Protected endpoints requiring an active shift (pos.shift middleware injects current shift).
    Route::middleware('pos.shift')->group(function (): void {
        Route::middleware('permission:orders.view')->group(function (): void {
            Route::get('incoming-orders', [PosIncomingOrderController::class, 'index'])->name('incoming-orders.index');
            Route::get('incoming-orders/events', [PosIncomingOrderController::class, 'events'])->name('incoming-orders.events');
            Route::get('incoming-orders/completed', [PosIncomingOrderController::class, 'completed'])->name('incoming-orders.completed');
            Route::get('shipping-companies', [PosIncomingOrderController::class, 'shippingCompanies'])->name('shipping-companies.index');
            Route::get('incoming-orders/{id}', [PosIncomingOrderController::class, 'show'])
                ->where('id', '[1-9][0-9]{0,17}')->name('incoming-orders.show');
            Route::post('incoming-orders/{id}/status', [PosIncomingOrderController::class, 'update'])
                ->where('id', '[1-9][0-9]{0,17}')->middleware('permission:orders.update')->name('incoming-orders.update');
            Route::post('incoming-orders/{id}/prepare', [PosIncomingOrderController::class, 'prepare'])
                ->where('id', '[1-9][0-9]{0,17}')->middleware('permission:orders.update')->name('incoming-orders.prepare');
            Route::post('incoming-orders/{id}/shipments', [PosIncomingOrderController::class, 'createShipment'])
                ->where('id', '[1-9][0-9]{0,17}')->middleware('permission:orders.update')->name('incoming-orders.shipments');
            Route::post('incoming-orders/{id}/cancel', [PosIncomingOrderController::class, 'cancel'])
                ->where('id', '[1-9][0-9]{0,17}')->middleware('permission:pos.orders.cancel')->name('incoming-orders.cancel');
            Route::post('incoming-orders/{id}/returns', [PosIncomingOrderController::class, 'createReturn'])
                ->where('id', '[1-9][0-9]{0,17}')->middleware(['permission:pos.sales.refund', 'throttle:pos-returns'])->name('incoming-orders.returns');
        });
        Route::get('shifts/current/summary', [PosShiftController::class, 'summary'])->name('shifts.current.summary');

        // POS sales, held carts, refunds, and exchanges.
        Route::get('sales/history', [PosSaleController::class, 'history'])
            ->middleware('permission:pos.sales.view|pos.sales.refund|pos.sales.create')
            ->name('sales.history');
        Route::post('sales', [PosSaleController::class, 'store'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.store');
        Route::post('sales/hold', [PosSaleController::class, 'hold'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.hold');
        Route::post('sales/quote', [PosSaleController::class, 'quote'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.quote');
        Route::get('sales/expenses', [PosSaleController::class, 'listExpenses'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.expenses.index');
        Route::post('sales/expenses', [PosSaleController::class, 'recordExpense'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.expenses');
        Route::match(['patch', 'post'], 'sales/expenses/{expense}', [PosSaleController::class, 'updateExpense'])
            ->where('expense', '[1-9][0-9]{0,17}')
            ->middleware('permission:pos.sales.create')
            ->name('sales.expenses.update');
        Route::post('sales/expenses/{expense}/void', [PosSaleController::class, 'voidExpense'])
            ->where('expense', '[1-9][0-9]{0,17}')
            ->middleware('permission:pos.sales.create')
            ->name('sales.expenses.void');
        Route::get('sales/lookup', [PosSaleController::class, 'lookupForReturn'])
            ->middleware(['permission:pos.sales.refund', 'throttle:pos-lookup'])
            ->name('sales.lookup');
        Route::post('sales/held/{held_sale}/resume', [PosSaleController::class, 'resumeHeld'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.held.resume');
        Route::delete('sales/held/{held_sale}', [PosSaleController::class, 'deleteHeld'])
            ->middleware('permission:pos.sales.create')
            ->name('sales.held.delete');
        Route::post('sales/{sale}/return', [PosSaleController::class, 'processReturn'])
            ->middleware(['permission:pos.sales.refund', 'throttle:pos-returns'])->name('sales.return');
        Route::post('sales/{sale}/exchange', [PosSaleController::class, 'processExchange'])
            ->middleware(['permission:pos.sales.refund', 'throttle:pos-returns'])->name('sales.exchange');

        // Restrict customer lookup and creation to an active shift.
        Route::get('customers', [PosCustomerController::class, 'index'])
            ->middleware(['permission:pos.sales.create', 'throttle:pos-customers'])->name('customers.index');
        Route::post('customers', [PosCustomerController::class, 'store'])
            ->middleware(['permission:pos.sales.create', 'throttle:pos-customers'])->name('customers.store');

        // Branch inventory, transfers, and barcode scanning.
        Route::get('stock', [PosStockController::class, 'index'])
            ->middleware('permission:pos.sales.create|inventory.view')
            ->name('stock.index');
        Route::get('transfers/incoming', [PosStockController::class, 'incoming'])
            ->middleware('permission:transfers.view|transfers.receive')
            ->name('transfers.incoming');
        Route::get('transfer-sources', [PosStockController::class, 'sources'])
            ->middleware('permission:transfers.view|transfers.create')
            ->name('transfer-sources.index');
        Route::post('transfer-requests', [PosStockController::class, 'requestTransfer'])
            ->middleware('permission:transfers.create')
            ->name('transfer-requests.store');
        Route::post('transfers/{transfer}/receive', [PosStockController::class, 'receiveTransfer'])
            ->middleware('permission:transfers.receive')
            ->name('transfers.receive');
    });

    // Staff notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
});
