<?php

declare(strict_types=1);

namespace App\Events\Wholesale;

use App\Models\Customer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after transaction commit when a new wholesale application is registered for staff review.
 */
class WholesaleApplicationSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Customer $customer) {}
}
