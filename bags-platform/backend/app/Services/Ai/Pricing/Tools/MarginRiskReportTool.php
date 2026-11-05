<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\CouponService;
use App\Services\PricingService;

final class MarginRiskReportTool implements AgentTool
{
    public function __construct(
        private PricingService $pricing,
        private CouponService $coupons,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'margin_risk_report';
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
        return ['price_lists.view', 'coupons.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اعرض أقل 20 هامش في عينة من المنتجات المنشورة بعد تسعير القنوات والمجموعات وخصم كوبون مؤهل.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['branch_id' => ['type' => 'INTEGER']],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        if (! CostAccess::canView($actor)) {
            return ToolOutcome::denied('التقرير يحتاج صلاحية عرض التكلفة.');
        }
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, PricingArgs::normalize($args));
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        $branchId = $scope['branchId'];
        $variants = ProductVariant::query()->where('is_active', true)->where('cost_price', '>', 0)
            ->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Published))
            ->orderByDesc('cost_price')->orderBy('id')->limit(100)->get();
        $variantIds = $variants->modelKeys();
        $now = now();
        $coupons = Coupon::query()->where('is_active', true)->where('type', '!=', 'free_shipping')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->get();
        $groups = CustomerGroup::query()->where('is_active', true)->orderByDesc('discount_percentage')
            ->orderBy('id')->limit(10)->get();

        $worst = [];
        foreach (CustomerType::cases() as $channel) {
            foreach ([null, ...$groups] as $group) {
                $customer = new Customer(['type' => $channel, 'status' => CustomerStatus::Approved]);
                $customer->setRelation('customerGroup', $group);
                $context = $this->pricing->contextFor($customer, $branchId === null ? $variantIds : null);
                foreach ($variants as $variant) {
                    try {
                        $price = $this->pricing->resolve($variant, $customer, 1, $branchId, $context)['unit_price'];
                    } catch (BusinessException) {
                        continue;
                    }
                    $discount = 0.0;
                    foreach ($coupons as $coupon) {
                        if ($price >= (float) $coupon->min_order_amount) {
                            $discount = max($discount, $this->coupons->discountFor($coupon, $price));
                        }
                    }
                    $net = round($price - $discount, 2);
                    $margin = $net - (float) $variant->cost_price;
                    if (! isset($worst[$variant->id]) || $margin < $worst[$variant->id]['margin']) {
                        $worst[$variant->id] = [
                            'sku' => $variant->sku,
                            'channel' => $channel->value,
                            'group_id' => $group?->id,
                            'unit_price' => $price,
                            'coupon_discount' => $discount,
                            'net_price' => $net,
                            'margin' => round($margin, 2),
                        ];
                    }
                }
            }
        }

        $rows = collect($worst)->sortBy('margin')->take(20)->values()->all();

        return $this->presenter->present($actor, [
            'sampled_variants' => $variants->count(),
            'sampled_groups' => $groups->count(),
            'rows' => $rows,
        ], $this->presenter->table(['SKU', 'القناة', 'المجموعة', 'السعر', 'خصم الكوبون', 'الصافي', 'الهامش ج.م'], array_map('array_values', $rows)),
            'عينة من المنتجات الأعلى تكلفة والمجموعات الأعلى خصمًا، عند شراء وحدة واحدة.');
    }
}
