<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class CouponPerformanceTool implements AgentTool
{
    public function __construct(private PricingPresenter $presenter, private BranchAccess $branches) {}

    public function name(): string
    {
        return 'coupon_performance';
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
        return ['coupons.view', 'orders.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اعرض أداء كوبون: الاستخدامات، إجمالي الخصم، متوسط قيمة الطلب، ونسبة الإلغاء.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['code'],
                'properties' => (object) [
                    'code' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        $coupon = Coupon::query()->whereRaw('upper(code) = ?', [mb_strtoupper(trim((string) ($args['code'] ?? '')))])->first();
        if (! $coupon) {
            return ToolOutcome::denied('الكوبون مش موجود.', auditable: false);
        }

        $branchId = $scope['branchId'];
        $usage = CouponUsage::query()
            ->join('orders', 'orders.id', '=', 'coupon_usages.order_id')
            ->where('coupon_usages.coupon_id', $coupon->id)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNull('orders.deleted_at')
            ->when($branchId !== null, fn ($query) => $query->where('orders.allocated_branch_id', $branchId))
            ->selectRaw('count(*) as uses, coalesce(sum(coupon_usages.discount_amount), 0) as discount_total, avg(orders.grand_total) as order_average')
            ->first();
        // Cancellation releases the usage row. The original coupon is still recorded on the order.
        $cancelled = Order::query()->where('coupon_id', $coupon->id)->where('status', OrderStatus::Cancelled)
            ->when($branchId !== null, fn ($query) => $query->where('allocated_branch_id', $branchId))
            ->count();
        $uses = (int) $usage->uses;
        $data = [
            'code' => $coupon->code,
            'uses' => $uses,
            'discount_total' => round((float) $usage->discount_total, 2),
            'order_average' => $usage->order_average === null ? null : round((float) $usage->order_average, 2),
            'cancelled_orders' => $cancelled,
            'cancellation_percent' => $uses + $cancelled > 0 ? round(100 * $cancelled / ($uses + $cancelled), 2) : null,
        ];

        return $this->presenter->present($actor, $data, $this->presenter->table(
            ['الكود', 'الاستخدامات', 'إجمالي الخصم', 'متوسط الطلب', 'الطلبات الملغية', 'نسبة الإلغاء %'],
            [array_values($data)],
        ));
    }
}
