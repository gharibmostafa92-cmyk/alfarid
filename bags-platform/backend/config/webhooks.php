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
        'paymob' => [
            'secret' => env('PAYMOB_WEBHOOK_SECRET'),
            'signature_header' => env('PAYMOB_SIGNATURE_HEADER', 'X-Webhook-Signature'),
        ],
        'stripe' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'signature_header' => env('STRIPE_SIGNATURE_HEADER', 'X-Webhook-Signature'),
        ],
        'moyasar' => [
            'secret' => env('MOYASAR_WEBHOOK_SECRET'),
            'signature_header' => env('MOYASAR_SIGNATURE_HEADER', 'X-Webhook-Signature'),
        ],
    ],

    'shipping' => [
        'default_secret' => env('SHIPPING_WEBHOOK_SECRET'),
        'signature_header' => env('SHIPPING_SIGNATURE_HEADER', 'X-Webhook-Signature'),
    ],
];
