<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** أمر شراء جديد في بوابة المورد. */
class PurchaseOrderSentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly PurchaseOrder $purchaseOrder) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'purchase_order_sent',
            'purchase_order_id' => $this->purchaseOrder->id,
            'po_number' => $this->purchaseOrder->po_number,
            'grand_total' => $this->purchaseOrder->grand_total,
            'message' => "وصل أمر شراء جديد رقم {$this->purchaseOrder->po_number} إلى بوابتك.",
        ];
    }
}
