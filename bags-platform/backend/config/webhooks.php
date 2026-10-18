<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| إعدادات الويب هوكس الخارجية
|--------------------------------------------------------------------------
| بوابات الدفع: سر HMAC لكل بوابة من متغيرات البيئة. التحقق الافتراضي:
| توقيع HMAC-SHA256 لنص الطلب الخام في هيدر X-Webhook-Signature
| (تُضبط الأنماط الخاصة بكل بوابة عند ربطها فعليًا).
| شركات الشحن: السر من api_credentials.webhook_secret لكل شركة،
| مع سر افتراضي احتياطي من البيئة.
*/

return [
    'payment' => [
        // نمط تحقق لكل بوابة (F-06): driver يختار خوارزمية البوابة الحقيقية
        'paymob' => [
            'driver' => 'paymob',
            'secret' => env('PAYMOB_WEBHOOK_SECRET'),
            'signature_header' => env('PAYMOB_SIGNATURE_HEADER', 'X-Webhook-Signature'),
            'currency' => env('PAYMOB_CURRENCY', 'EGP'),
            'amount_divisor' => (int) env('PAYMOB_AMOUNT_DIVISOR', 100),
            'require_amount' => (bool) env('PAYMOB_REQUIRE_AMOUNT', true),
        ],
        'stripe' => [
            'driver' => 'stripe',
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'signature_header' => env('STRIPE_SIGNATURE_HEADER', 'Stripe-Signature'),
            'currency' => env('STRIPE_CURRENCY', 'EGP'),
            'amount_divisor' => (int) env('STRIPE_AMOUNT_DIVISOR', 100),
            'require_amount' => (bool) env('STRIPE_REQUIRE_AMOUNT', true),
        ],
        'moyasar' => [
            'driver' => 'moyasar',
            'secret' => env('MOYASAR_WEBHOOK_SECRET'),
            'signature_header' => env('MOYASAR_SIGNATURE_HEADER', 'X-Webhook-Signature'),
            'currency' => env('MOYASAR_CURRENCY', 'SAR'),
            'amount_divisor' => (int) env('MOYASAR_AMOUNT_DIVISOR', 100),
            'require_amount' => (bool) env('MOYASAR_REQUIRE_AMOUNT', true),
        ],
    ],

    'shipping' => [
        // سر التوقيع لكل شركة من api_credentials.webhook_secret حصرًا (M10):
        // لا سر افتراضي مشترك ولا رجوع لمفتاح API.
        'signature_header' => env('SHIPPING_SIGNATURE_HEADER', 'X-Webhook-Signature'),
    ],
];
