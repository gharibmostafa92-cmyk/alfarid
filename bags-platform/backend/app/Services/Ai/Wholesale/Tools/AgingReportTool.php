<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use Illuminate\Support\Collection;
use Throwable;

final class AgingReportTool implements AgentTool
{
    public function __construct(private BranchAccess $branches) {}

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

        $orders = Order::query()
            ->with('customer:id,name,company_name')
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->get(['id', 'customer_id', 'grand_total', 'due_at', 'created_at', 'order_number']);

        $grouped = $orders->groupBy('customer_id')->map(function (Collection $rows) {
            $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
            $maxDays = 0;
            foreach ($rows as $order) {
                $basis = $order->due_at ?? $order->created_at;
                $days = $basis === null ? 0 : max(0, (int) $basis->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
                $maxDays = max($maxDays, $days);
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
            $customer = $rows->first()?->customer;

            return [
                'customer_id' => (int) $rows->first()->customer_id,
                'customer' => $customer?->company_name ?: $customer?->name,
                'max_days' => $maxDays,
                'total' => round(array_sum($buckets), 2),
                'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            ];
        })->sort(fn (array $a, array $b): int => [$b['max_days'], $b['total']] <=> [$a['max_days'], $a['total']])->values()->all();

        return ToolOutcome::data(['rows' => $grouped], [
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
