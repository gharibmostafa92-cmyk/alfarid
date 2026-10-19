<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WholesaleApplicationSubmitted;
use App\Notifications\StaffRecipients;
use App\Notifications\WholesaleApplicationSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** تنبيه الإدارة (حاملي customers.approve) بتسجيل تاجر جديد. */
class SendWholesaleApplicationSubmitted implements ShouldQueue
{
    public function handle(WholesaleApplicationSubmitted $event): void
    {
        $recipients = StaffRecipients::withAnyPermission(['customers.approve']);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WholesaleApplicationSubmittedNotification($event->customer));
        }
    }
}
