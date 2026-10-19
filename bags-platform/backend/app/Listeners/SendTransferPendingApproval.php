<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TransferPendingApproval;
use App\Notifications\StaffRecipients;
use App\Notifications\TransferPendingApprovalNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** تنبيه من يملكون اعتماد التحويلات (transfers.approve) بتحويلة جديدة. */
class SendTransferPendingApproval implements ShouldQueue
{
    public function handle(TransferPendingApproval $event): void
    {
        $recipients = StaffRecipients::withAnyPermission(['transfers.approve']);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TransferPendingApprovalNotification($event->transfer));
        }
    }
}
