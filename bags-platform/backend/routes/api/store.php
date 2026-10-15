<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Store\AuthController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\HomeController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| روابط المتجر القطاعي (api/v1/store)
|--------------------------------------------------------------------------
| المصادقة بتوكنات Sanctum للعملاء (customers)، وبوابة auth.customer تضمن
| أن التوكن لعميل فعلي غير موقوف/مرفوض.
| روابط التصفح عامة: الزائر يتصفح بلا توكن، وحامل التوكن تُحسب أسعاره
| الشخصية عبر auth('sanctum')->user() داخل الموارد (خصم مجموعة/سعر خاص).
*/

Route::get('home', [HomeController::class, 'index']);
Route::get('categories', [CatalogController::class, 'categories']);
Route::get('brands', [CatalogController::class, 'brands']);
Route::get('cities', [CatalogController::class, 'cities']);
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{slug}', [ProductController::class, 'show']);
Route::get('products/{slug}/similar', [ProductController::class, 'similar']);

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);

    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});
