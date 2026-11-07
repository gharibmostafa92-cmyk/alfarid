<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| External Webhook Configuration
|--------------------------------------------------------------------------
| HMAC verification keys and signature headers for payment gateways and carriers.
*/

return [
    'payment' => [
        // Driver selects gateway-specific verification algorithms
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
        // Carrier secrets are stored per company in api_credentials.webhook_secret; no global shared secret
        'signature_header' => env('SHIPPING_SIGNATURE_HEADER', 'X-Webhook-Signature'),
    ],
];
