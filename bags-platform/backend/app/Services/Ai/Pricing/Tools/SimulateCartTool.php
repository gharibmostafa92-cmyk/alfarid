<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\CouponService;
use App\Services\Pricing\PricingService;
use Carbon\Carbon;

final class SimulateCartTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PricingService $pricing,
        private CouponService $coupons,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'simulate_cart';
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
            'description' => 'احسب إجمالي سلة افتراضية (سعر كل بند من محرك التسعير + خصم الكوبون) من غير ما تسجل استخدام الكوبون أو تربطه بسلة. العروض الترويجية مش بتتطبق تلقائيًا — اعرضها كملحوظة نظرية فقط.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['items'],
                'properties' => (object) [
                    'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT']],
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_query' => ['type' => 'STRING'],
                    'coupon_code' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        if (! $actor->can('price_lists.view')) {
            return ToolOutcome::denied('معندكش صلاحية عرض قوائم الأسعار.');
        }
        $args = PricingArgs::normalize($args);
        $rawItems = (array) ($args['items'] ?? []);
        if ($rawItems === [] || count($rawItems) > 50) {
            return ToolOutcome::denied('السلة لازم يكون فيها من 1 لـ 50 بند.', auditable: false);
        }

        $customerResolved = PricingArgs::resolveCustomer($actor, $this->resolver, $args);
        if ($customerResolved instanceof ToolOutcome) {
            return $customerResolved;
        }
        $customer = $customerResolved['customer'];

        $couponCode = trim((string) ($args['coupon_code'] ?? ''));
        if ($couponCode !== '' && ! $actor->can('coupons.view')) {
            return ToolOutcome::denied('معندكش صلاحية عرض الكوبونات.');
        }

        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        $lines = [];
        $subtotal = 0.0;
        $variantIds = [];
        $categoryIds = [];
        $brandIds = [];
        foreach ($rawItems as $index => $raw) {
            if (! is_array($raw)) {
                return ToolOutcome::denied('بند رقم '.($index + 1).' غير صالح.', auditable: false);
            }
            $itemArgs = PricingArgs::normalize($raw);
            $resolved = PricingArgs::resolveVariant($actor, $this->resolver, $itemArgs);
            if ($resolved instanceof ToolOutcome) {
                return $resolved;
            }
            $variant = $resolved['variant'];
            $qty = isset($itemArgs['qty']) ? (float) $itemArgs['qty'] : 1.0;
            if (! is_finite($qty) || $qty <= 0) {
                return ToolOutcome::denied('كمية البند رقم '.($index + 1).' لازم تكون موجبة.', auditable: false);
            }
            try {
                $result = $this->pricing->resolve($variant, $customer, $qty, $scope['branchId']);
            } catch (BusinessException $e) {
                return ToolOutcome::denied($e->getMessage(), auditable: false);
            }
            $lineTotal = round($result['unit_price'] * $qty, 2);
            $subtotal += $lineTotal;
            $variant->loadMissing('product:id,category_id,brand_id');
            $variantIds[] = $variant->id;
            if ($variant->product?->category_id !== null) {
                $categoryIds[] = (int) $variant->product->category_id;
            }
            if ($variant->product?->brand_id !== null) {
                $brandIds[] = (int) $variant->product->brand_id;
            }
            $lines[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'qty' => $qty,
                'unit_price' => $result['unit_price'],
                'line_total' => $lineTotal,
                'source' => $result['source'],
            ];
        }
        $subtotal = round($subtotal, 2);

        $coupon = null;
        $couponRejection = null;
        $discount = 0.0;
        if ($couponCode !== '') {
            $coupon = Coupon::query()->whereRaw('upper(code) = ?', [mb_strtoupper($couponCode)])->first();
            if ($coupon === null) {
                $couponRejection = 'الكوبون مش موجود.';
            } else {
                $couponRejection = $this->adminRejectionReason($coupon, $subtotal);
                if ($couponRejection === null) {
                    try {
                        $coupon = $this->coupons->redeemableById($coupon->id, $subtotal, $customer);
                        $discount = $this->coupons->discountFor($coupon, $subtotal);
                    } catch (BusinessException) {
                        $couponRejection = 'الكوبون غير متاح لهذا العميل أو تغيّرت صلاحيته أثناء المحاكاة.';
                    }
                }
            }
        }

        $notes = [];
        if ($couponRejection !== null) {
            $notes[] = 'الكوبون مرفوض: '.$couponRejection;
        } elseif ($coupon?->type === 'free_shipping') {
            $notes[] = 'كوبون الشحن المجاني يخصم من الشحن عند الدفع. الإجمالي هنا للبضاعة.';
        }
        $now = Carbon::now();
        $promotions = $actor->can('promotions.view') ? Promotion::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('min_order_amount')->orWhere('min_order_amount', '<=', $subtotal))
            ->limit(50)
            ->get(['id', 'name', 'applies_to', 'target_ids']) : [];
        foreach ($promotions as $promotion) {
            if ($this->promotionMatches($promotion, $variantIds, $categoryIds, $brandIds)) {
                $notes[] = "العرض {$promotion->name} ينطبق نظريًا لكنه لن يُطبَّق في الدفع حاليًا.";
            }
        }
        $notes[] = 'العروض (promotions) بتتسجل بس ومش بتتطبق تلقائيًا على السلة حاليًا.';

        $total = round(max(0.0, $subtotal - $discount), 2);
        $rows = array_map(fn ($l) => [$l['sku'], (string) $l['qty'], (string) $l['unit_price'], (string) $l['line_total']], $lines);
        $rows[] = ['الإجمالي الفرعي', '', '', (string) $subtotal];
        $rows[] = ['خصم الكوبون'.($coupon ? ' ('.$coupon->code.')' : ''), '', '', (string) $discount];
        $rows[] = ['الإجمالي', '', '', (string) $total];

        return $this->presenter->present($actor, [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'coupon' => $coupon ? ['code' => $coupon->code, 'type' => $coupon->type, 'rejection' => $couponRejection] : null,
            'discount' => $discount,
            'total' => $total,
            'notes' => $notes,
        ], $this->presenter->table(['الصنف', 'الكمية', 'سعر الوحدة', 'الإجمالي'], $rows));
    }

    private function adminRejectionReason(Coupon $coupon, float $subtotal): ?string
    {
        $now = Carbon::now();
        if (! $coupon->is_active) {
            return 'الكوبون متوقف.';
        }
        if ($coupon->starts_at !== null && $coupon->starts_at->isFuture()) {
            return 'الكوبون لم يبدأ بعد.';
        }
        if ($coupon->ends_at !== null && $coupon->ends_at->lt($now)) {
            return 'الكوبون منتهي.';
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return 'الكوبون وصل للحد الأقصى للاستخدام.';
        }
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return 'السلة تحت الحد الأدنى للكوبون ('.$coupon->min_order_amount.').';
        }

        return null;
    }

    /**
     * @param  list<int>  $variantIds
     * @param  list<int>  $categoryIds
     * @param  list<int>  $brandIds
     */
    private function promotionMatches(Promotion $promotion, array $variantIds, array $categoryIds, array $brandIds): bool
    {
        $targets = array_map('intval', (array) ($promotion->target_ids ?? []));

        return match ($promotion->applies_to) {
            'all' => true,
            'variants' => count(array_intersect($targets, $variantIds)) > 0,
            'categories' => count(array_intersect($targets, $categoryIds)) > 0,
            'brands' => count(array_intersect($targets, $brandIds)) > 0,
            default => false,
        };
    }
}
