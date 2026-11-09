<?php

declare(strict_types=1);

namespace App\Enums\Orders;

/**
 * Order fulfillment lifecycle statuses. Transitions must be dispatched through OrderService::changeStatus().
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
}
