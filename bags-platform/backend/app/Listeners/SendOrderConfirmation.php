<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\OrderConfirmationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * تأكيد الطلب للعميل فقط.
 *
 * L8: staff fan-out lives in SendStaffOrderNotification (separate queued
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
