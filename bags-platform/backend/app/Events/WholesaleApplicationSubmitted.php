<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** سجّل تاجر جملة/موزع جديد وبانتظار مراجعة الإدارة. */
class WholesaleApplicationSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Customer $customer) {}
}
