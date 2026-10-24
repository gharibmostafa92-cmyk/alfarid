<?php

use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;

return [
    'require_phone_verification' => env('CUSTOMER_REQUIRE_PHONE_VERIFICATION', true),
    'customer_max_tokens' => (int) env('CUSTOMER_MAX_TOKENS', 10),
    'supplier_max_tokens' => (int) env('SUPPLIER_MAX_TOKENS', 5),
    'staff_token_minutes' => (int) env('STAFF_TOKEN_MINUTES', 120),
    'staff_current_password_failures' => (int) env('STAFF_CURRENT_PASSWORD_FAILURES', 5),
    'staff_admin_mutations_minute' => (int) env('STAFF_ADMIN_MUTATIONS_MINUTE', 60),
    'staff_audit_retention_days' => (int) env('STAFF_AUDIT_RETENTION_DAYS', 365),
    'staff_max_tokens' => (int) env('STAFF_MAX_TOKENS', 20),
    'legacy_login_enabled' => env('LEGACY_LOGIN_ENABLED', false),
    'unified_login_ip_minute' => (int) env('UNIFIED_LOGIN_IP_MINUTE', 120),
    'unified_login_failures_hour' => (int) env('UNIFIED_LOGIN_FAILURES_HOUR', 10),
    'unified_login_ip_failures_hour' => (int) env('UNIFIED_LOGIN_IP_FAILURES_HOUR', 1000),
    'unified_login_failures_day' => (int) env('UNIFIED_LOGIN_FAILURES_DAY', 1000),
    'unified_handoff_ip_minute' => (int) env('UNIFIED_HANDOFF_IP_MINUTE', 60),
    'customer_sms_daily_limit' => (int) env('CUSTOMER_SMS_DAILY_LIMIT', 1000),
    'customer_sms_hourly_limit' => (int) env('CUSTOMER_SMS_HOURLY_LIMIT', 100),
    'customer_limits' => [
        'phone_verify_ip_minute' => (int) env('CUSTOMER_PHONE_VERIFY_IP_MINUTE', 120),
        'reset_confirm_ip_minute' => (int) env('CUSTOMER_RESET_CONFIRM_IP_MINUTE', 120),
        'login_ip_minute' => (int) env('CUSTOMER_LOGIN_IP_MINUTE', 120),
        'reset_ip_minute' => (int) env('CUSTOMER_RESET_IP_MINUTE', 20),
        'reset_ip_day' => (int) env('CUSTOMER_RESET_IP_DAY', 500),
        'registration_ip_minute' => (int) env('CUSTOMER_REGISTRATION_IP_MINUTE', 20),
        'registration_ip_day' => (int) env('CUSTOMER_REGISTRATION_IP_DAY', 500),
        'phone_ip_minute' => (int) env('CUSTOMER_PHONE_IP_MINUTE', 20),
        'phone_ip_day' => (int) env('CUSTOMER_PHONE_IP_DAY', 500),
        'catalog_ip_minute' => (int) env('STORE_CATALOG_IP_MINUTE', 600),
        'price_ip_minute' => (int) env('STORE_PRICE_IP_MINUTE', 20),
        'price_global_minute' => (int) env('STORE_PRICE_GLOBAL_MINUTE', 200),
        'login_id_hour' => (int) env('CUSTOMER_LOGIN_ID_HOUR', 30),
        'login_id_day' => (int) env('CUSTOMER_LOGIN_ID_DAY', 100),
    ],
    'customer_daily_messages' => (int) env('CUSTOMER_DAILY_MESSAGES', 5),
    'customer_password_reset' => [
        'connection' => env('CUSTOMER_PASSWORD_RESET_QUEUE_CONNECTION', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'staff' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
        'customer' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],
        'supplier' => [
            'driver' => 'sanctum',
            'provider' => 'supplier_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],

        'supplier_users' => [
            'driver' => 'eloquent',
            'model' => SupplierUser::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
