<?php

declare(strict_types=1);

namespace App\Events\Wholesale;

use App\Models\Quotation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a merchant submits a quotation request.
 */
class QuotationSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
