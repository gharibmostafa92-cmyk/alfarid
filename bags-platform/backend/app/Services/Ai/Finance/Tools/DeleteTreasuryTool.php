<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinanceLocks;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\TreasuryService;

final class DeleteTreasuryTool implements AgentTool, LocksTargets, WriteTool
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
        return 'delete_treasury';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['treasuries.delete'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حذف خزينة فارغة بلا حركات أو ورديات أو رصيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];
        $blockers = $this->blockingReasons($treasury);
        if ($blockers !== []) {
            return ToolOutcome::denied('لا يمكن حذف الخزينة: '.implode('، ', $blockers).'.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['treasury_id' => $treasury->id],
            risk: $this->risk(),
            title: "حذف خزينة {$treasury->name}",
            before: ['name' => $treasury->name, 'code' => $treasury->code],
            after: ['deleted' => true],
            expectedVersions: $this->guards->accountVersions($treasury),
            typedConfirmation: $treasury->code,
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
        $blockers = $this->blockingReasons($treasury);
        if ($blockers !== []) {
            throw new BusinessException('لا يمكن حذف الخزينة: '.implode('، ', $blockers).'.');
        }
        $name = $treasury->name;
        $this->treasuryService->deleteAccount($treasury, $actor);

        return ToolOutcome::result(true, "اتحذفت الخزينة {$name}.", ['treasury_id' => (int) $a['treasury_id']]);
    }

    /** @return list<string> */
    private function blockingReasons(Treasury $treasury): array
    {
        $blockers = [];
        $unsettled = PosShift::query()->where('treasury_id', $treasury->id)
            ->whereIn('status', ['open', 'closed_pending_approval'])->exists();
        if ($unsettled) {
            $blockers[] = 'في ورديات غير مسوّاة';
        }
        if ((float) $treasury->current_balance !== 0.0) {
            $blockers[] = 'الرصيد مش صفر';
        }
        if (TreasuryTransaction::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'ليها سجل حركات';
        }

        return $blockers;
    }
}
