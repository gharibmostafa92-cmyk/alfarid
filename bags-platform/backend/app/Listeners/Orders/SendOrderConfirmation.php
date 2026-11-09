<?php

declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Events\Orders\OrderCreated;
use App\Notifications\Orders\OrderConfirmationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Dispatches order confirmation notification directly to the customer.
 *
 * Staff fan-out lives in SendStaffOrderNotification (separate queued
 * listener) so a staff-notify failure never re-sends this confirmation.
 */
class SendOrderConfirmation implements ShouldQueue
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        if ($order->customer !== null) {
            $order->customer->notify(new OrderConfirmationNotification($order));
        }
    }
}
