<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\WholesalePaymentNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analytical service for wholesale operational metrics and early-warning intelligence.
 *
 * Generates actionable debt collection rankings, merchant reorder cadence outlooks,
 * quotation acceptance rates, credit increase candidates, and OCR invoice anomalies.
 */
final class WholesaleInsights
{
    /**
     * Ranks overdue credit orders by debt exposure and generates draft Arabic reminders.
     *
     * @return list<array<string, mixed>> Prioritized collection targets sorted by exposure score.
     */
    public function collectionPriorities(): array
    {
        $orders = Order::query()
            ->with('customer:id,name,company_name')
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [OrderStatus::Cancelled])
            ->orderBy('due_at')
            ->limit(30)
            ->get();

        $covered = $this->coveredByOrderIds($orders->pluck('id')->all());

        return $orders
            ->map(function (Order $order) use ($covered): ?array {
                $remaining = max(0.0, round((float) $order->grand_total - ($covered[$order->id] ?? 0.0), 2));
                if ($remaining <= 0.0) {
                    return null;
                }
                $days = (int) $order->due_at?->diffInDays(now());
                $name = $order->customer?->company_name ?: $order->customer?->name;

                return [
                    'customer' => $name,
                    'order_number' => $order->order_number,
                    'amount' => number_format($remaining, 2, '.', ''),
                    'days_late' => $days,
                    // Debt exposure index: weights outstanding value by days overdue to prioritize large old balances.
                    'score' => round($remaining * max(1, $days), 2),
                    'draft' => "يا {$name}، طلب {$order->order_number} متأخر {$days} يوم. نأكد موعد السداد؟",
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * Identifies wholesale merchants whose elapsed time since last purchase exceeds their historical ordering cadence.
     *
     * @return list<array<string, mixed>> List of merchants overdue for restocking.
     */
    public function reorderOutlook(): array
    {
        // Require at least two past non-cancelled orders to establish a statistically meaningful cadence.
        $rows = DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw('orders.customer_id, count(*) as orders_count, max(orders.created_at) as last_order, min(orders.created_at) as first_order')
            ->whereNotNull('orders.customer_id')
            ->whereIn('customers.type', ['wholesale', 'distributor'])
            ->where('customers.status', 'approved')
            ->whereNotIn('orders.status', ['cancelled', 'draft'])
            ->groupBy('orders.customer_id')
            ->havingRaw('count(*) >= 2')
            ->limit(100)
            ->get();
        $customers = Customer::query()->whereIn('id', $rows->pluck('customer_id')->all())->get(['id', 'name', 'company_name'])->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            $span = max(1, (int) now()->parse($row->first_order)->diffInDays(now()->parse($row->last_order)));
            // Calculate mean interval across order gaps (n orders yield n - 1 intervals).
            $average = $span / max(1, ((int) $row->orders_count) - 1);
            $since = (int) now()->parse($row->last_order)->diffInDays(now());
            if ($since < $average) {
                continue;
            }
            $customer = $customers->get($row->customer_id);
            $out[] = [
                'customer_id' => (int) $row->customer_id,
                'customer' => $customer?->company_name ?: $customer?->name,
                'average_days' => round($average, 1),
                'days_since_last' => $since,
            ];
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Computes conversion percentage of closed quotations across final states (accepted, rejected, expired).
     *
     * @return array<string, mixed> Aggregated counts and acceptance rate percentage.
     */
    public function quoteAcceptance(): array
    {
        $counts = Quotation::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $accepted = (int) ($counts[QuotationStatus::Accepted->value] ?? 0)
            + (int) ($counts[QuotationStatus::ConvertedToOrder->value] ?? 0);
        // Exclude pending/draft quotes from denominator so in-flight negotiations do not artificially depress rate.
        $closed = $accepted
            + (int) ($counts[QuotationStatus::Rejected->value] ?? 0)
            + (int) ($counts[QuotationStatus::Expired->value] ?? 0);

        return [
            'accepted' => $accepted,
            'closed' => $closed,
            'acceptance_rate' => $closed === 0 ? null : round(($accepted / $closed) * 100, 1),
        ];
    }

    /**
     * Highlights reliable merchants operating near their credit ceiling without any overdue invoices.
     *
     * @return list<array<string, mixed>> Eligible merchants recommended for staff credit review.
     */
    public function creditSuggestions(): array
    {
        $out = [];
        $openOrdersByOrder = app(WholesaleOrderBalanceQuery::class)->unpaidCreditOrderBalances();
        $openTotalsSub = DB::query()->fromSub(clone $openOrdersByOrder, 'balances')
            ->groupBy('balances.customer_id')
            ->selectRaw('balances.customer_id, SUM(balances.remaining) as open_total');
        $overdueCustomersSub = DB::query()->fromSub(clone $openOrdersByOrder, 'balances')
            ->whereNotNull('balances.due_at')
            ->where('balances.due_at', '<', Carbon::today((string) config('app.business_timezone', 'Africa/Cairo'))->setTimezone('UTC'))
            ->where('balances.remaining', '>', 0)
            ->select('balances.customer_id');
        $freePoolSub = DB::table('wholesale_payment_notices as n')
            ->where('n.status', WholesalePaymentNoticeStatus::Approved->value)
            ->where(fn ($q) => $q->whereNull('n.order_id')->orWhereExists(fn ($s) => $s->selectRaw('1')->from('orders as cancelled_order')->whereColumn('cancelled_order.id', 'n.order_id')->where('cancelled_order.status', OrderStatus::Cancelled->value)))
            ->groupBy('n.customer_id')
            ->selectRaw('n.customer_id, SUM(n.amount) as free_total');
        $allocatedFreePoolSub = DB::table('wholesale_payment_allocations as a')
            ->join('wholesale_payment_notices as n', 'n.id', '=', 'a.notice_id')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('n.status', WholesalePaymentNoticeStatus::Approved->value)
            ->where('o.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('n.customer_id')
            ->selectRaw('n.customer_id, SUM(a.amount) as allocated_total');

        $customers = Customer::query()
            ->commercial()
            ->where('customers.status', 'approved')
            ->where('customers.credit_limit', '>', 0)
            ->joinSub($openTotalsSub, 'open_orders', 'open_orders.customer_id', '=', 'customers.id')
            ->leftJoinSub($freePoolSub, 'free_pool', 'free_pool.customer_id', '=', 'customers.id')
            ->leftJoinSub($allocatedFreePoolSub, 'allocated_pool', 'allocated_pool.customer_id', '=', 'customers.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->fromSub(clone $overdueCustomersSub, 'overdue_customers')->whereColumn('overdue_customers.customer_id', 'customers.id'))
            ->orderByRaw('(GREATEST(open_orders.open_total - GREATEST(COALESCE(free_pool.free_total, 0) - COALESCE(allocated_pool.allocated_total, 0), 0), 0) / NULLIF(customers.credit_limit, 0)) DESC, customers.id ASC')
            ->limit(100)
            ->get([
                'customers.*',
                'open_orders.open_total as open_total',
                'free_pool.free_total as free_total',
                'allocated_pool.allocated_total as allocated_total',
            ]);

        if ($customers->isEmpty()) {
            return [];
        }

        foreach ($customers as $customer) {
            $floating = max(0.0, (float) ($customer->free_total ?? 0) - (float) ($customer->allocated_total ?? 0));
            $used = max(0.0, (float) $customer->open_total - $floating);
            $limit = (float) $customer->credit_limit;
            // Target merchants with at least 90% credit utilization to proactively prevent order checkout blockages.
            if ($limit <= 0 || ($used / $limit) < 0.9) {
                continue;
            }
            $out[] = [
                'customer_id' => $customer->id,
                'customer' => $customer->company_name ?: $customer->name,
                'used_percent' => round(($used / $limit) * 100, 1),
                'suggestion' => 'العميل يسدد في الميعاد واستخدم أغلب الحد. مراجعة رفع الحد قرار موظف.',
            ];
        }

        return $out;
    }

    /**
     * Flags discrepancies between declared payment notice amounts and vision OCR extractions.
     *
     * @return list<array<string, mixed>> List of flagged payment notices exceeding the 1-cent variance threshold.
     */
    public function anomalies(): array
    {
        $out = [];
        $notices = WholesalePaymentNotice::query()
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->whereNotNull('ocr_payload')
            ->latest('id')
            ->limit(100)
            ->get();
        foreach ($notices as $notice) {
            $read = $notice->ocr_payload['amount'] ?? null;
            if (! is_string($read) || $read === '') {
                continue;
            }
            // Flag any mismatch >= 0.01 EGP between the merchant claim and bank slip OCR for manual review.
            if (abs((float) $read - (float) $notice->amount) >= 0.01) {
                $out[] = [
                    'notice_number' => $notice->notice_number,
                    'kind' => 'amount_gap',
                    'stated' => (string) $notice->amount,
                    'read' => $read,
                ];
            }
        }

        return $out;
    }

    /**
     * Sums approved linked notices, FIFO allocations, and refunded credit notes per order ID.
     *
     * @param  list<int>  $orderIds
     * @return array<int, float>
     */
    private function coveredByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $covered = [];
        $linked = DB::table('wholesale_payment_notices')
            ->whereIn('order_id', $orderIds)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(amount) as total')
            ->pluck('total', 'order_id');
        foreach ($linked as $orderId => $amount) {
            $covered[(int) $orderId] = ($covered[(int) $orderId] ?? 0.0) + (float) $amount;
        }

        $allocated = DB::table('wholesale_payment_allocations as a')
            ->join('wholesale_payment_notices as n', 'n.id', '=', 'a.notice_id')
            ->whereIn('a.order_id', $orderIds)
            ->where('n.status', WholesalePaymentNoticeStatus::Approved->value)
            ->groupBy('a.order_id')
            ->selectRaw('a.order_id, SUM(a.amount) as total')
            ->pluck('total', 'a.order_id');
        foreach ($allocated as $orderId => $amount) {
            $covered[(int) $orderId] = ($covered[(int) $orderId] ?? 0.0) + (float) $amount;
        }

        $creditNotes = DB::table('return_requests')
            ->whereIn('order_id', $orderIds)
            ->where('status', 'refunded')
            ->where('refund_method', RefundMethod::CreditNote->value)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(total_refund_amount) as total')
            ->pluck('total', 'order_id');
        foreach ($creditNotes as $orderId => $amount) {
            $covered[(int) $orderId] = ($covered[(int) $orderId] ?? 0.0) + (float) $amount;
        }

        return $covered;
    }
}
