<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\AdminOrderCreatedNotification;
use App\Notifications\OrderConfirmationNotification;
use App\Notifications\StaffRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** تأكيد الطلب للعميل + تنبيه طاقم الإدارة (حاملي orders.view وSuper Admin). */
class SendOrderConfirmation implements ShouldQueue
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        if ($order->customer !== null) {
            $order->customer->notify(new OrderConfirmationNotification($order));
        }

        $staff = StaffRecipients::withAnyPermission(['orders.view']);
        if ($staff->isNotEmpty()) {
            Notification::send($staff, new AdminOrderCreatedNotification($order));
        }
    }
}
