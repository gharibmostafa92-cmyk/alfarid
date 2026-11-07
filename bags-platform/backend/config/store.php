<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Store Configuration
|--------------------------------------------------------------------------
| Supported payment methods for retail checkout. Trade credit is reserved
| exclusively for vetted wholesale accounts via dedicated wholesale workflows.
*/

return [
    // Fallback route for retail customers after login when no safe redirect is provided.
    'retail_home' => env('STORE_RETAIL_HOME', '/shop'),

    // Manual bank transfer available only when a valid InstaPay recipient is configured.
    'instapay_address' => env('STORE_INSTAPAY_ADDRESS'),

    'payment_methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STORE_PAYMENT_METHODS', 'cod,wallet,bank_transfer')),
    ))),

    /*
     * Maximum line items permitted in a single cart to constrain calculation and lock costs.
     */
    'max_cart_lines' => (int) env('STORE_MAX_CART_LINES', 50),

    /*
     * Maximum item quantity per cart line for unverified guests to prevent inventory lockup.
     */
    'guest_max_line_qty' => (float) env('STORE_GUEST_MAX_LINE_QTY', 10),

    /*
     * Maximum item quantity per cart line for retail customers; vetted wholesale accounts are exempt.
     */
    'retail_max_line_qty' => (float) env('STORE_RETAIL_MAX_LINE_QTY', 50),

    /*
     * Maximum active pending COD orders allowed per normalized guest phone number (0 = uncapped).
     */
    'guest_pending_orders_cap' => (int) env('STORE_GUEST_PENDING_ORDERS_CAP', 3),

    /*
     * Maximum active pending COD orders allowed per retail customer account (0 = uncapped).
     */
    'customer_pending_orders_cap' => (int) env('STORE_CUSTOMER_PENDING_ORDERS_CAP', 5),

    /*
     * Rate limit for coupon code validations per (coupon + client IP) per minute.
     */
    'coupon_apply_per_minute' => (int) env('STORE_COUPON_APPLY_PER_MINUTE', 20),
];
