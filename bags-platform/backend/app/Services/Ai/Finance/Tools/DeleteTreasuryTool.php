<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Treasury;
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
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\TreasuryService;

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
        $blockers = $this->guards->deletionBlockers($treasury);
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
        $this->guards->assertAccountVersionsMatch($treasury, (array) ($action->expected_versions ?? []));
        $blockers = $this->guards->deletionBlockers($treasury);
        if ($blockers !== []) {
            throw new BusinessException('لا يمكن حذف الخزينة: '.implode('، ', $blockers).'.');
        }
        $name = $treasury->name;
        $this->treasuryService->deleteAccount($treasury, $actor);

        return ToolOutcome::result(true, "اتحذفت الخزينة {$name}.", ['treasury_id' => (int) $a['treasury_id']]);
    }
}
