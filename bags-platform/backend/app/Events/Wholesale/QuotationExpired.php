<?php

declare(strict_types=1);

namespace App\Events\Wholesale;

use App\Models\Quotation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a priced wholesale quotation reaches its validity expiration date.
 */
class QuotationExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
