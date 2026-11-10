<?php

return [

    'integrations' => [
        'ca_bundle' => env('INTEGRATIONS_CA_BUNDLE'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL'),
        'invoice_model' => env('GEMINI_INVOICE_MODEL'),
        'timeout_seconds' => (int) env('GEMINI_TIMEOUT_SECONDS', 20),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 2048),
        'max_images' => 5,
        'max_image_bytes' => 4 * 1024 * 1024,
        // Maximum pending unconsumed attachments per user to protect shared storage from exhaustion.
        'max_pending_attachments_per_user' => (int) env('AI_MAX_PENDING_ATTACHMENTS_PER_USER', 40),
        'retired_models' => array_values(array_filter(explode(',', (string) env('GEMINI_RETIRED_MODELS', '')))),
        // Hard concurrency ceiling kept below worker pool max_children to prevent AI requests from starving web traffic.
        'max_concurrent_sessions_cap' => (int) env('AI_MAX_CONCURRENT_SESSIONS_CAP', 20),
    ],
    'whatsapp_business' => [
        'token' => env('WHATSAPP_BUSINESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_BUSINESS_PHONE_NUMBER_ID'),
        'webhook_secret' => env('WHATSAPP_BUSINESS_WEBHOOK_SECRET'),
    ],
    'customer_sms' => [
        'url' => env('CUSTOMER_SMS_URL'),
        'token' => env('CUSTOMER_SMS_TOKEN'),
    ],
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'expected_hostname' => env('TURNSTILE_EXPECTED_HOSTNAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'whatsapp_gateway' => [
        'url' => env('WA_GATEWAY_URL'),
        'secret' => env('WA_GATEWAY_SECRET'),
        'timeout_seconds' => (int) env('WA_GATEWAY_TIMEOUT', 10),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
