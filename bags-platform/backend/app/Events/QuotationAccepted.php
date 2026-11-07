<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Quotation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a wholesale quotation is accepted by the merchant and converted into an order.
 */
class QuotationAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
