<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Enums\QuotationStatus;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Models\Quotation;
use App\Models\WholesalePaymentNotice;
use App\Notifications\StaffRecipients;
use App\Notifications\Wholesale\WholesaleDeskNotification;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Escalates quotation requests and payment notices exceeding SLA review thresholds to staff.
 */
class EscalateStaleWholesale extends Command
{
    protected $signature = 'wholesale:escalate-stale';

    protected $description = 'Alert sales when a quote or payment notice waits past its SLA';

    public function handle(SettingService $settings, WholesaleOutbox $outbox): int
    {
        $quoteHours = max(1, (int) $settings->get('ai_wholesale_quote_sla_hours', 24));
        $noticeHours = max(1, (int) $settings->get('ai_wholesale_notice_sla_hours', 24));
        $quotes = Quotation::query()
            ->where('status', QuotationStatus::Requested)
            ->where('updated_at', '<=', now()->subHours($quoteHours))
            ->count();
        $notices = WholesalePaymentNotice::query()
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->where('created_at', '<=', now()->subHours($noticeHours))
            ->count();
        if ($quotes === 0 && $notices === 0) {
            return self::SUCCESS;
        }
        $day = now((string) config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        $message = "تصعيد: {$quotes} عروض تجاوزت {$quoteHours} ساعة، و{$notices} إشعارات تجاوزت {$noticeHours} ساعة.";
        $recipients = StaffRecipients::withAnyPermission(['quotations.price']);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WholesaleDeskNotification('wholesale_escalation', $message, []));
        }
        $outbox->queue("staff-escalation:{$day}", 'staff_whatsapp', null, 'staff_escalation', ['message' => $message], deliver: true);

        return self::SUCCESS;
    }
}
