<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Store\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| روابط المتجر القطاعي (api/v1/store)
|--------------------------------------------------------------------------
| المصادقة بتوكنات Sanctum للعملاء (customers)، وبوابة auth.customer تضمن
| أن التوكن لعميل فعلي غير موقوف/مرفوض.
*/

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);

    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});
