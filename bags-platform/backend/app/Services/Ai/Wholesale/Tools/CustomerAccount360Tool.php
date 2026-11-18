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
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\Wholesale\WholesaleAccessGuard;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class CustomerAccount360Tool implements AgentTool
{
    public function __construct(
        private WholesalePaymentNoticeService $notices,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

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
        if ($denied = WholesaleAccessGuard::denyIfNoCentralAccess($this->branches, $actor)) {
            return $denied;
        }
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if ($customer === null) {
            return ToolOutcome::denied('التاجر مش موجود.', auditable: false);
        }

        $outstanding = (float) $this->notices->balanceBreakdown($customer)['outstanding'];
        $used = max(0, $outstanding);
        $limit = (float) $customer->credit_limit;
        $today = now()->startOfDay();
        $aging = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN COALESCE(due_at, created_at) >= ? THEN grand_total ELSE 0 END), 0) AS age_0_30, '
                .'COALESCE(SUM(CASE WHEN COALESCE(due_at, created_at) < ? AND COALESCE(due_at, created_at) >= ? THEN grand_total ELSE 0 END), 0) AS age_31_60, '
                .'COALESCE(SUM(CASE WHEN COALESCE(due_at, created_at) < ? AND COALESCE(due_at, created_at) >= ? THEN grand_total ELSE 0 END), 0) AS age_61_90, '
                .'COALESCE(SUM(CASE WHEN COALESCE(due_at, created_at) < ? THEN grand_total ELSE 0 END), 0) AS age_over_90',
                [$today->copy()->subDays(30), $today->copy()->subDays(30), $today->copy()->subDays(60), $today->copy()->subDays(60), $today->copy()->subDays(90), $today->copy()->subDays(90)]
            )->first();
        $buckets = [
            '0_30' => (float) ($aging->age_0_30 ?? 0),
            '31_60' => (float) ($aging->age_31_60 ?? 0),
            '61_90' => (float) ($aging->age_61_90 ?? 0),
            'over_90' => (float) ($aging->age_over_90 ?? 0),
        ];

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
        $paidOrderIds = $paidOrders->pluck('id')->all();
        if ($paidOrderIds !== []) {
            $directPaidAt = WholesalePaymentNotice::query()
                ->where('customer_id', $customer->id)
                ->where('status', WholesalePaymentNoticeStatus::Approved)
                ->whereIn('order_id', $paidOrderIds)
                ->selectRaw('order_id, MAX(approved_at) AS paid_at')
                ->groupBy('order_id')
                ->pluck('paid_at', 'order_id');
            $allocTable = (new \App\Models\WholesalePaymentAllocation)->getTable();
            $allocPaidAt = \Illuminate\Support\Facades\DB::table("{$allocTable} as a")
                ->join('wholesale_payment_notices as n', 'n.id', '=', 'a.notice_id')
                ->where('n.customer_id', $customer->id)
                ->where('n.status', WholesalePaymentNoticeStatus::Approved->value)
                ->whereIn('a.order_id', $paidOrderIds)
                ->selectRaw('a.order_id, MAX(n.approved_at) AS paid_at')
                ->groupBy('a.order_id')
                ->pluck('paid_at', 'order_id');
            foreach ($paidOrders as $order) {
                $paidAt = $directPaidAt[$order->id] ?? $allocPaidAt[$order->id] ?? null;
                if ($paidAt !== null && $order->created_at !== null) {
                    $daySamples[] = max(0, (int) $order->created_at->diffInDays(\Carbon\Carbon::parse($paidAt)));
                }
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

        return $this->presenter->present($actor, $data, [
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
