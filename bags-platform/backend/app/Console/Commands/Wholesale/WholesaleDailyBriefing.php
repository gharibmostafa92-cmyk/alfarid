<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\WholesalePaymentNotice;
use App\Notifications\StaffRecipients;
use App\Notifications\Wholesale\WholesaleDeskNotification;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Summarizes the daily morning operational queue for wholesale and sales desk staff.
 */
class WholesaleDailyBriefing extends Command
{
    protected $signature = 'wholesale:daily-briefing';

    protected $description = 'Morning wholesale queue summary for sales staff';

    public function handle(WholesaleOutbox $outbox): int
    {
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $today = now($tz)->toDateString();
        $key = "staff-briefing:{$today}";

        $pendingNotices = WholesalePaymentNotice::query()
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->count();
        $matchedNotices = WholesalePaymentNotice::query()
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->where('match_score', '>=', 95)
            ->count();
        $staleQuotes = Quotation::query()
            ->where('status', QuotationStatus::Requested)
            ->where('created_at', '<=', now()->subHours(24))
            ->count();
        $overdue = Order::query()
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay());
        $overdueCount = (int) (clone $overdue)->distinct()->count('customer_id');
        $overdueTotal = (float) (clone $overdue)->sum('grand_total');
        $worst = (clone $overdue)->with('customer')->orderBy('due_at')->first()?->customer;

        $worstText = $worst === null ? '' : ' وأخطرهم '.($worst->company_name ?: $worst->name).'.';
        $message = "عندك {$pendingNotices} إشعارات ({$matchedNotices} مطابقين 95% أو أكتر)، و{$staleQuotes} عروض مستنية تسعير من أكتر من 24 ساعة، و{$overdueCount} تاجر متأخرين بإجمالي ".number_format($overdueTotal, 0, '.', ',').'.'.$worstText;

        $recipients = StaffRecipients::withAnyPermission(['quotations.price', 'payment_notices.approve']);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WholesaleDeskNotification('wholesale_daily_briefing', $message, [
                'pending_notices' => $pendingNotices,
                'stale_quotes' => $staleQuotes,
                'overdue_customers' => $overdueCount,
            ]));
        }

        $outbox->queue($key, 'staff_whatsapp', null, 'staff_daily_briefing', ['message' => $message], now($tz), true);
        $this->info($message);

        return self::SUCCESS;
    }
}
