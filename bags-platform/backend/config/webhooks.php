<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| External Webhook Configuration
|--------------------------------------------------------------------------
| HMAC verification keys and signature headers for payment gateways and carriers.
*/

return [
    // H3/H4: per-IP outer ceilings keep provider retries from exhausting the
    // shared bucket, while the inner bucket stays isolated per provider/route.
    'rate_limits' => [
        'payment_ip_per_minute' => (int) env('WEBHOOK_PAYMENT_IP_PER_MINUTE', 240),
        'payment_per_minute' => (int) env('WEBHOOK_PAYMENT_PER_MINUTE', 120),
        'shipping_ip_per_minute' => (int) env('WEBHOOK_SHIPPING_IP_PER_MINUTE', 240),
        'shipping_per_minute' => (int) env('WEBHOOK_SHIPPING_PER_MINUTE', 120),
        'whatsapp_inbound_per_minute' => (int) env('WEBHOOK_WHATSAPP_INBOUND_PER_MINUTE', 120),
        'whatsapp_internal_per_minute' => (int) env('WEBHOOK_WHATSAPP_INTERNAL_PER_MINUTE', 600),
        'whatsapp_customers_per_minute' => (int) env('WEBHOOK_WHATSAPP_CUSTOMERS_PER_MINUTE', 120),
    ],

    'payment' => [
        // Driver selects gateway-specific verification algorithms
        'paymob' => [
            'driver' => 'paymob',
            'secret' => env('PAYMOB_WEBHOOK_SECRET'),
            'api_key' => env('PAYMOB_INQUIRY_API_KEY'),
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
            // M2: only one success event may confirm an order; otherwise every
            // successful delivery type looks like a second capture.
            'allowed_events' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'STRIPE_ALLOWED_EVENTS',
                'payment_intent.succeeded,charge.refunded,refund.created,refund.updated,payment_intent.payment_failed,charge.failed',
            ))))),
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
