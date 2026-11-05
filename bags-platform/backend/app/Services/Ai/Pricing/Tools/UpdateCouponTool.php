<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingFingerprint;
use App\Services\Ai\Pricing\PricingLocks;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class UpdateCouponTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'update_coupon';
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
        return ['coupons.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل كوبون (القيمة، الحدود، الاستخدام، التواريخ، النشاط). الكود والنوع ثابتان — وused_count لا يُمس أبدًا.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['coupon_id'],
                'properties' => (object) [
                    'coupon_id' => ['type' => 'INTEGER'],
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

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['coupon_id'])) {
            throw ValidationException::withMessages(['coupon_id' => 'حدد الكوبون.']);
        }
        $coupon = \App\Models\Coupon::query()->find((int) $args['coupon_id']);
        if (! $coupon) {
            return ToolOutcome::denied('الكوبون مش موجود.', auditable: false);
        }

        $changes = [];
        if (array_key_exists('value', $args)) {
            if (! is_numeric($args['value']) || (float) $args['value'] < 0 || ($coupon->type === 'percentage' && (float) $args['value'] > 100)) {
                throw ValidationException::withMessages(['value' => 'قيمة غير صالحة (النسبة لا تتجاوز 100%).']);
            }
            $changes['value'] = (float) $args['value'];
        }
        foreach (['min_order_amount', 'max_discount_amount'] as $field) {
            if (array_key_exists($field, $args)) {
                if ($args[$field] !== null && (! is_numeric($args[$field]) || (float) $args[$field] < 0)) {
                    throw ValidationException::withMessages([$field => 'لازم يكون رقم موجب أو فاضي.']);
                }
                $changes[$field] = $args[$field] === null ? null : (float) $args[$field];
            }
        }
        if (array_key_exists('usage_limit', $args)) {
            if ($args['usage_limit'] !== null && (! is_numeric($args['usage_limit']) || (int) $args['usage_limit'] < 1 || (int) $args['usage_limit'] > 1000000)) {
                throw ValidationException::withMessages(['usage_limit' => 'لازم يكون بين 1 ومليون أو فاضي.']);
            }
            $newLimit = $args['usage_limit'] === null ? null : (int) $args['usage_limit'];
            if ($newLimit !== null && $newLimit < (int) $coupon->used_count) {
                throw ValidationException::withMessages(['usage_limit' => 'الحد الجديد أقل من الاستخدام الحالي ('.$coupon->used_count.')؛ الكوبون هيبقى ميت.']);
            }
            $changes['usage_limit'] = $newLimit;
        }
        if (array_key_exists('usage_limit_per_customer', $args)) {
            if (! is_numeric($args['usage_limit_per_customer']) || (int) $args['usage_limit_per_customer'] < 0 || (int) $args['usage_limit_per_customer'] > 1000000) {
                throw ValidationException::withMessages(['usage_limit_per_customer' => 'لازم يكون بين 0 ومليون.']);
            }
            $changes['usage_limit_per_customer'] = (int) $args['usage_limit_per_customer'];
        }
        $startsAt = array_key_exists('starts_at', $args) ? $this->optionalDate($args['starts_at']) : $coupon->starts_at;
        $endsAt = array_key_exists('ends_at', $args) ? $this->optionalDate($args['ends_at']) : $coupon->ends_at;
        if ($startsAt === false || $endsAt === false) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }
        if (array_key_exists('starts_at', $args)) {
            $changes['starts_at'] = $startsAt?->format('Y-m-d H:i:s');
        }
        if (array_key_exists('ends_at', $args)) {
            $changes['ends_at'] = $endsAt?->format('Y-m-d H:i:s');
        }
        $startEff = $startsAt instanceof Carbon ? $startsAt : ($coupon->starts_at ? Carbon::parse($coupon->starts_at) : null);
        $endEff = $endsAt instanceof Carbon ? $endsAt : ($coupon->ends_at ? Carbon::parse($coupon->ends_at) : null);
        if ($startEff && $endEff && $endEff->lt($startEff)) {
            throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
        }
        if (array_key_exists('is_active', $args)) {
            $changes['is_active'] = (bool) $args['is_active'];
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['coupon_id' => 'مفيش حقول للتعديل. used_count والكود والنوع لا تتعدل.']);
        }

        $inverse = [];
        foreach (array_keys($changes) as $field) {
            $inverse[$field] = $coupon->getAttribute($field);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['coupon_id' => $coupon->id] + $changes,
            risk: $this->risk(),
            title: 'تعديل الكوبون: '.$coupon->code,
            before: ['code' => $coupon->code],
            after: ['code' => $coupon->code, 'changes' => array_keys($changes)],
            expectedVersions: ['coupon:'.$coupon->id => PricingFingerprint::coupon($coupon)],
            inverse: $inverse,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        PricingLocks::lockCoupon($action, (int) $action->args['coupon_id'], (string) ($action->expected_versions['coupon:'.$action->args['coupon_id']] ?? ''));
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockCoupon($action, (int) $action->args['coupon_id'], (string) ($action->expected_versions['coupon:'.$action->args['coupon_id']] ?? ''));
        $changes = (array) $action->args;
        unset($changes['coupon_id']);
        $locked->update($changes);
        $coupon = $locked->fresh();

        StaffAdministration::audit(
            $actor,
            'coupon.updated',
            'coupon',
            $coupon->id,
            ['code', 'type'],
            ['code' => $coupon->code],
            ['code' => $coupon->code, 'type' => $coupon->type],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'coupon:'.$coupon->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم تعديل الكوبون '.$coupon->code.'.', ['coupon_id' => $coupon->id, 'code' => $coupon->code]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && $action->inverse !== [];
    }

    public function targetState(AiPendingAction $action): string
    {
        $coupon = \App\Models\Coupon::query()->find((int) $action->args['coupon_id']);

        return $coupon ? PricingFingerprint::coupon($coupon) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockCoupon($action, (int) $action->args['coupon_id'], (string) ($action->expected_versions['coupon:'.$action->args['coupon_id']] ?? ''));
        $locked->update((array) $action->inverse);

        return ToolOutcome::result(true, 'تم التراجع عن تعديل الكوبون.', ['coupon_id' => $locked->id]);
    }

    private function optionalDate(mixed $value): Carbon|bool|null
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
}
