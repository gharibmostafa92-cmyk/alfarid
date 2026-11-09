<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Expense;
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
use App\Services\Pos\ExpenseService;

final class VoidExpenseTool implements AgentTool, LocksTargets, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private ExpenseService $expenses,
    ) {}

    public function name(): string
    {
        return 'void_expense';
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
        return ['expenses.delete'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إلغاء مصروف بسبب إجباري مع قيد عكسي على الخزينة. مصروف الوردية لا يُلغى من هنا.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['reason'],
                'properties' => (object) [
                    'expense_id' => ['type' => 'INTEGER'],
                    'expense_query' => ['type' => 'STRING'],
                    'reason' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $reason = mb_substr(trim((string) ($args['reason'] ?? '')), 0, 500);
        if ($reason === '') {
            return ToolOutcome::denied('سبب الإلغاء إجباري.', auditable: false);
        }
        $resolved = FinanceArgs::resolveExpense($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $expense = $resolved['expense'];
        if ($expense->voided_at !== null) {
            return ToolOutcome::denied('هذا المصروف ملغى سابقًا.', auditable: false);
        }
        if ($expense->shift_id !== null) {
            return ToolOutcome::denied('لا يمكن إلغاء مصروف مرتبط بوردية من المسار الإداري.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['expense_id' => $expense->id, 'treasury_id' => $expense->treasury_id, 'reason' => $reason],
            risk: $this->risk(),
            title: "إلغاء مصروف {$expense->expense_number}",
            before: ['status' => 'ساري', 'amount' => (float) $expense->amount, 'treasury_balance' => (float) $expense->treasury->current_balance],
            after: ['status' => 'ملغي', 'reversal' => (float) $expense->amount,
                'treasury_balance' => (float) bcadd($expense->treasury->current_balance, $expense->amount, 2)],
            expectedVersions: [
                'expense:'.$expense->id => FinanceWriteGuards::expenseVersion($expense),
                'treasury:'.$expense->treasury_id => FinanceWriteGuards::ledgerVersion($expense->treasury),
            ],
            typedConfirmation: $expense->expense_number,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Expense::query()->lockForUpdate()->findOrFail((int) $action->args['expense_id']);
        (new FinanceLocks)->lockTargets($action);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $expense = Expense::query()->lockForUpdate()->findOrFail((int) $a['expense_id']);
        if (($action->expected_versions['expense:'.$expense->id] ?? null) !== FinanceWriteGuards::expenseVersion($expense)
            || ($action->expected_versions['treasury:'.$expense->treasury_id] ?? null) !== FinanceWriteGuards::ledgerVersion($expense->treasury)) {
            throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        $this->branches->assertBranch($actor->id, $expense->branch_id);
        $this->branches->assertTreasury($actor->id, $expense->treasury);
        $voided = $this->expenses->void($expense, (string) $a['reason'], $actor);

        return ToolOutcome::result(true, "اتلغى المصروف {$voided->expense_number} وعكس أثره على الخزينة.", [
            'expense_id' => $voided->id,
            'deep_link' => $this->presenter->deepLink('exp', $voided->id),
        ]);
    }
}
