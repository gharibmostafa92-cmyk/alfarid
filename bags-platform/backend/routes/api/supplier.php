<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Supplier\AccountController;
use App\Http\Controllers\Api\V1\Supplier\AuthController;
use App\Http\Controllers\Api\V1\Supplier\DashboardController;
use App\Http\Controllers\Api\V1\Supplier\DeliveryController;
use App\Http\Controllers\Api\V1\Supplier\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

// معرّفات رقمية فقط — غير الرقمي 404 بدل خطأ قاعدة بيانات
Route::pattern('purchase_order', '[0-9]{1,18}');
Route::pattern('delivery', '[0-9]{1,18}');

// دخول موظفي المورد (supplier_users) — عام مع حد محاولات
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

// بوابة المورد: كل المسارات التالية مقيدة بمورد صاحب التوكن حصرًا
Route::middleware(['auth:sanctum', 'auth.supplier'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('purchase-orders/{purchase_order}/accept', [PurchaseOrderController::class, 'accept'])->name('purchase-orders.accept');
    Route::post('purchase-orders/{purchase_order}/request-change', [PurchaseOrderController::class, 'requestChange'])->name('purchase-orders.request-change');

    Route::post('purchase-orders/{purchase_order}/deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');

    Route::get('statement', [AccountController::class, 'statement'])->name('statement');
});
