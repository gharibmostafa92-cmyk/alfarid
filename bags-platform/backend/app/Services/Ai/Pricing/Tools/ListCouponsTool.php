<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Services\Shared\SearchTerm;

use App\Models\Coupon;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use Carbon\Carbon;

final class ListCouponsTool implements AgentTool
{
    public function __construct(private PricingPresenter $presenter) {}

    public function name(): string
    {
        return 'list_coupons';
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
            'description' => 'اعرض الكوبونات مع فلاتر (نشط، منتهي، قريب من الحد، كود) وعدد الاستخدام مقابل الحد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'code' => ['type' => 'STRING'],
                    'active' => ['type' => 'BOOLEAN'],
                    'expired' => ['type' => 'BOOLEAN'],
                    'near_limit' => ['type' => 'BOOLEAN'],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $now = Carbon::now();

        $coupons = Coupon::query()
            ->when(! empty($args['code']), fn ($q) => $q->whereRaw('UPPER(code) LIKE ?', [\App\Services\Shared\SearchTerm::pattern(mb_strtoupper(trim((string) $args['code'])))]))
            ->when(array_key_exists('active', $args), fn ($q) => $q->where('is_active', (bool) $args['active']))
            ->when(! empty($args['expired']), fn ($q) => $q->whereNotNull('ends_at')->where('ends_at', '<', $now))
            ->when(! empty($args['near_limit']), fn ($q) => $q->whereNotNull('usage_limit')->whereRaw('used_count >= 0.9 * usage_limit'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = [];
        $data = [];
        foreach ($coupons as $coupon) {
            $usage = $coupon->usage_limit !== null ? $coupon->used_count.'/'.$coupon->usage_limit : (string) $coupon->used_count;
            $status = ! $coupon->is_active ? 'متوقف' : ($coupon->ends_at !== null && $coupon->ends_at->lt($now) ? 'منتهي' : 'نشط');
            $rows[] = [$coupon->code, $coupon->type, (string) $coupon->value, $coupon->min_order_amount !== null ? (string) $coupon->min_order_amount : '—', $usage, $coupon->ends_at?->format('Y-m-d') ?? '—', $status];
            $data[] = [
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
            ];
        }

        return $this->presenter->present($actor, [
            'coupons' => $data,
            'count' => count($data),
        ], $this->presenter->table(
            ['الكود', 'النوع', 'القيمة', 'الحد الأدنى', 'مستخدم/الحد', 'ينتهي في', 'الحالة'],
            $rows
        ));
    }
}
