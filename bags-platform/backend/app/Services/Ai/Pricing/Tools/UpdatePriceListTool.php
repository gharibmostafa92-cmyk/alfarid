<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\Catalog\PriceListType;
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
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\PriceListService;
use App\Services\Auth\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class UpdatePriceListTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'update_price_list';
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
        return ['price_lists.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل بيانات قائمة أسعار (الاسم، الأولوية، التواريخ، النشاط) — مش البنود نفسها (دي upsert_price_list_items).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'name' => ['type' => 'STRING'],
                    'priority' => ['type' => 'INTEGER'],
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
        if (empty($args['price_list_id'])) {
            throw ValidationException::withMessages(['price_list_id' => 'حدد القائمة.']);
        }
        try {
            $list = $this->lists->find((int) $args['price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }

        $changes = [];
        foreach (['name', 'priority', 'starts_at', 'ends_at', 'is_active'] as $field) {
            if (array_key_exists($field, $args)) {
                $changes[$field] = $args[$field];
            }
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['price_list_id' => 'مفيش حقول للتعديل.']);
        }
        if (isset($changes['name']) && trim((string) $changes['name']) === '') {
            throw ValidationException::withMessages(['name' => 'الاسم ماينفعش يبقى فاضي.']);
        }
        try {
            $start = array_key_exists('starts_at', $changes) ? $changes['starts_at'] : $list->starts_at;
            $end = array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $list->ends_at;
            if ($start !== null && $end !== null && Carbon::parse($end)->lt(Carbon::parse($start))) {
                throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }

        $inverse = [];
        foreach (array_keys($changes) as $field) {
            $inverse[$field] = $list->getAttribute($field);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['price_list_id' => $list->id] + $changes,
            risk: $this->risk(),
            title: 'تعديل قائمة الأسعار: '.$list->name,
            before: ['name' => $list->name],
            after: ['name' => $changes['name'] ?? $list->name],
            expectedVersions: ['pl:'.$list->id => PricingFingerprint::priceList($list)],
            inverse: $inverse,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        PricingLocks::lockListAndItems($action, (int) $action->args['price_list_id'], [], (string) ($action->expected_versions['pl:'.$action->args['price_list_id']] ?? ''));
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $args = (array) $action->args;
        $listId = (int) $args['price_list_id'];
        unset($args['price_list_id']);
        $locked = PricingLocks::lockListAndItems($action, $listId, [], (string) ($action->expected_versions['pl:'.$listId] ?? ''))['list'];
        $updated = $this->lists->update($locked, $args, $actor);

        StaffAdministration::audit(
            $actor,
            'price_list.updated',
            'price_list',
            $updated->id,
            ['name'],
            ['name' => $locked->name],
            ['name' => $updated->name],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم تعديل قائمة الأسعار.', [
            'price_list_id' => $updated->id,
            'deep_link' => '#/pricing?list='.$updated->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && $action->inverse !== [];
    }

    public function targetState(AiPendingAction $action): string
    {
        $list = \App\Models\PriceList::query()->find((int) $action->args['price_list_id']);

        return $list ? PricingFingerprint::priceList($list) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockListAndItems($action, (int) $action->args['price_list_id'], [], (string) ($action->expected_versions['pl:'.$action->args['price_list_id']] ?? ''))['list'];
        $this->lists->update($locked, (array) $action->inverse, $actor);

        return ToolOutcome::result(true, 'تم التراجع عن تعديل قائمة الأسعار.', [
            'price_list_id' => $locked->id,
            'deep_link' => '#/pricing?list='.$locked->id,
        ]);
    }
}
