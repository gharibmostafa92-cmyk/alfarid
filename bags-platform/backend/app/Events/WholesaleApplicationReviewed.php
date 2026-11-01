<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Customer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** راجعت الإدارة طلب تاجر: decision = approved|rejected. */
class WholesaleApplicationReviewed implements ShouldDispatchAfterCommit // M4
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $decision,
        public ?string $reason = null,
    ) {}
}
