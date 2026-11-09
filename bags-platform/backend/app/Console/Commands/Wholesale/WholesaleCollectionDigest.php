<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Notifications\StaffRecipients;
use App\Notifications\WholesaleDeskNotification;
use App\Services\Wholesale\WholesaleInsights;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Compiles a daily morning collection priority digest for credit recovery staff.
 */
class WholesaleCollectionDigest extends Command
{
    protected $signature = 'wholesale:collection-digest';

    protected $description = 'Morning list of who to call, with a draft the employee must approve before sending';

    public function handle(WholesaleInsights $insights, WholesaleOutbox $outbox): int
    {
        $rows = array_slice($insights->collectionPriorities(), 0, 5);
        $day = now((string) config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        $message = $rows === []
            ? 'مفيش تحصيل متأخر محتاج اتصال النهارده.'
            : 'اتصل النهارده: '.implode('، ', array_map(fn ($row) => ($row['customer'] ?? '').' '.$row['amount'], $rows));
        $recipients = StaffRecipients::withAnyPermission(['payment_notices.approve']);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WholesaleDeskNotification('collection_digest', $message, ['count' => count($rows)]));
        }
        $outbox->queue("collection-digest:{$day}", 'staff_whatsapp', null, 'staff_daily_briefing', ['message' => $message], deliver: true);

        return self::SUCCESS;
    }
}
