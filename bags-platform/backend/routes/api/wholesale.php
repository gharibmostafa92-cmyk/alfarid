<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Wholesale\PaymentNoticeController;
use App\Http\Controllers\Api\V1\Wholesale\QuotationController;
use App\Http\Controllers\Api\V1\Wholesale\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    // التسجيل ورفع المستندات لتاجر الجملة
    Route::post('register', [RegisterController::class, 'register'])->middleware('throttle:customer-registration')->name('register');

    // متابعة حالة الطلب (يتطلب توكن عميل فقط، ولا يتطلب wholesale.approved)
    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::get('application-status', [RegisterController::class, 'applicationStatus'])->name('application-status');
    });
});

// بوابة الجملة المتقدمة (المرحلة 12): الحماية الثلاثية إلزامية — لا يصل أي عميل
// قطاعي أو تاجر قيد المراجعة إلى أسعار الجملة أو شرائح الكميات أو السداد الآجل.
Route::middleware(['auth:sanctum', 'auth.customer', 'wholesale.approved'])->group(function (): void {
    // عروض الأسعار
    Route::apiResource('quotations', QuotationController::class)->parameters(['quotations' => 'quotation']);
    Route::post('quotations/{quotation}/submit', [QuotationController::class, 'submit'])->whereNumber('quotation')->name('quotations.submit');
    Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->whereNumber('quotation')->name('quotations.accept');
    Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->whereNumber('quotation')->name('quotations.reject');

    // إشعارات السداد البنكي والمديونية الآجلة
    Route::get('payment-notices/outstanding', [PaymentNoticeController::class, 'outstanding'])->name('payment-notices.outstanding');
    Route::apiResource('payment-notices', PaymentNoticeController::class)
        ->parameters(['payment-notices' => 'payment_notice'])
        ->only(['index', 'store', 'show']);
    Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');
});
