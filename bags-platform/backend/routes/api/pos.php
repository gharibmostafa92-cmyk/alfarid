<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Pos\PosAuthController;
use App\Http\Controllers\Api\V1\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Pos\PosSaleController;
use App\Http\Controllers\Api\V1\Pos\PosShiftController;
use App\Http\Controllers\Api\V1\Pos\PosStockController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use Illuminate\Support\Facades\Route;

// معرّفات رقمية فقط
Route::pattern('sale', '[0-9]{1,18}');
Route::pattern('transfer', '[0-9]{1,18}');
Route::pattern('stocktake', '[0-9]{1,18}');
Route::pattern('held_sale', '[0-9]{1,18}');

// تسجيل دخول الكاشير / موظف الفرع مع اختيار الفرع (legacy — M5: يُعطَّل مع
// LEGACY_LOGIN_ENABLED=false لصالح /api/v1/auth/login).
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [PosAuthController::class, 'login'])
        ->middleware('throttle:pos-login')
        ->name('auth.login');
}

Route::middleware(['auth:sanctum', 'auth.staff', 'permission:pos.access'])->group(function (): void {
    Route::post('auth/logout', [PosAuthController::class, 'logout'])->name('auth.logout');

    // الورديات: الفتح والعرض لا يحتاجان وردية قائمة، والإغلاق داخل مجموعة الوردية بالأسفل
    Route::post('shifts/open', [PosShiftController::class, 'open'])->name('shifts.open');
    Route::get('shifts/current', [PosShiftController::class, 'current'])->name('shifts.current');

    // البحث عن المنتجات والفواتير المعلقة (متاحة للكاشير بلا وردية مفتوحة)
    Route::get('products/lookup', [PosProductController::class, 'lookup'])->name('products.lookup');
    Route::get('sales/held', [PosSaleController::class, 'held'])->name('sales.held');

    // ما يلي يحتاج وردية مفتوحة (pos.shift يحقن الوردية الحالية في الطلب)
    Route::middleware('pos.shift')->group(function (): void {
        Route::post('shifts/close', [PosShiftController::class, 'close'])->name('shifts.close');

        // المبيعات والفواتير المعلقة والمرتجعات والاستبدالات
        Route::post('sales', [PosSaleController::class, 'store'])->name('sales.store');
        Route::post('sales/hold', [PosSaleController::class, 'hold'])->name('sales.hold');
        Route::post('sales/{sale}/return', [PosSaleController::class, 'processReturn'])->middleware('permission:pos.sales.refund')->name('sales.return');
        Route::post('sales/{sale}/exchange', [PosSaleController::class, 'processExchange'])->middleware('permission:pos.sales.refund')->name('sales.exchange');

        // مخزون الفرع والتحويلات والجرد بالباركود
        Route::get('stock', [PosStockController::class, 'index'])->name('stock.index');
        Route::post('transfer-requests', [PosStockController::class, 'requestTransfer'])->name('transfer-requests.store');
        Route::post('transfers/{transfer}/receive', [PosStockController::class, 'receiveTransfer'])->name('transfers.receive');
        Route::post('stocktakes/{stocktake}/scan', [PosStockController::class, 'scan'])->name('stocktakes.scan');
    });

    // الإشعارات
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
});
