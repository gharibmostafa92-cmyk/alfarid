<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\WholesalePaymentNotice;
use App\Notifications\StaffRecipients;
use App\Notifications\Wholesale\WholesaleDeskNotification;
use App\Services\Wholesale\WholesaleOutbox;
use App\Services\Wholesale\WholesaleOrderBalanceQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Summarizes the daily morning operational queue for wholesale and sales desk staff.
 */
class WholesaleDailyBriefing extends Command
{
    protected $signature = 'wholesale:daily-briefing';

    protected $description = 'Morning wholesale queue summary for sales staff';

    public function handle(WholesaleOutbox $outbox, WholesaleOrderBalanceQuery $orderBalances): int
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
        $overdueCutoff = Carbon::today($tz)->setTimezone('UTC');
        $overdueBalances = DB::query()->fromSub($orderBalances->unpaidCreditOrderBalances(), 'credit_order_balances')
            ->whereNotNull('due_at')
            ->where('due_at', '<', $overdueCutoff)
            ->where('remaining', '>', 0);
        $overdueCount = (int) (clone $overdueBalances)->distinct()->count('customer_id');
        $overdueTotal = (float) (clone $overdueBalances)->sum('remaining');
        $worstCustomerId = (clone $overdueBalances)->orderBy('due_at')->orderBy('id')->value('customer_id');
        $worst = $worstCustomerId === null ? null : Customer::query()->find($worstCustomerId);

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
