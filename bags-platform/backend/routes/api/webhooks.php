<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhook\CustomerWhatsAppWebhookController;
use App\Http\Controllers\Api\V1\Webhook\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Webhook\ShippingWebhookController;
use App\Http\Controllers\Api\V1\Webhook\WhatsAppInboundController;
use Illuminate\Support\Facades\Route;

// External inbound webhooks (payment gateways & shipping carriers): authenticated via HMAC signatures and logged to webhook_logs.
// Each purpose owns its throttle bucket so one busy sender cannot starve the others.
// Constrain gateway/company slug length to 40 chars matching provider database column width, returning 404 on overflow.
Route::post('payment/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->where('gateway', '[a-z0-9_-]{1,40}')->middleware('throttle:webhooks-payment')->name('payment');
Route::post('shipping/{company}', [ShippingWebhookController::class, 'handle'])
    ->where('company', '[A-Za-z0-9_-]{1,40}')->middleware('throttle:webhooks-shipping')->name('shipping');
Route::post('whatsapp/inbound', [WhatsAppInboundController::class, 'handle'])->middleware('throttle:webhooks-whatsapp')->name('whatsapp.inbound');
Route::post('whatsapp/customers', [CustomerWhatsAppWebhookController::class, 'handle'])->middleware('throttle:webhooks-whatsapp')->name('whatsapp.customers');
