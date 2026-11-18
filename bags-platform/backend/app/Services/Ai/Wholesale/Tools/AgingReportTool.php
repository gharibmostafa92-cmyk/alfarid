<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\Orders\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use Illuminate\Support\Collection;
use Throwable;

final class AgingReportTool implements AgentTool
{
    public function __construct(private BranchAccess $branches, private FinancePresenter $presenter) {}

    public function name(): string
    {
        return 'aging_report';
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
            'description' => 'تقرير أعمار المديونية لكل التجار: 0–30 و31–60 و61–90 وأكتر من 90 يوم.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        try {
            $branchId = $this->branches->restrictedBranch($actor);
        } catch (Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }

        $today = now()->startOfDay()->toDateString();
        $rows = Order::query()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('orders.payment_method', 'credit')
            ->where('orders.payment_status', 'unpaid')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('orders.allocated_branch_id', $branchId))
            ->selectRaw("
                orders.customer_id,
                COALESCE(NULLIF(customers.company_name, ''), customers.name) AS customer_name,
                MAX(GREATEST(0, (?::date - COALESCE(orders.due_at, orders.created_at)::date))) AS max_days,
                SUM(CASE WHEN (?::date - COALESCE(orders.due_at, orders.created_at)::date) <= 30 THEN orders.grand_total ELSE 0 END) AS bucket_0_30,
                SUM(CASE WHEN (?::date - COALESCE(orders.due_at, orders.created_at)::date) BETWEEN 31 AND 60 THEN orders.grand_total ELSE 0 END) AS bucket_31_60,
                SUM(CASE WHEN (?::date - COALESCE(orders.due_at, orders.created_at)::date) BETWEEN 61 AND 90 THEN orders.grand_total ELSE 0 END) AS bucket_61_90,
                SUM(CASE WHEN (?::date - COALESCE(orders.due_at, orders.created_at)::date) > 90 THEN orders.grand_total ELSE 0 END) AS bucket_over_90,
                SUM(orders.grand_total) AS total_amount
            ", [$today, $today, $today, $today, $today])
            ->groupBy('orders.customer_id', 'customers.company_name', 'customers.name')
            ->orderByDesc('max_days')
            ->orderByDesc('total_amount')
            ->limit(50)
            ->get();

        $grouped = $rows->map(function ($row): array {
            $buckets = [
                '0_30' => round((float) $row->bucket_0_30, 2),
                '31_60' => round((float) $row->bucket_31_60, 2),
                '61_90' => round((float) $row->bucket_61_90, 2),
                'over_90' => round((float) $row->bucket_over_90, 2),
            ];

            return [
                'customer_id' => (int) $row->customer_id,
                'customer' => $row->customer_name,
                'max_days' => (int) $row->max_days,
                'total' => round((float) $row->total_amount, 2),
                'buckets' => $buckets,
            ];
        })->values()->all();

        return $this->presenter->present($actor, ['rows' => $grouped], [
            'type' => 'table',
            'columns' => ['التاجر', '0–30', '31–60', '61–90', '+90', 'الإجمالي'],
            'rows' => array_map(fn (array $row) => [
                $row['customer'],
                number_format($row['buckets']['0_30'], 2, '.', ''),
                number_format($row['buckets']['31_60'], 2, '.', ''),
                number_format($row['buckets']['61_90'], 2, '.', ''),
                number_format($row['buckets']['over_90'], 2, '.', ''),
                number_format($row['total'], 2, '.', ''),
            ], $grouped),
        ]);
    }
}
