<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\WholesalePaymentNotice;
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
        return Order::query()
            ->with('customer:id,name,company_name')
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [OrderStatus::Cancelled])
            ->orderBy('due_at')
            ->limit(30)
            ->get()
            ->map(function (Order $order): array {
                $days = (int) $order->due_at?->diffInDays(now());
                $name = $order->customer?->company_name ?: $order->customer?->name;

                return [
                    'customer' => $name,
                    'order_number' => $order->order_number,
                    'amount' => (string) $order->grand_total,
                    'days_late' => $days,
                    // Debt exposure index: weights outstanding value by days overdue to prioritize large old balances.
                    'score' => round((float) $order->grand_total * max(1, $days), 2),
                    'draft' => "يا {$name}، طلب {$order->order_number} متأخر {$days} يوم. نأكد موعد السداد؟",
                ];
            })
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
            ->selectRaw('customer_id, count(*) as orders_count, max(created_at) as last_order, min(created_at) as first_order')
            ->whereNotNull('customer_id')
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->groupBy('customer_id')
            ->havingRaw('count(*) >= 2')
            ->limit(100)
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $span = max(1, (int) now()->parse($row->first_order)->diffInDays(now()->parse($row->last_order)));
            // Calculate mean interval across order gaps (n orders yield n - 1 intervals).
            $average = $span / max(1, ((int) $row->orders_count) - 1);
            $since = (int) now()->parse($row->last_order)->diffInDays(now());
            if ($since < $average) {
                continue;
            }
            $customer = Customer::query()->find($row->customer_id);
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
        $accepted = (int) ($counts[QuotationStatus::Accepted->value] ?? 0);
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
        $customers = Customer::query()
            ->whereIn('type', ['wholesale', 'distributor'])
            ->where('status', 'approved')
            ->where('credit_limit', '>', 0)
            ->limit(100)
            ->get();

        if ($customers->isEmpty()) {
            return [];
        }

        $customerIds = $customers->pluck('id')->all();

        // Disqualify merchants with any unpaid orders past due_at, regardless of total utilization.
        $overdueCustomerIds = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->where('payment_status', 'unpaid')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->pluck('customer_id')
            ->flip()
            ->all();

        $usedByCustomer = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(grand_total) as total_used')
            ->pluck('total_used', 'customer_id')
            ->all();

        foreach ($customers as $customer) {
            if (isset($overdueCustomerIds[$customer->id])) {
                continue;
            }
            $used = (float) ($usedByCustomer[$customer->id] ?? 0.0);
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
        $notices = WholesalePaymentNotice::query()->whereNotNull('ocr_payload')->limit(100)->get();
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
}
