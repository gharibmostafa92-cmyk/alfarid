<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Wholesale\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    // التسجيل ورفع المستندات لتاجر الجملة
    Route::post('register', [RegisterController::class, 'register'])->name('register');

    // متابعة حالة الطلب (يتطلب توكن عميل فقط، ولا يتطلب wholesale.approved)
    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::get('application-status', [RegisterController::class, 'applicationStatus'])->name('application-status');
    });
});
