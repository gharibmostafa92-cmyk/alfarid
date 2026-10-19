<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** تنبيه طاقم الإدارة بطلب جديد. */
class AdminOrderCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Order $order) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'admin_order_created',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'channel' => $this->order->channel,
            'grand_total' => $this->order->grand_total,
            'message' => "طلب جديد رقم {$this->order->order_number} بقيمة {$this->order->grand_total}.",
        ];
    }
}
