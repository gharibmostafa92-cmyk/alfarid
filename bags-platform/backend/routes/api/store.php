<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Controllers\Api\V1\Store\AccountController;
use App\Http\Controllers\Api\V1\Store\AuthController;
use App\Http\Controllers\Api\V1\Store\BrandingController;
use App\Http\Controllers\Api\V1\Store\CartController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\HomeController;
use App\Http\Controllers\Api\V1\Store\OrderController as StoreOrderController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use App\Http\Controllers\Api\V1\Store\ReturnController;
use App\Http\Controllers\Api\V1\Store\WalletController;
use App\Http\Middleware\ProtectStorefrontCaching;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة للعميل القطاعي
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:customer-registration')->name('register');
    Route::post('phone-verification/send', [AuthController::class, 'sendPhoneVerification'])->middleware('throttle:customer-phone-send')->name('phone-verification.send');
    Route::post('phone-verification/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:customer-phone-verify')->name('phone-verification.verify');
    // حد محاولات الدخول (مرحلة 5) محفوظ فوق الشكل المطلوب: حماية من التخمين
    // M5: مسار legacy — يُعطَّل مع LEGACY_LOGIN_ENABLED=false لصالح /auth/login.
    if (config('auth.legacy_login_enabled', false)) {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login')->name('login');
    }
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:customer-password-reset')->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:customer-reset-confirm')->name('reset-password');

    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    });
});

Route::get('branding', [BrandingController::class, 'show'])->middleware('throttle:store-branding')->name('branding');

// مسارات تصفح المتجر (متاحة للزوار والعملاء المسجلين)
Route::middleware(['throttle:store-catalog', ProtectStorefrontCaching::class])->group(function (): void {
    Route::get('home', [HomeController::class, 'index'])->name('home');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('brands', [CatalogController::class, 'brands'])->name('brands');
    Route::get('cities', [CatalogController::class, 'cities'])->name('cities');

    Route::get('catalog/filters', [CatalogController::class, 'filters'])->name('catalog.filters');
    Route::get('showcase', [ProductController::class, 'showcase'])->name('showcase');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{slug}/similar', [ProductController::class, 'similar'])->name('products.similar');
});

// معرّفات بنود السلة وطلبات الحساب رقمية فقط: غير الرقمي 404 لا 500 (M7)
Route::pattern('item', '[0-9]{1,18}');
Route::pattern('order', '[0-9]{1,18}');

// مسارات السلة وإتمام الطلب (تعمل للزائر بـ X-Cart-Session وللعميل المسجل بـ Sanctum Token)
Route::middleware('throttle:cart')->prefix('cart')->name('cart.')->group(function (): void {
    Route::get('items', [CartController::class, 'index'])->name('items.index');
    Route::post('items', [CartController::class, 'store'])->name('items.store');
    Route::delete('items', [CartController::class, 'clear'])->name('items.clear');
    Route::put('items/{item}', [CartController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [CartController::class, 'destroy'])->name('items.destroy');
    Route::post('coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon')->name('coupon.apply');
    Route::delete('coupon', [CartController::class, 'removeCoupon'])->name('coupon.remove');
});

Route::middleware('throttle:checkout')->prefix('checkout')->name('checkout.')->group(function (): void {
    Route::post('shipping-options', [CheckoutController::class, 'shippingOptions'])->name('shipping-options');
    Route::post('/', [CheckoutController::class, 'store'])->name('store');
});

// تتبع الطلب للزائر والعميل — حد صارم ضد التعداد (M5)
Route::get('orders/{number}/track', [StoreOrderController::class, 'track'])
    ->middleware('throttle:order-tracking')->name('orders.track');

// مسارات حساب العميل المسجل فقط
Route::middleware(['auth:sanctum', 'auth.customer'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('addresses', [AccountController::class, 'addresses'])->name('addresses.index');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::get('orders', [StoreOrderController::class, 'index'])->name('orders.index');
    Route::post('orders/{order}/cancel', [StoreOrderController::class, 'cancel'])->name('orders.cancel');
    // المرحلة 9: تقديم طلب إرجاع لطلب مُسلَّم يخص العميل
    Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/{return_request}', [ReturnController::class, 'show'])->whereNumber('return_request')->name('returns.show');
    Route::post('returns', [ReturnController::class, 'store'])->middleware('throttle:10,60')->name('returns.store');
});
