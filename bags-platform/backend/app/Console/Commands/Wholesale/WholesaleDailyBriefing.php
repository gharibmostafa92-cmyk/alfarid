<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\WholesalePaymentNotice;
use App\Notifications\StaffRecipients;
use App\Notifications\Wholesale\WholesaleDeskNotification;
use App\Services\Wholesale\WholesaleOutbox;
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
        $overdueCutoff = Carbon::today($tz)->setTimezone('UTC');
        $overdue = Order::query()
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $overdueCutoff);
        $overdueCount = (int) (clone $overdue)->distinct()->count('customer_id');
        $linkedNotices = DB::table('wholesale_payment_notices')
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(amount) as total');
        $allocations = DB::table('wholesale_payment_allocations as a')
            ->join('wholesale_payment_notices as n', 'n.id', '=', 'a.notice_id')
            ->where('n.status', WholesalePaymentNoticeStatus::Approved->value)
            ->groupBy('a.order_id')
            ->selectRaw('a.order_id, SUM(a.amount) as total');
        $creditNotes = DB::table('return_requests')
            ->where('status', 'refunded')
            ->where('refund_method', RefundMethod::CreditNote->value)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(total_refund_amount) as total');
        $overdueBalances = DB::table('orders as o')
            ->leftJoinSub($linkedNotices, 'linked_notices', 'linked_notices.order_id', '=', 'o.id')
            ->leftJoinSub($allocations, 'approved_allocations', 'approved_allocations.order_id', '=', 'o.id')
            ->leftJoinSub($creditNotes, 'credit_notes', 'credit_notes.order_id', '=', 'o.id')
            ->where('o.payment_method', 'credit')
            ->where('o.payment_status', 'unpaid')
            ->where('o.status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('o.due_at')
            ->where('o.due_at', '<', $overdueCutoff)
            ->selectRaw('GREATEST(o.grand_total - COALESCE(linked_notices.total, 0) - COALESCE(approved_allocations.total, 0) - COALESCE(credit_notes.total, 0), 0) as remaining');
        $overdueTotal = (float) DB::query()->fromSub($overdueBalances, 'overdue_balances')->sum('remaining');
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
