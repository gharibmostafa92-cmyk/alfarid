<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a customer or converted quotation order is created.
 *
 * Implements ShouldDispatchAfterCommit so listeners (notifications, inventory,
 * external hooks) execute only after the enclosing database transaction succeeds.
 */
class OrderCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Order  $order  Newly committed order model instance.
     */
    public function __construct(public Order $order) {}
}
