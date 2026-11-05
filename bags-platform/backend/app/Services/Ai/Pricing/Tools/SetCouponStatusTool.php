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
use Illuminate\Validation\ValidationException;

final class SetCouponStatusTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'set_coupon_status';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['coupons.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تفعيل أو تعطيل كوبون (البديل الآمن للحذف — الإنشاء مالوش تراجع).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['coupon_id', 'is_active'],
                'properties' => (object) [
                    'coupon_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['coupon_id']) || ! array_key_exists('is_active', $args)) {
            throw ValidationException::withMessages(['coupon_id' => 'حدد الكوبون والحالة المطلوبة.']);
        }
        $coupon = \App\Models\Coupon::query()->find((int) $args['coupon_id']);
        if (! $coupon) {
            return ToolOutcome::denied('الكوبون مش موجود.', auditable: false);
        }
        $wanted = (bool) $args['is_active'];
        if ((bool) $coupon->is_active === $wanted) {
            throw ValidationException::withMessages(['is_active' => 'الكوبون بالفعل '.($wanted ? 'نشط' : 'متوقف').'.']);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['coupon_id' => $coupon->id, 'is_active' => $wanted],
            risk: $this->risk(),
            title: ($wanted ? 'تفعيل' : 'تعطيل').' الكوبون: '.$coupon->code,
            before: ['is_active' => (bool) $coupon->is_active],
            after: ['is_active' => $wanted],
            expectedVersions: ['coupon:'.$coupon->id => PricingFingerprint::coupon($coupon)],
            inverse: ['is_active' => (bool) $coupon->is_active],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        PricingLocks::lockCoupon($action, (int) $action->args['coupon_id'], (string) ($action->expected_versions['coupon:'.$action->args['coupon_id']] ?? ''));
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockCoupon($action, (int) $action->args['coupon_id'], (string) ($action->expected_versions['coupon:'.$action->args['coupon_id']] ?? ''));
        $locked->update(['is_active' => (bool) $action->args['is_active']]);
        $coupon = $locked->fresh();

        StaffAdministration::audit(
            $actor,
            'coupon.status_changed',
            'coupon',
            $coupon->id,
            ['code'],
            ['code' => $coupon->code],
            ['code' => $coupon->code],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'coupon:'.$coupon->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, (bool) $action->args['is_active'] ? 'تم تفعيل الكوبون.' : 'تم تعطيل الكوبون.', ['coupon_id' => $coupon->id, 'code' => $coupon->code]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('is_active', $action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $coupon = \App\Models\Coupon::query()->find((int) $action->args['coupon_id']);

        return $coupon ? PricingFingerprint::coupon($coupon) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockCoupon($action, (int) $action->args['coupon_id'], (string) ($action->expected_versions['coupon:'.$action->args['coupon_id']] ?? ''));
        $locked->update(['is_active' => (bool) $action->inverse['is_active']]);

        return ToolOutcome::result(true, 'تم التراجع عن تغيير حالة الكوبون.', ['coupon_id' => $locked->id]);
    }
}
