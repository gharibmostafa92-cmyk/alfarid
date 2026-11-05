<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\CouponService;
use App\Services\PricingService;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class CreateCouponsTool implements WriteTool
{
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
        private SettingService $settings,
        private PricingService $pricing,
        private CouponService $coupons,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'create_coupons';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['coupons.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء كوبون واحد أو دفعة (لحد 200) بأكواد مولدة بحروف غير ملتبسة. الكوبون المفتوح (من غير حد استخدام ولا تاريخ انتهاء) عالي الخطورة. used_count لا يُمس أبدًا من أي أداة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['type'],
                'properties' => (object) [
                    'code' => ['type' => 'STRING'],
                    'prefix' => ['type' => 'STRING'],
                    'count' => ['type' => 'INTEGER'],
                    'type' => ['type' => 'STRING', 'enum' => ['percentage', 'fixed', 'free_shipping']],
                    'value' => ['type' => 'NUMBER'],
                    'min_order_amount' => ['type' => 'NUMBER'],
                    'max_discount_amount' => ['type' => 'NUMBER'],
                    'usage_limit' => ['type' => 'INTEGER'],
                    'usage_limit_per_customer' => ['type' => 'INTEGER'],
                    'starts_at' => ['type' => 'STRING'],
                    'ends_at' => ['type' => 'STRING'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public static function generateCode(?string $prefix, int $length = 8): string
    {
        $random = '';
        for ($i = 0; $i < $length; $i++) {
            $random .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $clean = $prefix !== null ? preg_replace('/[^A-Z0-9-]/', '', mb_strtoupper(trim($prefix))) ?? '' : '';

        return ($clean !== '' ? $clean.'-' : '').$random;
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $type = (string) ($args['type'] ?? '');
        if (! in_array($type, ['percentage', 'fixed', 'free_shipping'], true)) {
            throw ValidationException::withMessages(['type' => 'نوع الكوبون لازم يكون percentage أو fixed أو free_shipping.']);
        }

        $value = null;
        if ($type !== 'free_shipping') {
            if (! isset($args['value']) || ! is_numeric($args['value'])) {
                throw ValidationException::withMessages(['value' => 'قيمة الكوبون مطلوبة.']);
            }
            $value = (float) $args['value'];
            if ($value < 0 || ($type === 'percentage' && $value > 100)) {
                throw ValidationException::withMessages(['value' => 'قيمة الكوبون غير صالحة (النسبة لا تتجاوز 100%).']);
            }
        }

        foreach (['min_order_amount', 'max_discount_amount'] as $field) {
            if (isset($args[$field]) && (! is_numeric($args[$field]) || (float) $args[$field] < 0)) {
                throw ValidationException::withMessages([$field => 'لازم يكون رقم موجب.']);
            }
        }
        foreach (['usage_limit' => [1, 1000000], 'usage_limit_per_customer' => [0, 1000000]] as $field => [$min, $max]) {
            if (isset($args[$field]) && (! is_numeric($args[$field]) || (int) $args[$field] < $min || (int) $args[$field] > $max)) {
                throw ValidationException::withMessages([$field => "لازم يكون بين {$min} و{$max}."]);
            }
        }
        $startsAt = $this->optionalDate($args['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->optionalDate($args['ends_at'] ?? null, 'ends_at');
        if ($startsAt === false || $endsAt === false) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt->lt($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
        }

        $count = isset($args['count']) && is_numeric($args['count']) ? max(1, (int) $args['count']) : 1;
        if ($count > 200) {
            throw ValidationException::withMessages(['count' => 'الحد الأقصى 200 كوبون في الدفعة الواحدة.']);
        }

        $codes = [];
        if ($count === 1 && isset($args['code']) && trim((string) $args['code']) !== '') {
            $code = mb_strtoupper(trim((string) $args['code']));
            if (mb_strlen($code) > 50) {
                throw ValidationException::withMessages(['code' => 'الكود أطول من 50 حرف.']);
            }
            $this->assertCodeFree($code);
            $codes[] = $code;
        } else {
            $prefix = isset($args['prefix']) ? mb_substr(trim((string) $args['prefix']), 0, 20) : null;
            $attempts = 0;
            while (count($codes) < $count && $attempts < $count * 10 + 20) {
                $attempts++;
                $code = self::generateCode($prefix);
                if (in_array($code, $codes, true) || ! $this->isCodeFree($code)) {
                    continue;
                }
                $codes[] = $code;
            }
            if (count($codes) < $count) {
                throw ValidationException::withMessages(['prefix' => 'تعذر توليد أكواد فريدة؛ جرّب prefix مختلف.']);
            }
        }

        $canViewCost = CostAccess::canView($actor);
        $warnings = [];
        $highRiskPercent = (float) $this->settings->get('ai_coupon_high_risk_percent', 30);
        $isHighPercent = $type === 'percentage' && $value !== null && $value > $highRiskPercent;
        $isOpen = ! isset($args['usage_limit']) && ! isset($args['ends_at']);
        $risk = ($isHighPercent || ! isset($args['usage_limit']) || ! isset($args['ends_at'])) ? 'high' : 'medium';

        if ($isOpen) {
            $warnings[] = 'الكوبون ده ممكن يتسرّب على السوشيال ويفضل شغال للأبد.';
        }
        if ($type === 'percentage' && $value !== null && $value >= 20 && ! isset($args['max_discount_amount'])) {
            $warnings[] = 'النسبة كبيرة من غير سقف — اقترح max_discount_amount يحمي الهامش.';
        }
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, []);
        $conflictCount = $type === 'percentage' && $value !== null && is_array($scope)
            ? $this->countNearCostVariants($value, isset($args['max_discount_amount']) ? (float) $args['max_discount_amount'] : null, $scope['branchId'])
            : 0;
        if ($conflictCount > 0) {
            $warnings[] = $canViewCost
                ? "{$conflictCount} صنف في العينة قد يتباع تحت التكلفة مع هذا الكوبون."
                : "{$conflictCount} صنف قد تتأثر هوامشها (أسباب تسعير محمية).";
        }

        $payload = [
            'codes' => $codes,
            'type' => $type,
            'value' => $value,
            'min_order_amount' => isset($args['min_order_amount']) ? (float) $args['min_order_amount'] : null,
            'max_discount_amount' => isset($args['max_discount_amount']) ? (float) $args['max_discount_amount'] : null,
            'usage_limit' => isset($args['usage_limit']) ? (int) $args['usage_limit'] : null,
            'usage_limit_per_customer' => isset($args['usage_limit_per_customer']) ? (int) $args['usage_limit_per_customer'] : 1,
            'starts_at' => $startsAt?->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
            'is_active' => array_key_exists('is_active', $args) ? (bool) $args['is_active'] : true,
        ];

        return $this->proposals->make(
            $actor,
            $this->name(),
            $payload,
            risk: $risk,
            title: $count === 1 ? 'إنشاء كوبون: '.$codes[0] : "إنشاء {$count} كوبون",
            before: ['coupons_count' => 0],
            after: ['codes' => $codes, 'type' => $type, 'value' => $value],
            warnings: $warnings,
            typedConfirmation: $risk === 'high' ? $codes[0] : null,
            typedConfirmationHint: $count === 1 ? 'اكتب الكود' : 'اكتب أول كود في الدفعة',
            extraPreview: [
                'block_type' => 'coupon_batch',
                'summary' => ['count' => count($codes), 'type' => $type, 'value' => $value],
                'rows' => array_map(fn (string $code) => ['code' => $code], $codes),
            ],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $payload = (array) $action->args;
        $created = [];
        foreach ((array) ($payload['codes'] ?? []) as $code) {
            $code = mb_strtoupper(trim((string) $code));
            // Re-verify inside the transaction; the unique index is the last line of defense (23505 → 409).
            if (Coupon::query()->whereRaw('upper(code) = ?', [$code])->exists()) {
                abort(409, 'رمز الكوبون مستخدم بالفعل: '.$code);
            }
            $coupon = Coupon::query()->create([
                'code' => $code,
                'type' => $payload['type'],
                'value' => $payload['value'] ?? 0,
                'min_order_amount' => $payload['min_order_amount'] ?? null,
                'max_discount_amount' => $payload['max_discount_amount'] ?? null,
                'usage_limit' => $payload['usage_limit'] ?? null,
                'usage_limit_per_customer' => $payload['usage_limit_per_customer'] ?? 1,
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'is_active' => $payload['is_active'] ?? true,
            ]);
            $created[] = $coupon->code;
            StaffAdministration::audit(
                $actor,
                'coupon.created',
                'coupon',
                $coupon->id,
                ['code', 'type'],
                [],
                ['code' => $coupon->code, 'type' => $coupon->type],
                ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'coupon:'.$coupon->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
            );
        }

        return ToolOutcome::result(true, count($created) === 1 ? 'تم إنشاء الكوبون '.$created[0].'.' : 'تم إنشاء '.count($created).' كوبون.', [
            'codes' => $created,
            'count' => count($created),
        ], [
            'type' => 'coupon_batch',
            'codes' => $created,
            'count' => count($created),
        ]);
    }

    private function assertCodeFree(string $code): void
    {
        if (! $this->isCodeFree($code)) {
            throw ValidationException::withMessages(['code' => 'رمز الكوبون مستخدم بالفعل.']);
        }
    }

    private function isCodeFree(string $code): bool
    {
        return ! Coupon::query()->whereRaw('upper(code) = ?', [mb_strtoupper($code)])->exists();
    }

    private function optionalDate(mixed $value, string $field): Carbon|bool|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim((string) $value));
        } catch (\Throwable) {
            return false;
        }
    }

    private function countNearCostVariants(float $percent, ?float $maxDiscount, ?int $branchId): int
    {
        $coupon = new Coupon(['type' => 'percentage', 'value' => $percent, 'max_discount_amount' => $maxDiscount]);
        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->where('cost_price', '>', 0)
            ->orderByRaw('COALESCE(LEAST(retail_price, wholesale_price), 0) - cost_price')
            ->orderBy('id')->limit(50)->get();
        $flagged = [];
        foreach (CustomerType::cases() as $channel) {
            $customer = new Customer(['type' => $channel, 'status' => CustomerStatus::Approved]);
            $context = $this->pricing->contextFor($customer, $branchId === null ? $variants->modelKeys() : null);
            foreach ($variants as $variant) {
                try {
                    $price = $this->pricing->resolve($variant, $customer, 1, $branchId, $context)['unit_price'];
                } catch (BusinessException) {
                    continue;
                }
                if ($price - $this->coupons->discountFor($coupon, $price) < (float) $variant->cost_price) {
                    $flagged[$variant->id] = true;
                }
            }
        }

        return count($flagged);
    }
}
