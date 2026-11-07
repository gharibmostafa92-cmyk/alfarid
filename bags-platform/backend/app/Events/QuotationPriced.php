<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Quotation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when staff submits item pricing, discounts, and validity dates for a quotation.
 */
class QuotationPriced implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
