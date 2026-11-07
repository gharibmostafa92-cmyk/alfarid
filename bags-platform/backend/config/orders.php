<?php

declare(strict_types=1);

return [
    /*
     * Dedicated background user email for attributing automated inventory movements
     * (e.g. webhook payment confirmations, customer cancellations).
     */
    'system_user_email' => env('ORDER_SYSTEM_USER_EMAIL', 'system@alfarid.local'),

    /*
     * Pending order expiration intervals: releases reserved inventory when checkout is abandoned.
     */
    // 90 minutes provides a safe window for 3DS authentication and wallet callbacks before cancellation
    'pending_online_expire_minutes' => (int) env('ORDER_PENDING_ONLINE_EXPIRE_MINUTES', 90),
    'pending_cod_expire_hours' => (int) env('ORDER_PENDING_COD_EXPIRE_HOURS', 72),
    // Guest COD orders expire faster (12h) to prevent unverified carts from tying up inventory
    'pending_guest_cod_expire_hours' => (int) env('ORDER_PENDING_GUEST_COD_EXPIRE_HOURS', 12),
];
