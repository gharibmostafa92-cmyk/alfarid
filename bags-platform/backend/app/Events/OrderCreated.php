<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** أُنشئ طلب عميل جديد (متجر أو تحويل عرض سعر). */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
