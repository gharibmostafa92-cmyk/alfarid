<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Webhook\ShippingWebhookController;
use Illuminate\Support\Facades\Route;

// روابط خارجية (بوابات دفع وشركات شحن): لا مصادقة Sanctum —
// التحقق من التوقيع الرقمي HMAC داخل كل متحكم، وكل طلب يُسجل في webhook_logs.
Route::middleware('throttle:webhooks')->group(function (): void {
    // قيود طول/نمط على المعاملات: اسم بوابة/شركة طويل كان يتجاوز عمود
    // provider في سجل الويب هوك فيحوّل الطلب لـ 500 بدل 404 نظيف (F-07)
    Route::post('payment/{gateway}', [PaymentWebhookController::class, 'handle'])
        ->where('gateway', '[a-z0-9_-]{1,40}')->name('payment');
    Route::post('shipping/{company}', [ShippingWebhookController::class, 'handle'])
        ->where('company', '[A-Za-z0-9_-]{1,40}')->name('shipping');
});
