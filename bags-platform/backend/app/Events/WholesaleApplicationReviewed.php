<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Customer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after transaction commit when staff reviews a merchant application (approved or rejected).
 */
class WholesaleApplicationReviewed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $decision,
        public ?string $reason = null,
    ) {}
}
