<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a purchase order is transmitted to the supplier portal.
 */
class PurchaseOrderSentToSupplier
{
    use Dispatchable, SerializesModels;

    public function __construct(public PurchaseOrder $purchaseOrder) {}
}
