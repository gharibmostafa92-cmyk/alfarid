<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** تأكيد استلام طلب جديد — للعميل صاحب الطلب. */
class OrderConfirmationNotification extends Notification implements ShouldQueue
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
        $message = "تم استلام طلبك رقم {$this->order->order_number} بنجاح وهو قيد المراجعة.";

        return [
            'kind' => 'order_confirmation',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'grand_total' => $this->order->grand_total,
            'message' => $message,
            // شكل موحد لبوابة الجملة: {title, body, icon, link:{screen,id}}
            'title' => "تم استلام طلبك رقم {$this->order->order_number}",
            'body' => $message,
            'icon' => 'fa-box',
            'link' => ['screen' => 'order', 'id' => $this->order->id],
        ];
    }
}
