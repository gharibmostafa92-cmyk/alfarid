<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\BranchAccess;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\SettingService;

use App\Enums\Finance\TreasuryTransactionType;
use App\Exceptions\BusinessException;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
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
        // Never spread the raw request: branch_id/treasury_id/created_by are
        // financial fields and must not be overridable through the payload.
        $payload = Arr::only($data, ['branch_id', 'treasury_id', 'category', 'amount', 'expense_date', 'notes', 'attachment_path']);
        if (array_key_exists('attachment_path', $payload) && $payload['attachment_path'] !== null) {
            $payload['attachment_path'] = self::validateAttachmentPath($payload['attachment_path']);
        }
        try {
            $amount = BigDecimal::of((string) ($payload['amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new BusinessException('مبلغ المصروف غير صالح.');
        }
        if (! $amount->isPositive()) {
            throw new BusinessException('مبلغ المصروف يجب أن يكون أكبر من صفر.');
        }
        $payload['amount'] = (string) $amount;

        $expenseDate = (string) ($payload['expense_date'] ?? '');
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $today = now($tz)->toDateString();
        $minDate = now($tz)->subDays(90)->toDateString();
        if ($expenseDate === '' || $expenseDate > $today || $expenseDate < $minDate) {
            throw new BusinessException('تاريخ المصروف غير صالح أو خارج النطاق المسموح به (خلال آخر 90 يومًا وحتى اليوم).');
        }

        $treasury = Treasury::query()->findOrFail($payload['treasury_id']);
        $this->branchAccess->assertBranch($actor->id, (int) $payload['branch_id']);
        $this->branchAccess->assertTreasury($actor->id, $treasury);
        if (! $treasury->is_active || ($treasury->branch_id !== null && (int) $treasury->branch_id !== (int) $payload['branch_id'])) {
            throw new BusinessException('الخزينة غير مفعّلة أو لا تتبع الفرع المحدد.');
        }
        if ($treasury->type->value === 'pos_drawer') {
            throw new BusinessException('مصروفات أدراج نقطة البيع تُسجل من مسار نقطة البيع فقط.');
        }

        return $this->idempotency->run(
            'expense',
            $actor,
            $idempotencyKey,
            $payload,
            fn () => DB::transaction(function () use ($payload, $amount, $actor): Expense {
                $this->branchAccess->lockActiveBranch((int) $payload['branch_id']);
                $lockedTreasury = $this->treasury->lockTreasuries([(int) $payload['treasury_id']], $actor->id)->get((int) $payload['treasury_id']);
                if ($lockedTreasury === null) {
                    throw new BusinessException('الخزينة غير موجودة.');
                }
                $this->branchAccess->assertTreasury($actor->id, $lockedTreasury);
                if (! $lockedTreasury->is_active) {
                    throw new BusinessException('الخزينة غير نشطة.');
                }
                if ($lockedTreasury->type->value === 'pos_drawer'
                    || ($lockedTreasury->branch_id !== null && (int) $lockedTreasury->branch_id !== (int) $payload['branch_id'])) {
                    throw new BusinessException('الخزينة لا تقبل مصروفات هذا الفرع من المسار الإداري.');
                }
                $expenseNumber = $this->settings->generateNextNumber('expense');

                $expense = Expense::create([
                    ...$payload,
                    'expense_number' => $expenseNumber,
                    'created_by' => $actor->id,
                ]);

                $this->treasury->record([
                    'treasury_id' => $payload['treasury_id'],
                    'type' => TreasuryTransactionType::Expense,
                    'amount' => (string) $amount->negated(),
                    'reference_type' => Expense::class,
                    'reference_id' => $expense->id,
                    'created_by' => $actor->id,
                    'notes' => "مصروف إداري: {$payload['category']} #{$expenseNumber}",
                ]);

                return $expense;
            })
        );
    }

    /**
     * Updates an active expense. Non-financial attributes update in-place; amount changes
     * atomically void the original voucher (posting ExpenseReversal) and create a replacement.
     *
     * @param  array{category?: string, notes?: string|null, amount?: mixed}  $data  Validated update payload
     * @return Expense Updated or replacement expense
     *
     * @throws BusinessException If expense was already voided, amount is invalid, or shift-tied amount change is attempted
     */
    public function update(Expense $expense, array $data, User $actor): Expense
    {
        $relations = ['branch:id,name', 'treasury:id,name,code,branch_id,type', 'creator:id,name'];

        return DB::transaction(function () use ($expense, $data, $actor, $relations): Expense {
            $locked = Expense::query()->with('treasury')->lockForUpdate()->findOrFail($expense->id);
            $this->assertExpenseAccess($actor->id, $locked);
            if ($locked->voided_at !== null) {
                throw new BusinessException('لا يمكن تعديل مصروف ملغى.');
            }

            if (! array_key_exists('amount', $data)) {
                $locked->update(Arr::only($data, ['category', 'notes']));

                return $locked->fresh($relations);
            }

            try {
                $newAmount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            } catch (MathException) {
                throw new BusinessException('مبلغ المصروف غير صالح.');
            }
            if (! $newAmount->isPositive()) {
                throw new BusinessException('مبلغ المصروف يجب أن يكون أكبر من صفر.');
            }

            $oldAmount = BigDecimal::of((string) $locked->amount)->toScale(2, RoundingMode::HalfUp);
            if ($newAmount->isEqualTo($oldAmount)) {
                $locked->update(Arr::only($data, ['category', 'notes']));

                return $locked->fresh($relations);
            }

            if ($locked->shift_id !== null) {
                throw new BusinessException('لا يمكن تعديل مبلغ مصروف مرتبط بوردية من المسار الإداري.');
            }

            $lockedTreasury = $this->treasury->lockTreasuries([(int) $locked->treasury_id], $actor->id)->get((int) $locked->treasury_id);
            if ($lockedTreasury === null || ! $lockedTreasury->is_active) {
                throw new BusinessException('الخزينة غير مفعّلة أو غير موجودة.');
            }

            $newNumber = $this->settings->generateNextNumber('expense');
            $category = (string) ($data['category'] ?? $locked->category);
            $notes = array_key_exists('notes', $data) ? $data['notes'] : $locked->notes;

            $this->treasury->record([
                'treasury_id' => $locked->treasury_id,
                'type' => TreasuryTransactionType::ExpenseReversal,
                'amount' => (string) $oldAmount,
                'reference_type' => Expense::class,
                'reference_id' => $locked->id,
                'created_by' => $actor->id,
                'notes' => "تعديل مصروف #{$locked->expense_number} ← #{$newNumber}",
            ]);

            $locked->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => mb_substr("تعديل المبلغ واستبداله بالمصروف #{$newNumber}", 0, 500),
            ]);

            $replacement = Expense::create([
                'expense_number' => $newNumber,
                'branch_id' => $locked->branch_id,
                'treasury_id' => $locked->treasury_id,
                'category' => $category,
                'amount' => (string) $newAmount,
                'expense_date' => $locked->expense_date,
                'attachment_path' => $locked->attachment_path,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            $this->treasury->record([
                'treasury_id' => $locked->treasury_id,
                'type' => TreasuryTransactionType::Expense,
                'amount' => (string) $newAmount->negated(),
                'reference_type' => Expense::class,
                'reference_id' => $replacement->id,
                'created_by' => $actor->id,
                'notes' => "مصروف إداري: {$category} #{$newNumber}",
            ]);

            return $replacement->load($relations);
        });
    }

    /**
     * Voids an expense and atomically posts the treasury reversal transaction.
     *
     * @throws BusinessException If already voided or tied to a POS shift
     */
    public function void(Expense $expense, string $reason, User $actor, ?string $idempotencyKey = null): Expense
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new BusinessException('سبب إلغاء المصروف إجباري.');
        }

        $execute = fn (): Expense => DB::transaction(function () use ($expense, $reason, $actor): Expense {
            $locked = Expense::query()->with('treasury')->lockForUpdate()->findOrFail($expense->id);
            $this->assertExpenseAccess($actor->id, $locked);
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

        if ($idempotencyKey !== null) {
            return $this->idempotency->run(
                'expense_void',
                $actor,
                $idempotencyKey,
                ['expense_id' => $expense->id, 'void_reason' => $reason],
                $execute,
            );
        }

        return $execute();
    }

    private function assertExpenseAccess(int $userId, Expense $expense): void
    {
        $this->branchAccess->assertBranch($userId, $expense->branch_id);
        $this->branchAccess->assertTreasury($userId, $expense->treasury);
    }

    public static function validateAttachmentPath(mixed $path): string
    {
        if (! is_string($path)) {
            throw new BusinessException('مسار مرفق المصروف غير صالح.');
        }

        $normalized = trim($path);
        if (! self::isSafeAttachmentPath($normalized)) {
            throw new BusinessException('مسار مرفق المصروف غير صالح.');
        }

        return $normalized;
    }

    public static function isSafeAttachmentPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        if (preg_match('/(?:^|\/)\.\.?(?:\/|$)/', $path) === 1 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        return str_starts_with($path, 'expense-receipts/') || str_starts_with($path, 'expenses/');
    }
}
