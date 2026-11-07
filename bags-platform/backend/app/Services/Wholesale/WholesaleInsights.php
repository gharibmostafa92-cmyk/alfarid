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
 * Derives wholesale business metrics: collection priority, replenishment velocity, quote acceptance, and credit risk.
 */
final class WholesaleInsights
{
    /** @return list<array<string, mixed>> */
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
                    'score' => round((float) $order->grand_total * max(1, $days), 2),
                    'draft' => "يا {$name}، طلب {$order->order_number} متأخر {$days} يوم. نأكد موعد السداد؟",
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function reorderOutlook(): array
    {
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

    /** @return array<string, mixed> */
    public function quoteAcceptance(): array
    {
        $counts = Quotation::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $accepted = (int) ($counts[QuotationStatus::Accepted->value] ?? 0);
        $closed = $accepted
            + (int) ($counts[QuotationStatus::Rejected->value] ?? 0)
            + (int) ($counts[QuotationStatus::Expired->value] ?? 0);

        return [
            'accepted' => $accepted,
            'closed' => $closed,
            'acceptance_rate' => $closed === 0 ? null : round(($accepted / $closed) * 100, 1),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function creditSuggestions(): array
    {
        $out = [];
        $customers = Customer::query()
            ->whereIn('type', ['wholesale', 'distributor'])
            ->where('status', 'approved')
            ->where('credit_limit', '>', 0)
            ->limit(100)
            ->get();
        foreach ($customers as $customer) {
            $overdue = Order::query()
                ->where('customer_id', $customer->id)
                ->where('payment_status', 'unpaid')
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->exists();
            if ($overdue) {
                continue;
            }
            $used = (float) Order::query()
                ->where('customer_id', $customer->id)
                ->where('payment_method', 'credit')
                ->where('payment_status', 'unpaid')
                ->sum('grand_total');
            $limit = (float) $customer->credit_limit;
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

    /** @return list<array<string, mixed>> */
    public function anomalies(): array
    {
        $out = [];
        $notices = WholesalePaymentNotice::query()->whereNotNull('ocr_payload')->limit(100)->get();
        foreach ($notices as $notice) {
            $read = $notice->ocr_payload['amount'] ?? null;
            if (! is_string($read) || $read === '') {
                continue;
            }
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
