<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreasuryTransactionType;
use App\Exceptions\BusinessException;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for administrative expense mutations.
 *
 * Encapsulates the exact rules previously embedded in ExpenseController::store
 * so the REST controller and the AI finance tools share one implementation:
 * branch scoping, pos_drawer refusal, idempotent creation, treasury locking,
 * document numbering, and reversal-based voiding (the ledger is append-only).
 */
class ExpenseService
{
    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly SettingService $settings,
        private readonly BranchAccess $branchAccess,
        private readonly OperationIdempotencyService $idempotency,
    ) {}

    /**
     * Records a new administrative expense and posts the debit transaction.
     *
     * @param  array{branch_id: int, treasury_id: int, category: string, amount: mixed, expense_date: string, notes?: string|null}  $data  Validated creation payload
     * @return Expense Created expense
     *
     * @throws BusinessException If treasury is inactive, mismatched, or a POS drawer
     */
    public function create(array $data, User $actor, ?string $idempotencyKey): Expense
    {
        $treasury = Treasury::query()->findOrFail($data['treasury_id']);
        $this->branchAccess->assertBranch($actor->id, (int) $data['branch_id']);
        $this->branchAccess->assertTreasury($actor->id, $treasury);
        if (! $treasury->is_active || ($treasury->branch_id !== null && (int) $treasury->branch_id !== (int) $data['branch_id'])) {
            throw new BusinessException('الخزينة غير مفعّلة أو لا تتبع الفرع المحدد.');
        }
        if ($treasury->type->value === 'pos_drawer') {
            throw new BusinessException('مصروفات أدراج نقطة البيع تُسجل من مسار نقطة البيع فقط.');
        }

        return $this->idempotency->run(
            'expense',
            $actor,
            $idempotencyKey,
            $data,
            fn () => DB::transaction(function () use ($data, $actor): Expense {
                $lockedTreasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
                $this->branchAccess->assertTreasury($actor->id, $lockedTreasury);
                if ($lockedTreasury->type->value === 'pos_drawer'
                    || ($lockedTreasury->branch_id !== null && (int) $lockedTreasury->branch_id !== (int) $data['branch_id'])) {
                    throw new BusinessException('الخزينة لا تقبل مصروفات هذا الفرع من المسار الإداري.');
                }
                $expenseNumber = $this->settings->generateNextNumber('expense');

                $expense = Expense::create([
                    ...$data,
                    'amount' => bcadd((string) $data['amount'], '0', 2),
                    'expense_number' => $expenseNumber,
                    'created_by' => $actor->id,
                ]);

                $this->treasury->record([
                    'treasury_id' => $data['treasury_id'],
                    'type' => TreasuryTransactionType::Expense,
                    'amount' => bcsub('0', (string) $data['amount'], 2),
                    'reference_type' => Expense::class,
                    'reference_id' => $expense->id,
                    'created_by' => $actor->id,
                    'notes' => "مصروف إداري: {$data['category']} #{$expenseNumber}",
                ]);

                return $expense;
            })
        );
    }

    /**
     * Updates non-financial attributes of an active expense.
     *
     * @param  array{category?: string, notes?: string|null}  $data  Validated update payload
     * @return Expense Updated expense
     *
     * @throws BusinessException If expense was already voided
     */
    public function update(Expense $expense, array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $data, $actor): Expense {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->assertExpenseAccess($actor->id, $locked);
            if ($locked->voided_at !== null) {
                throw new BusinessException('لا يمكن تعديل مصروف ملغى.');
            }

            $locked->update($data);

            return $locked;
        });
    }

    /**
     * Voids an expense and atomically posts the treasury reversal transaction.
     *
     * @throws BusinessException If already voided or tied to a POS shift
     */
    public function void(Expense $expense, string $reason, User $actor): Expense
    {
        $this->assertExpenseAccess($actor->id, $expense);

        return DB::transaction(function () use ($expense, $reason, $actor): Expense {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->voided_at !== null) {
                throw new BusinessException('هذا المصروف ملغى سابقًا.');
            }
            if ($locked->shift_id !== null) {
                throw new BusinessException('لا يمكن إلغاء مصروف مرتبط بوردية من المسار الإداري.');
            }

            // Post distinct reversal type so reporting aggregations do not register positive expenses.
            $this->treasury->record([
                'treasury_id' => $locked->treasury_id,
                'type' => TreasuryTransactionType::ExpenseReversal,
                'amount' => (string) $locked->amount,
                'reference_type' => Expense::class,
                'reference_id' => $locked->id,
                'created_by' => $actor->id,
                'notes' => "إلغاء مصروف #{$locked->expense_number}",
            ]);

            $locked->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    private function assertExpenseAccess(int $userId, Expense $expense): void
    {
        $this->branchAccess->assertBranch($userId, $expense->branch_id);
        $this->branchAccess->assertTreasury($userId, $expense->treasury);
    }
}
