<?php

declare(strict_types=1);

namespace App\Events\Wholesale;

use App\Models\WholesalePaymentNotice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a wholesale payment notice is approved or rejected by authorized staff.
 */
class PaymentNoticeReviewed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public WholesalePaymentNotice $notice) {}
}
