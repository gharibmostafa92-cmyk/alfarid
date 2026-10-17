<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Store\AuthController;
use App\Http\Controllers\Api\V1\Store\CartController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\HomeController;
use App\Http\Controllers\Api\V1\Store\OrderController as StoreOrderController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use App\Http\Middleware\ProtectStorefrontCaching;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة للعميل القطاعي
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:customer-registration')->name('register');
    Route::post('phone-verification/send', [AuthController::class, 'sendPhoneVerification'])->middleware('throttle:customer-phone-send')->name('phone-verification.send');
    Route::post('phone-verification/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:customer-phone-verify')->name('phone-verification.verify');
    // حد محاولات الدخول (مرحلة 5) محفوظ فوق الشكل المطلوب: حماية من التخمين
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login')->name('login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:customer-password-reset')->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:customer-reset-confirm')->name('reset-password');

    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// مسارات تصفح المتجر (متاحة للزوار والعملاء المسجلين)
Route::middleware(['throttle:store-catalog', ProtectStorefrontCaching::class])->group(function (): void {
    Route::get('home', [HomeController::class, 'index'])->name('home');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('brands', [CatalogController::class, 'brands'])->name('brands');
    Route::get('cities', [CatalogController::class, 'cities'])->name('cities');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{slug}/similar', [ProductController::class, 'similar'])->name('products.similar');
});

// مسارات السلة وإتمام الطلب (تعمل للزائر بـ X-Cart-Session وللعميل المسجل بـ Sanctum Token)
Route::prefix('cart')->name('cart.')->group(function (): void {
    Route::get('items', [CartController::class, 'index'])->name('items.index');
    Route::post('items', [CartController::class, 'store'])->name('items.store');
    Route::put('items/{item}', [CartController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [CartController::class, 'destroy'])->name('items.destroy');
    Route::post('coupon', [CartController::class, 'applyCoupon'])->name('coupon.apply');
    Route::delete('coupon', [CartController::class, 'removeCoupon'])->name('coupon.remove');
});

Route::prefix('checkout')->name('checkout.')->group(function (): void {
    Route::post('shipping-options', [CheckoutController::class, 'shippingOptions'])->name('shipping-options');
    Route::post('/', [CheckoutController::class, 'store'])->name('store');
});

// تتبع الطلب للزائر والعميل
Route::get('orders/{number}/track', [StoreOrderController::class, 'track'])->name('orders.track');

// مسارات حساب العميل المسجل فقط
Route::middleware(['auth:sanctum', 'auth.customer'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('orders', [StoreOrderController::class, 'index'])->name('orders.index');
    Route::post('orders/{order}/cancel', [StoreOrderController::class, 'cancel'])->name('orders.cancel');
});
