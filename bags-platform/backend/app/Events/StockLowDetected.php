<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when variant availability crosses to or below its reorder point.
 * Emitted strictly on boundary crossing to avoid duplicate alerts on subsequent movements.
 * Dispatched after transaction commit to ensure queue workers never read uncommitted ledger rows.
 */
class StockLowDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $productVariantId,
        public int $branchId,
        public float $available,
        public float $reorderLevel,
    ) {}
}
