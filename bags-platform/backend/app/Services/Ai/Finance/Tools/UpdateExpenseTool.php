<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Expense;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\ExpenseService;

final class UpdateExpenseTool implements AgentTool, LocksTargets, UndoableTool, WriteTool
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
        return 'update_expense';
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
        return ['expenses.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل فئة المصروف أو ملاحظاته. المصروف الملغي لا يُعدَّل.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'expense_id' => ['type' => 'INTEGER'],
                    'expense_query' => ['type' => 'STRING'],
                    'category' => ['type' => 'STRING'],
                    'notes' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveExpense($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $expense = $resolved['expense'];
        if ($expense->voided_at !== null) {
            return ToolOutcome::denied('لا يمكن تعديل مصروف ملغى.', auditable: false);
        }
        $patch = [];
        if (isset($args['category'])) {
            $category = mb_substr(trim((string) $args['category']), 0, 191);
            if ($category === '') {
                return ToolOutcome::denied('فئة المصروف مطلوبة.', auditable: false);
            }
            $patch['category'] = $category;
        }
        if (array_key_exists('notes', $args)) {
            $patch['notes'] = $args['notes'] === null ? null : mb_substr(trim((string) $args['notes']), 0, 500);
        }
        if ($patch === []) {
            return ToolOutcome::denied('مفيش تغيير.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['expense_id' => $expense->id, 'treasury_id' => $expense->treasury_id] + $patch,
            risk: $this->risk(),
            title: "تعديل مصروف {$expense->expense_number}",
            before: ['category' => $expense->category, 'notes' => $expense->notes],
            after: $patch,
            expectedVersions: ['expense:'.$expense->id => FinanceWriteGuards::expenseVersion($expense)],
            inverse: ['category' => $expense->category, 'notes' => $expense->notes],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Expense::query()->lockForUpdate()->findOrFail((int) $action->args['expense_id']);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $expense = Expense::query()->lockForUpdate()->findOrFail((int) $a['expense_id']);
        if (($action->expected_versions['expense:'.$expense->id] ?? null) !== FinanceWriteGuards::expenseVersion($expense)
            || $expense->category !== ($action->inverse['category'] ?? null)
            || $expense->notes !== ($action->inverse['notes'] ?? null)) {
            throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }
        $this->branches->assertBranch($actor->id, $expense->branch_id);
        $this->branches->assertTreasury($actor->id, $expense->treasury);
        $updated = $this->expenses->update($expense, array_intersect_key($a, ['category' => true, 'notes' => true]), $actor);

        return ToolOutcome::result(true, "اتعدّل المصروف {$updated->expense_number}.", [
            'expense_id' => $updated->id,
            'deep_link' => $this->presenter->deepLink('exp', $updated->id),
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $expense = Expense::find((int) ($action->args['expense_id'] ?? 0));

        return $expense !== null && $expense->voided_at === null && is_array($action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $expense = Expense::findOrFail((int) $action->args['expense_id']);

        return hash('sha256', json_encode([$expense->id, $expense->category, $expense->notes], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $expense = Expense::findOrFail((int) $action->args['expense_id']);
        if ($expense->voided_at !== null) {
            throw new BusinessException('المصروف ملغي ولا يمكن التراجع عن تعديله.', [], 409);
        }
        $this->expenses->update($expense, array_intersect_key($action->inverse ?? [], ['category' => true, 'notes' => true]), $actor);

        return ToolOutcome::result(true, "اترجع تعديل المصروف {$expense->expense_number}.", []);
    }
}
