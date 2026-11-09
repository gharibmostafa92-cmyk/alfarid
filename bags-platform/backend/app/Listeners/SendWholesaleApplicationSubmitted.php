<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Wholesale\WholesaleApplicationSubmitted;
use App\Notifications\StaffRecipients;
use App\Notifications\Wholesale\WholesaleApplicationSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alerts staff members with customers.approve permission when a wholesale registration is submitted.
 */
class SendWholesaleApplicationSubmitted implements ShouldQueue
{
    public function handle(WholesaleApplicationSubmitted $event): void
    {
        $recipients = StaffRecipients::withAnyPermission(['customers.approve']);
        if ($recipients->isNotEmpty()) {
            DB::transaction(fn () => Notification::sendNow($recipients, new WholesaleApplicationSubmittedNotification($event->customer), ['database']));
        }
    }
}
