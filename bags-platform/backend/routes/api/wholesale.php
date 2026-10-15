<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Wholesale\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    // التسجيل ورفع المستندات لتاجر الجملة
    Route::post('register', [RegisterController::class, 'register'])->name('register');

    // متابعة حالة الطلب (يتطلب توكن عميل فقط، ولا يتطلب wholesale.approved)
    // ملحوظة مرحلة 5: بلا auth.customer هنا — تلك البوابة ترفض الحسابات
    // المرفوضة، وهم تحديدًا من يحتاج رؤية سبب الرفض ومستنداته المرفوضة.
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('application-status', [RegisterController::class, 'applicationStatus'])->name('application-status');
    });
});
