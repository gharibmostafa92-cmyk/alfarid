<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StockTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** تحويلة مخزون جديدة بانتظار الاعتماد. */
class TransferPendingApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly StockTransfer $transfer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'transfer_pending_approval',
            'transfer_id' => $this->transfer->id,
            'transfer_number' => $this->transfer->transfer_number,
            'from_branch_id' => $this->transfer->from_branch_id,
            'to_branch_id' => $this->transfer->to_branch_id,
            'message' => "تحويلة جديدة رقم {$this->transfer->transfer_number} بانتظار الاعتماد.",
        ];
    }
}
