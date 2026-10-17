<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Webhook\ShippingWebhookController;
use Illuminate\Support\Facades\Route;

// روابط خارجية (بوابات دفع وشركات شحن): لا مصادقة Sanctum —
// التحقق من التوقيع الرقمي HMAC داخل كل متحكم، وكل طلب يُسجل في webhook_logs.
Route::post('payment/{gateway}', [PaymentWebhookController::class, 'handle'])->name('payment');
Route::post('shipping/{company}', [ShippingWebhookController::class, 'handle'])->name('shipping');
