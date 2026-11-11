<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Controllers\Api\V1\Supplier\AccountController;
use App\Http\Controllers\Api\V1\Supplier\AuthController;
use App\Http\Controllers\Api\V1\Supplier\DashboardController;
use App\Http\Controllers\Api\V1\Supplier\DeliveryController;
use App\Http\Controllers\Api\V1\Supplier\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

// Enforce numeric route patterns to return 404 rather than casting exceptions.
Route::pattern('purchase_order', '[0-9]{1,18}');
Route::pattern('delivery', '[0-9]{1,18}');

// Legacy supplier authentication; disabled when LEGACY_LOGIN_ENABLED=false in favor of /api/v1/auth/login.
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:supplier-login')
        ->name('auth.login');
}

// Supplier portal endpoints scoped strictly to the authenticated supplier.
Route::middleware(['auth:sanctum', 'auth.supplier', 'throttle:supplier-portal'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('purchase-orders/{purchase_order}/accept', [PurchaseOrderController::class, 'accept'])->name('purchase-orders.accept');
    Route::post('purchase-orders/{purchase_order}/request-change', [PurchaseOrderController::class, 'requestChange'])->name('purchase-orders.request-change');

    Route::post('purchase-orders/{purchase_order}/deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');

    Route::get('supplier-invoices/{invoice}/attachment', [AttachmentController::class, 'invoice'])->where('invoice', '[0-9]{1,18}')->name('supplier-invoices.attachment');
    Route::get('deliveries/{delivery}/invoice', [AttachmentController::class, 'delivery'])->where('delivery', '[0-9]{1,18}')->name('deliveries.invoice');
    Route::get('statement', [AccountController::class, 'statement'])->name('statement');
});
