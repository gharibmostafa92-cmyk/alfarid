<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WholesaleApplicationReviewed;
use App\Notifications\WholesaleApplicationReviewedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/** إشعار التاجر بنتيجة مراجعة حسابه (قبول/رفض). */
class SendWholesaleApplicationReviewed implements ShouldQueue
{
    public function handle(WholesaleApplicationReviewed $event): void
    {
        $event->customer->notify(new WholesaleApplicationReviewedNotification($event->decision, $event->reason));
    }
}
