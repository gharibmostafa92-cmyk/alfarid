<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TransferPendingApproval;
use App\Notifications\StaffRecipients;
use App\Notifications\TransferPendingApprovalNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alerts authorized staff members with transfers.approve permission of a newly created pending transfer.
 */
class SendTransferPendingApproval implements ShouldQueue
{
    public function handle(TransferPendingApproval $event): void
    {
        $recipients = StaffRecipients::withAnyPermission(['transfers.approve'], [
            (int) $event->transfer->from_branch_id, (int) $event->transfer->to_branch_id,
        ]);
        if ($recipients->isNotEmpty()) {
            DB::transaction(fn () => Notification::sendNow($recipients, new TransferPendingApprovalNotification($event->transfer), ['database']));
        }
    }
}
