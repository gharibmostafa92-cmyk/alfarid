<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\AiPendingAction;
use App\Models\Promotion;
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
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class SetPromotionStatusTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'set_promotion_status';
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
        return ['promotions.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تفعيل أو تعطيل عرض ترويجي.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['promotion_id', 'is_active'],
                'properties' => (object) [
                    'promotion_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['promotion_id']) || ! array_key_exists('is_active', $args)) {
            throw ValidationException::withMessages(['promotion_id' => 'حدد العرض والحالة المطلوبة.']);
        }
        $promotion = Promotion::query()->find((int) $args['promotion_id']);
        if (! $promotion) {
            return ToolOutcome::denied('العرض مش موجود.', auditable: false);
        }
        $wanted = (bool) $args['is_active'];
        if ((bool) $promotion->is_active === $wanted) {
            throw ValidationException::withMessages(['is_active' => 'العرض بالفعل '.($wanted ? 'نشط' : 'متوقف').'.']);
        }
        $notice = CreatePromotionTool::autoAppliedNotice($promotion);

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['promotion_id' => $promotion->id, 'is_active' => $wanted],
            risk: $this->risk(),
            title: ($wanted ? 'تفعيل' : 'تعطيل').' العرض: '.$promotion->name,
            before: ['is_active' => (bool) $promotion->is_active],
            after: ['is_active' => $wanted],
            warnings: $notice !== null ? [$notice] : [],
            expectedVersions: ['promotion:'.$promotion->id => PricingFingerprint::promotion($promotion)],
            inverse: ['is_active' => (bool) $promotion->is_active],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        PricingLocks::lockPromotion($action, (int) $action->args['promotion_id'], (string) ($action->expected_versions['promotion:'.$action->args['promotion_id']] ?? ''));
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockPromotion($action, (int) $action->args['promotion_id'], (string) ($action->expected_versions['promotion:'.$action->args['promotion_id']] ?? ''));
        $locked->update(['is_active' => (bool) $action->args['is_active']]);
        $promotion = $locked->fresh();

        StaffAdministration::audit(
            $actor,
            'promotion.status_changed',
            'promotion',
            $promotion->id,
            ['name'],
            ['name' => $promotion->name],
            ['name' => $promotion->name],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'promotion:'.$promotion->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, (bool) $action->args['is_active'] ? 'تم تفعيل العرض.' : 'تم تعطيل العرض.', ['promotion_id' => $promotion->id]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('is_active', $action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $promotion = Promotion::query()->find((int) $action->args['promotion_id']);

        return $promotion ? PricingFingerprint::promotion($promotion) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockPromotion($action, (int) $action->args['promotion_id'], (string) ($action->expected_versions['promotion:'.$action->args['promotion_id']] ?? ''));
        $locked->update(['is_active' => (bool) $action->inverse['is_active']]);

        return ToolOutcome::result(true, 'تم التراجع عن تغيير حالة العرض.', ['promotion_id' => $locked->id]);
    }
}
