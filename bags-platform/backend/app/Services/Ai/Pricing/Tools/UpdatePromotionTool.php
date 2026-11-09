<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\AiPendingAction;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
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

final class UpdatePromotionTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private EntityResolver $resolver,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'update_promotion';
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
        return ['promotions.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل عرض (القيمة، الحدود، النطاق، التواريخ، النشاط). النوع buy_x_get_y ممنوع.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['promotion_id'],
                'properties' => (object) [
                    'promotion_id' => ['type' => 'INTEGER'],
                    'name' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => ['percentage_discount', 'fixed_discount', 'free_shipping']],
                    'value' => ['type' => 'NUMBER'],
                    'min_order_amount' => ['type' => 'NUMBER'],
                    'targets' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT']],
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
        if (empty($args['promotion_id'])) {
            throw ValidationException::withMessages(['promotion_id' => 'حدد العرض.']);
        }
        $promotion = Promotion::query()->find((int) $args['promotion_id']);
        if (! $promotion) {
            return ToolOutcome::denied('العرض مش موجود.', auditable: false);
        }

        $changes = [];
        if (array_key_exists('name', $args)) {
            if (trim((string) $args['name']) === '') {
                throw ValidationException::withMessages(['name' => 'الاسم ماينفعش يبقى فاضي.']);
            }
            $changes['name'] = trim((string) $args['name']);
        }
        if (array_key_exists('type', $args)) {
            if ($args['type'] === 'buy_x_get_y' || ! in_array($args['type'], ['percentage_discount', 'fixed_discount', 'free_shipping'], true)) {
                throw ValidationException::withMessages(['type' => 'نوع العرض غير صالح (buy_x_get_y ممنوع).']);
            }
            $changes['type'] = $args['type'];
        }
        $effectiveType = $changes['type'] ?? $promotion->type;
        if (array_key_exists('value', $args)) {
            if ($effectiveType !== 'free_shipping' && (! is_numeric($args['value']) || (float) $args['value'] < 0)) {
                throw ValidationException::withMessages(['value' => 'قيمة غير صالحة.']);
            }
            if ($effectiveType === 'percentage_discount' && (float) $args['value'] > 100) {
                throw ValidationException::withMessages(['value' => 'نسبة الخصم لا تتجاوز 100%.']);
            }
            $changes['value'] = (float) $args['value'];
        }
        if (array_key_exists('min_order_amount', $args)) {
            if ($args['min_order_amount'] !== null && (! is_numeric($args['min_order_amount']) || (float) $args['min_order_amount'] < 0)) {
                throw ValidationException::withMessages(['min_order_amount' => 'لازم يكون رقم موجب أو فاضي.']);
            }
            $changes['min_order_amount'] = $args['min_order_amount'] === null ? null : (float) $args['min_order_amount'];
        }
        if (array_key_exists('targets', $args)) {
            [$appliesTo, $targetIds] = CreatePromotionTool::resolveTargets($this->resolver, $actor, (array) $args['targets']);
            $changes['applies_to'] = $appliesTo;
            $changes['target_ids'] = $targetIds;
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $args)) {
                $changes[$field] = $args[$field] === null || trim((string) $args[$field]) === '' ? null : trim((string) $args[$field]);
            }
        }
        $startEff = $changes['starts_at'] ?? $promotion->starts_at;
        $endEff = $changes['ends_at'] ?? $promotion->ends_at;
        try {
            if ($startEff !== null && $endEff !== null && \Carbon\Carbon::parse($endEff)->lt(\Carbon\Carbon::parse($startEff))) {
                throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }
        if (array_key_exists('is_active', $args)) {
            $changes['is_active'] = (bool) $args['is_active'];
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['promotion_id' => 'مفيش حقول للتعديل.']);
        }

        $inverse = [];
        foreach (array_keys($changes) as $field) {
            $inverse[$field] = $promotion->getAttribute($field);
        }

        $notice = CreatePromotionTool::autoAppliedNotice($promotion);

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['promotion_id' => $promotion->id] + $changes,
            risk: $this->risk(),
            title: 'تعديل العرض: '.$promotion->name,
            before: ['name' => $promotion->name],
            after: ['name' => $changes['name'] ?? $promotion->name],
            warnings: $notice !== null ? [$notice] : [],
            expectedVersions: ['promotion:'.$promotion->id => PricingFingerprint::promotion($promotion)],
            inverse: $inverse,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        PricingLocks::lockPromotion($action, (int) $action->args['promotion_id'], (string) ($action->expected_versions['promotion:'.$action->args['promotion_id']] ?? ''));
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockPromotion($action, (int) $action->args['promotion_id'], (string) ($action->expected_versions['promotion:'.$action->args['promotion_id']] ?? ''));
        $changes = (array) $action->args;
        unset($changes['promotion_id']);
        $locked->update($changes);
        $promotion = $locked->fresh();
        $notice = CreatePromotionTool::autoAppliedNotice($promotion);

        StaffAdministration::audit(
            $actor,
            'promotion.updated',
            'promotion',
            $promotion->id,
            ['name', 'type'],
            ['name' => $promotion->name],
            ['name' => $promotion->name, 'type' => $promotion->type],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'promotion:'.$promotion->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم تعديل العرض.'.($notice !== null ? ' '.$notice : ''), [
            'promotion_id' => $promotion->id,
            'auto_applied' => $notice === null,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && $action->inverse !== [];
    }

    public function targetState(AiPendingAction $action): string
    {
        $promotion = Promotion::query()->find((int) $action->args['promotion_id']);

        return $promotion ? PricingFingerprint::promotion($promotion) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockPromotion($action, (int) $action->args['promotion_id'], (string) ($action->expected_versions['promotion:'.$action->args['promotion_id']] ?? ''));
        $locked->update((array) $action->inverse);

        return ToolOutcome::result(true, 'تم التراجع عن تعديل العرض.', ['promotion_id' => $locked->id]);
    }
}
