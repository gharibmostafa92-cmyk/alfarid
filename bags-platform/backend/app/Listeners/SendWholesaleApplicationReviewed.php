<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WholesaleApplicationReviewed;
use App\Notifications\Wholesale\WholesaleApplicationReviewedNotification;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Notifies merchant of wholesale onboarding application review outcome (approved or rejected).
 * Uses ShouldQueueAfterCommit so notifications are queued strictly after database transaction commit,
 * preventing premature delivery if parent transactions roll back.
 */
class SendWholesaleApplicationReviewed implements ShouldQueueAfterCommit
{
    public function handle(WholesaleApplicationReviewed $event): void
    {
        $event->customer->notify(new WholesaleApplicationReviewedNotification($event->decision, $event->reason));
    }
}
