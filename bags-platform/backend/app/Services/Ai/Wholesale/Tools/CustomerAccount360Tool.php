<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class CustomerAccount360Tool implements AgentTool
{
    public function __construct(private WholesalePaymentNoticeService $notices) {}

    public function name(): string
    {
        return 'customer_account_360';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['payment_notices.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حساب التاجر: الرصيد، حد الائتمان والمستخدم، الأعمار، آخر 5 سدادات، ومتوسط أيام السداد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['customer_id' => ['type' => 'INTEGER']],
                'required' => ['customer_id'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if ($customer === null) {
            return ToolOutcome::denied('التاجر مش موجود.', auditable: false);
        }

        $outstanding = (float) $this->notices->balanceBreakdown($customer)['outstanding'];
        $used = max(0, $outstanding);
        $limit = (float) $customer->credit_limit;
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['id', 'grand_total', 'due_at', 'created_at']);
        $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        foreach ($orders as $order) {
            $basis = $order->due_at ?? $order->created_at;
            $days = $basis === null ? 0 : max(0, (int) $basis->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
            $amount = (float) $order->grand_total;
            if ($days <= 30) {
                $buckets['0_30'] += $amount;
            } elseif ($days <= 60) {
                $buckets['31_60'] += $amount;
            } elseif ($days <= 90) {
                $buckets['61_90'] += $amount;
            } else {
                $buckets['over_90'] += $amount;
            }
        }

        $payments = WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved)
            ->orderByDesc('approved_at')
            ->limit(5)
            ->get(['id', 'notice_number', 'amount', 'approved_at', 'transfer_date'])
            ->map(fn (WholesalePaymentNotice $n) => [
                'number' => $n->notice_number,
                'amount' => (string) $n->amount,
                'date' => ($n->approved_at ?? $n->transfer_date)?->toDateString(),
            ])->all();

        $paidOrders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'paid')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'created_at']);
        $daySamples = [];
        foreach ($paidOrders as $order) {
            $paidAt = WholesalePaymentNotice::query()
                ->where('customer_id', $customer->id)
                ->where('status', WholesalePaymentNoticeStatus::Approved)
                ->where(function ($q) use ($order): void {
                    $q->where('order_id', $order->id)
                        ->orWhereHas('allocations', fn ($a) => $a->where('order_id', $order->id));
                })
                ->max('approved_at');
            if ($paidAt !== null && $order->created_at !== null) {
                $daySamples[] = max(0, (int) $order->created_at->diffInDays($paidAt));
            }
        }

        $data = [
            'customer_id' => $customer->id,
            'name' => $customer->company_name ?: $customer->name,
            'outstanding' => round($outstanding, 2),
            'credit_limit' => $limit,
            'used_credit' => round($used, 2),
            'available_credit' => round($limit - $used, 2),
            'payment_terms_days' => $customer->payment_terms_days,
            'aging' => array_map(fn ($v) => round($v, 2), $buckets),
            'last_payments' => $payments,
            'average_days_to_pay' => $daySamples === [] ? null : round(array_sum($daySamples) / count($daySamples), 1),
        ];

        return ToolOutcome::data($data, [
            'type' => 'customer_card',
            'id' => $customer->id,
            'name' => $data['name'],
            'credit_limit' => $limit,
            'used_credit' => $data['used_credit'],
            'aging_table' => [
                'columns' => ['0–30', '31–60', '61–90', '+90'],
                'rows' => [[
                    number_format($data['aging']['0_30'], 2, '.', ''),
                    number_format($data['aging']['31_60'], 2, '.', ''),
                    number_format($data['aging']['61_90'], 2, '.', ''),
                    number_format($data['aging']['over_90'], 2, '.', ''),
                ]],
            ],
        ]);
    }
}
