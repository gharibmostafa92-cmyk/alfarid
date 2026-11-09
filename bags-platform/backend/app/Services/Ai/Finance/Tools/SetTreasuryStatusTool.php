<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinanceLocks;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\TreasuryService;

final class SetTreasuryStatusTool implements AgentTool, LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinanceWriteGuards $guards,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private TreasuryService $treasuryService,
    ) {}

    public function name(): string
    {
        return 'set_treasury_status';
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
        return ['treasuries.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إيقاف أو تفعيل خزينة. الإيقاف مرفوض مع رصيد أو ورديات أو سجل حركات أو تحويلات صادرة معلقة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['is_active'],
                'properties' => (object) [
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        if (! array_key_exists('is_active', $args)) {
            return ToolOutcome::denied('حدد الحالة المطلوبة بـ is_active.', auditable: false);
        }
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];
        $wantsActive = (bool) $args['is_active'];
        if ($wantsActive === (bool) $treasury->is_active) {
            return ToolOutcome::denied('الخزينة بالفعل '.($wantsActive ? 'نشطة' : 'متوقفة').'.', auditable: false);
        }
        if (! $wantsActive) {
            $blockers = $this->guards->deactivationBlockers($treasury);
            if ($blockers !== []) {
                return ToolOutcome::denied('لا يمكن إيقاف الخزينة: '.implode('، ', $blockers).'.', auditable: false);
            }
        } elseif ($treasury->branch_id !== null) {
            $branch = \App\Models\Branch::find($treasury->branch_id);
            if ($branch === null || ! $branch->is_active) {
                return ToolOutcome::denied('لا يمكن تفعيل خزينة على فرع متوقف.', auditable: false);
            }
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['treasury_id' => $treasury->id, 'is_active' => $wantsActive],
            risk: $wantsActive ? $this->risk() : 'high',
            title: ($wantsActive ? 'تفعيل خزينة ' : 'إيقاف خزينة ').$treasury->name,
            before: ['is_active' => (bool) $treasury->is_active],
            after: ['is_active' => $wantsActive],
            expectedVersions: $this->guards->accountVersions($treasury),
            inverse: ['is_active' => (bool) $treasury->is_active],
            typedConfirmation: $wantsActive ? null : $treasury->code,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        (new FinanceLocks)->lockTargets($action);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $treasury = Treasury::query()->lockForUpdate()->findOrFail((int) $a['treasury_id']);
        $this->branches->assertTreasury($actor->id, $treasury);
        if (array_diff_assoc($this->guards->accountVersions($treasury), $action->expected_versions ?? []) !== []) {
            throw new BusinessException('الخزينة اتعدلت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        return $this->changeStatus($actor, $treasury, (bool) $a['is_active']);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return isset($action->inverse['is_active']) && Treasury::query()->whereKey($action->args['treasury_id'])->exists();
    }

    public function targetState(AiPendingAction $action): string
    {
        return hash('sha256', json_encode($this->guards->accountVersions(Treasury::findOrFail($action->args['treasury_id'])), JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        return $this->changeStatus($actor, Treasury::findOrFail($action->args['treasury_id']), (bool) $action->inverse['is_active']);
    }

    private function changeStatus(User $actor, Treasury $treasury, bool $wantsActive): ToolOutcome
    {
        if (! $wantsActive && $treasury->is_active) {
            $blockers = $this->guards->deactivationBlockers($treasury);
            if ($blockers !== []) {
                throw new BusinessException('لا يمكن إيقاف الخزينة: '.implode('، ', $blockers).'.');
            }
        }
        if ($wantsActive && $treasury->branch_id !== null) {
            $this->branches->lockActiveBranches([$treasury->branch_id]);
        }
        $this->treasuryService->updateAccount($treasury, ['is_active' => $wantsActive], $actor);

        return ToolOutcome::result(true, $wantsActive ? "اتفعّلت الخزينة {$treasury->name}." : "اتوقفت الخزينة {$treasury->name}.", [
            'treasury_id' => $treasury->id,
            'deep_link' => $this->presenter->deepLink('tr', $treasury->id),
        ]);
    }
}
