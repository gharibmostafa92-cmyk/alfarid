<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhook\CustomerWhatsAppWebhookController;
use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Webhook\ShippingWebhookController;
use App\Http\Controllers\Api\V1\Webhook\WhatsAppInboundController;
use Illuminate\Support\Facades\Route;

// External inbound webhooks (payment gateways & shipping carriers): authenticated via HMAC signatures and logged to webhook_logs.
Route::middleware('throttle:webhooks')->group(function (): void {
    // Constrain gateway/company slug length to 40 chars matching provider database column width, returning 404 on overflow.
    Route::post('payment/{gateway}', [PaymentWebhookController::class, 'handle'])
        ->where('gateway', '[a-z0-9_-]{1,40}')->name('payment');
    Route::post('shipping/{company}', [ShippingWebhookController::class, 'handle'])
        ->where('company', '[A-Za-z0-9_-]{1,40}')->name('shipping');
    Route::post('whatsapp/inbound', [WhatsAppInboundController::class, 'handle'])->name('whatsapp.inbound');
    Route::post('whatsapp/customers', [CustomerWhatsAppWebhookController::class, 'handle'])->name('whatsapp.customers');
});
