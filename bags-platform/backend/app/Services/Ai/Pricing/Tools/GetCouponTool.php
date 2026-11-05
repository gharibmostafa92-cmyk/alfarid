<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\Coupon;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use Carbon\Carbon;

final class GetCouponTool implements AgentTool
{
    public function __construct(private PricingPresenter $presenter) {}

    public function name(): string
    {
        return 'get_coupon';
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
        return ['coupons.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اعرض تفاصيل كوبون واحد (القيمة، الحدود، الاستخدام، الصلاحية) — للسؤال "الكوبون ده شغال؟".',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'coupon_id' => ['type' => 'INTEGER'],
                    'code' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $coupon = null;
        if (! empty($args['coupon_id'])) {
            $coupon = Coupon::query()->find((int) $args['coupon_id']);
        } elseif (! empty($args['code'])) {
            $coupon = Coupon::query()->where('code', strtoupper(trim((string) $args['code'])))->first();
        }
        if (! $coupon) {
            return ToolOutcome::denied('الكوبون مش موجود.', auditable: false);
        }

        $now = Carbon::now();
        $status = ! $coupon->is_active ? 'متوقف'
            : ($coupon->starts_at !== null && $coupon->starts_at->isFuture() ? 'مجدول'
            : ($coupon->ends_at !== null && $coupon->ends_at->lt($now) ? 'منتهي'
            : ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit ? 'مستنفد' : 'نشط')));

        return $this->presenter->present($actor, [
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'min_order_amount' => $coupon->min_order_amount,
                'max_discount_amount' => $coupon->max_discount_amount,
                'usage_limit' => $coupon->usage_limit,
                'usage_limit_per_customer' => $coupon->usage_limit_per_customer,
                'used_count' => $coupon->used_count,
                'starts_at' => $coupon->starts_at?->toIso8601String(),
                'ends_at' => $coupon->ends_at?->toIso8601String(),
                'is_active' => (bool) $coupon->is_active,
                'status' => $status,
            ],
        ], $this->presenter->table(
            ['الكود', 'النوع', 'القيمة', 'الحد الأدنى', 'مستخدم/الحد', 'الحالة'],
            [[$coupon->code, $coupon->type, (string) $coupon->value, $coupon->min_order_amount !== null ? (string) $coupon->min_order_amount : '—', $coupon->usage_limit !== null ? $coupon->used_count.'/'.$coupon->usage_limit : (string) $coupon->used_count, $status]]
        ));
    }
}
