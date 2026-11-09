<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Models\AiPendingAction;
use App\Models\Customer;
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
use App\Services\Pricing\PriceListService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class SetPriceListStatusTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'set_price_list_status';
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
            'description' => 'تفعيل أو تعطيل قائمة أسعار (تحذير لو القائمة بتخدم عملاء دلوقتي).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id', 'is_active'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['price_list_id']) || ! array_key_exists('is_active', $args)) {
            throw ValidationException::withMessages(['price_list_id' => 'حدد القائمة والحالة المطلوبة.']);
        }
        try {
            $list = $this->lists->find((int) $args['price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }
        $wanted = (bool) $args['is_active'];
        if ((bool) $list->is_active === $wanted) {
            throw ValidationException::withMessages(['is_active' => 'القائمة بالفعل '.($wanted ? 'نشطة' : 'متوقفة').'.']);
        }

        $warnings = [];
        if (! $wanted) {
            $affected = $this->affectedCustomers($list);
            if ($affected > 0) {
                $warnings[] = "القائمة بتخدم {$affected} عميل دلوقتي — التعطيل هيغيّر أسعارهم فورًا.";
            }
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['price_list_id' => $list->id, 'is_active' => $wanted],
            risk: $this->risk(),
            title: ($wanted ? 'تفعيل' : 'تعطيل').' قائمة الأسعار: '.$list->name,
            before: ['is_active' => (bool) $list->is_active],
            after: ['is_active' => $wanted],
            warnings: $warnings,
            expectedVersions: ['pl:'.$list->id => PricingFingerprint::priceList($list)],
            inverse: ['is_active' => (bool) $list->is_active],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        PricingLocks::lockListAndItems($action, (int) $action->args['price_list_id'], [], (string) ($action->expected_versions['pl:'.$action->args['price_list_id']] ?? ''));
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockListAndItems($action, (int) $action->args['price_list_id'], [], (string) ($action->expected_versions['pl:'.$action->args['price_list_id']] ?? ''))['list'];
        $updated = $this->lists->update($locked, ['is_active' => (bool) $action->args['is_active']], $actor);

        StaffAdministration::audit(
            $actor,
            'price_list.status_changed',
            'price_list',
            $updated->id,
            ['is_active'],
            ['is_active' => ! (bool) $action->args['is_active']],
            ['is_active' => (bool) $updated->is_active],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, (bool) $action->args['is_active'] ? 'تم تفعيل قائمة الأسعار.' : 'تم تعطيل قائمة الأسعار.', [
            'price_list_id' => $updated->id,
            'deep_link' => '#/pricing?list='.$updated->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('is_active', $action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $list = \App\Models\PriceList::query()->find((int) $action->args['price_list_id']);

        return $list ? PricingFingerprint::priceList($list) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $locked = PricingLocks::lockListAndItems($action, (int) $action->args['price_list_id'], [], (string) ($action->expected_versions['pl:'.$action->args['price_list_id']] ?? ''))['list'];
        $this->lists->update($locked, ['is_active' => (bool) $action->inverse['is_active']], $actor);

        return ToolOutcome::result(true, 'تم التراجع عن تغيير حالة القائمة.', [
            'price_list_id' => $locked->id,
            'deep_link' => '#/pricing?list='.$locked->id,
        ]);
    }

    private function affectedCustomers(\App\Models\PriceList $list): int
    {
        $type = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
        if ($type === PriceListType::CustomerSpecific->value) {
            return Customer::query()->whereKey($list->customer_id)->where('status', CustomerStatus::Approved)->exists() ? 1 : 0;
        }
        if ($type === PriceListType::CustomerGroup->value) {
            return Customer::query()->where('customer_group_id', $list->customer_group_id)->where('status', CustomerStatus::Approved)->count();
        }
        $channelTypes = array_filter([$type === PriceListType::Wholesale->value ? CustomerType::Wholesale : null, $type === PriceListType::Distributor->value ? CustomerType::Distributor : null]);
        if ($channelTypes !== []) {
            return Customer::query()->whereIn('type', $channelTypes)->where('status', CustomerStatus::Approved)->count();
        }

        return 0;
    }
}
