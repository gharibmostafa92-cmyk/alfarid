<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Stocktake Stale Snapshot Approval Policy
    |--------------------------------------------------------------------------
    |
    | 'reject' (default): Rejects approval if stock movements occurred after physical counting.
    | 'apply_difference': Applies count variance (counted - system_qty) to the current balance.
    |
    */
    'stocktake_stale_policy' => env('STOCKTAKE_STALE_POLICY', 'reject'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Items Per Stocktake Session
    |--------------------------------------------------------------------------
    |
    | Limits stocktake batch size to prevent excessive row-lock contention on inventory balances.
    |
    */
    'stocktake_max_items' => (int) env('STOCKTAKE_MAX_ITEMS', 500),
    'pos_max_discount_percent' => (float) env('POS_MAX_DISCOUNT_PERCENT', 100),
];
