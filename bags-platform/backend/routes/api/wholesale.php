<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Wholesale\RegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| روابط قناة الجملة (api/v1/wholesale)
|--------------------------------------------------------------------------
| التسجيل يصدر توكنًا فورًا لحساب pending حتى يتابع التاجر حالة طلبه.
| متابعة الحالة بـ auth:sanctum فقط (بلا wholesale.approved وبلا بوابة
| ترفض المرفوضين) — التفاصيل في RegisterController::applicationStatus.
*/

Route::prefix('auth')->group(function (): void {
    Route::post('register', [RegisterController::class, 'register']);
    Route::middleware('auth:sanctum')->get('application-status', [RegisterController::class, 'applicationStatus']);
});
