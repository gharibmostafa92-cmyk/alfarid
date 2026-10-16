<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Store\AuthController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\HomeController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use Illuminate\Support\Facades\Route;

// مسارات المصادقة للعميل القطاعي
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    // حد محاولات الدخول (مرحلة 5) محفوظ فوق الشكل المطلوب: حماية من التخمين
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login')->name('login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:customer-password-reset')->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:customer-password-reset')->name('reset-password');

    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// مسارات تصفح المتجر (متاحة للزوار والعملاء المسجلين)
Route::get('home', [HomeController::class, 'index'])->name('home');
Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
Route::get('brands', [CatalogController::class, 'brands'])->name('brands');
Route::get('cities', [CatalogController::class, 'cities'])->name('cities');

Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('products/{slug}/similar', [ProductController::class, 'similar'])->name('products.similar');
