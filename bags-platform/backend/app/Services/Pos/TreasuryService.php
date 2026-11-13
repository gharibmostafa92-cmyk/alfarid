<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SettingService;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for treasury mutations, enforcing balance integrity and audit logging.
 */
class TreasuryService
{
    private const MAX_TREASURY_BALANCE = '999999999999.99';
    /** Transaction types whose amount is always a credit (positive). */
    private const POSITIVE_TYPES = [
        TreasuryTransactionType::OpeningBalance,
        TreasuryTransactionType::PosSale,
        TreasuryTransactionType::ExpenseReversal,
        TreasuryTransactionType::TransferIn,
        TreasuryTransactionType::CustomerReceipt,
        TreasuryTransactionType::CashOverage,
    ];

    /** Transaction types whose amount is always a debit (negative). */
    private const NEGATIVE_TYPES = [
        TreasuryTransactionType::PosReturn,
        TreasuryTransactionType::Expense,
        TreasuryTransactionType::TransferOut,
        TreasuryTransactionType::SupplierPayment,
        TreasuryTransactionType::CustomerReceiptReversal,
        TreasuryTransactionType::CashShortage,
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly BranchAccess $branchAccess,
    ) {}

    /**
     * Creates a new treasury account and posts optional opening balance.
     *
     * @param  array<string, mixed>  $data  Treasury attributes
     * @param  User  $actor  Staff user creating the treasury
     * @param  string  $opening  Initial opening balance amount
     * @return Treasury Created treasury model
     */
    public function createAccount(array $data, User $actor, string $opening = '0.00'): Treasury
    {
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $typeValue = ($data['type'] ?? null) instanceof TreasuryType
            ? $data['type']->value
            : (string) ($data['type'] ?? '');
        if ($typeValue === TreasuryType::PosDrawer->value && $branchId === null) {
            throw new BusinessException('درج نقطة البيع يجب أن يتبع فرعًا محددًا.');
        }

        $this->assertAccountBranch($actor, $branchId);
        $openingDec = $this->parseDecimal($opening, 'الرصيد الافتتاحي')->toScale(2, RoundingMode::HalfUp);
        if ($openingDec->isNegative()) {
            throw new BusinessException('الرصيد الافتتاحي لا يمكن أن يكون سالبًا.');
        }

        try {
            return DB::transaction(function () use ($data, $actor, $openingDec, $branchId): Treasury {
                if ($branchId !== null) {
                    $this->branchAccess->lockActiveBranch($branchId);
                }
                $payload = Arr::only($data, ['name', 'code', 'branch_id', 'type', 'is_active']);
                $treasury = new Treasury($payload);
                $treasury->forceFill(['current_balance' => '0.00']);
                $treasury->save();
                if (! $openingDec->isZero()) {
                    $this->record([
                        'treasury_id' => $treasury->id,
                        'type' => TreasuryTransactionType::OpeningBalance,
                        'amount' => (string) $openingDec,
                        'created_by' => $actor->id,
                        'notes' => "رصيد افتتاحي #{$treasury->code}",
                    ]);
                }

                return $treasury->refresh();
            });
        } catch (QueryException $e) {
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '23505') {
                throw new BusinessException('كود الخزينة مستخدم بالفعل.', [], 409);
            }
            throw $e;
        }
    }

    /**
     * Updates treasury account parameters with branch and unsettled shift validation.
     *
     * @param  Treasury  $treasury  Target treasury
     * @param  array<string, mixed>  $data  Updated attributes
     * @param  User  $actor  Staff user initiating update
     * @return Treasury Refreshed treasury model
     *
     * @throws BusinessException If type changed, unsettled shifts exist, or cross-branch move is blocked
     */
    public function updateAccount(Treasury $treasury, array $data, User $actor): Treasury
    {
        if (array_key_exists('branch_id', $data)) {
            $this->assertAccountBranch($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        }

        try {
            return DB::transaction(function () use ($treasury, $data, $actor): Treasury {
                $locked = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
                $this->branchAccess->assertTreasury($actor->id, $locked);
                if (isset($data['type']) && $data['type'] !== $locked->type->value) {
                    throw new BusinessException('نوع الخزينة غير قابل للتغيير.');
                }
                if ($locked->type === TreasuryType::PosDrawer && array_key_exists('branch_id', $data) && $data['branch_id'] === null) {
                    throw new BusinessException('درج نقطة البيع يجب أن يتبع فرعًا محددًا.');
                }
                $balance = $this->parseDecimal($locked->current_balance, 'رصيد الخزينة');
                $unsettled = $locked->shifts()->whereIn('status', ['open', 'closed_pending_approval'])->exists();
                $transferQuery = TreasuryTransfer::query()->where(
                    fn ($q) => $q->where('from_treasury_id', $locked->id)->orWhere('to_treasury_id', $locked->id)
                );
                $hasTransfers = (clone $transferQuery)->exists();
                if (array_key_exists('branch_id', $data) && (int) $data['branch_id'] !== (int) $locked->branch_id
                    && ($unsettled || ! $balance->isZero() || $locked->transactions()->exists() || $hasTransfers)) {
                    throw new BusinessException('لا يمكن نقل خزينة لها رصيد أو سجل حركات أو تحويلات إلى فرع آخر.');
                }
                if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null && (int) $data['branch_id'] !== (int) $locked->branch_id) {
                    $this->branchAccess->lockActiveBranch((int) $data['branch_id']);
                }
                if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                    if ($unsettled) {
                        throw new BusinessException('لا يمكن تعطيل درج له وردية لم تُسوَّ.');
                    }
                    // Disabling a non-empty account traps money: record() rejects every
                    // movement except reversals, so the balance could not even be moved out.
                    if (! $balance->isZero()) {
                        throw new BusinessException('لا يمكن تعطيل خزينة لها رصيد غير صفري؛ رحّل الرصيد أولًا.');
                    }
                    if ((clone $transferQuery)->where('status', TreasuryTransferStatus::Pending)->exists()) {
                        throw new BusinessException('لا يمكن تعطيل خزينة لها تحويلات معلقة؛ اعتمد أو ارفض التحويلات المعلقة أولًا.');
                    }
                }
                $locked->update(Arr::only($data, ['name', 'code', 'branch_id', 'is_active']));

                return $locked;
            });
        } catch (QueryException $e) {
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '23505') {
                throw new BusinessException('كود الخزينة مستخدم بالفعل.', [], 409);
            }
            throw $e;
        }
    }

    /**
     * Deletes an unused treasury account with zero balance and no transaction history.
     *
     * @param  Treasury  $treasury  Target treasury
     * @param  User  $actor  Staff user initiating deletion
     *
     * @throws BusinessException If treasury has non-zero balance, transactions, shifts, expenses, or notices
     */
    public function deleteAccount(Treasury $treasury, User $actor): void
    {
        DB::transaction(function () use ($treasury, $actor): void {
            $locked = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
            $this->branchAccess->assertTreasury($actor->id, $locked);
            $balance = $this->parseDecimal($locked->current_balance, 'رصيد الخزينة');
            $hasTransfers = TreasuryTransfer::query()
                ->where(fn ($q) => $q
                    ->where('from_treasury_id', $locked->id)
                    ->orWhere('to_treasury_id', $locked->id))
                ->exists();
            $hasExpenses = Expense::query()->where('treasury_id', $locked->id)->exists();
            $hasNotices = WholesalePaymentNotice::query()->where('treasury_id', $locked->id)->exists();
            if (! $balance->isZero() || $locked->transactions()->exists() || $locked->shifts()->exists() || $hasTransfers || $hasExpenses || $hasNotices) {
                throw new BusinessException('لا يمكن حذف خزينة لها رصيد أو سجل حركات أو تحويلات أو ورديات أو مصروفات.');
            }
            try {
                $locked->delete();
            } catch (QueryException $e) {
                if ((string) ($e->errorInfo[0] ?? '') === '23503') {
                    throw new BusinessException('لا يمكن حذف خزينة مرتبطة بسجلات مالية قائمة.', [], 409);
                }
                throw $e;
            }
        });
    }

    private function assertAccountBranch(User $actor, ?int $branchId): void
    {
        if ($branchId === null) {
            $this->branchAccess->assertCentralAccess($actor);
        } else {
            $this->branchAccess->assertBranch($actor->id, $branchId);
        }
    }

    /**
     * Records a financial transaction and updates treasury balance under a row lock.
     *
     * @param array{
     *     treasury_id: int,
     *     shift_id?: int|null,
     *     type: TreasuryTransactionType,
     *     amount: float|string,
     *     reference_type?: string|null,
     *     reference_id?: int|null,
     *     created_by: int,
     *     notes?: string|null,
     * } $data Transaction payload
     * @return TreasuryTransaction Created transaction record
     *
     * @throws BusinessException If treasury is inactive or insufficient funds
     */
    public function record(array $data): TreasuryTransaction
    {
        return DB::transaction(function () use ($data): TreasuryTransaction {
            /** @var Treasury $treasury */
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
            if ($treasury->branch_id !== null) {
                $this->branchAccess->lockActiveBranch((int) $treasury->branch_id);
            }

            $type = $data['type'] instanceof TreasuryTransactionType
                ? $data['type']
                : TreasuryTransactionType::from((string) $data['type']);

            if (! $treasury->is_active && ! in_array($type, [TreasuryTransactionType::ExpenseReversal, TreasuryTransactionType::CustomerReceiptReversal], true)) {
                throw new BusinessException('الخزينة غير نشطة.');
            }

            $amount = $this->parseDecimal($data['amount'] ?? null, 'مبلغ الحركة')->toScale(2, RoundingMode::HalfUp);
            $this->assertAmountSign($type, $amount);
            $balanceAfter = $this->parseDecimal($treasury->current_balance, 'رصيد الخزينة')->plus($amount)->toScale(2);
            if ($balanceAfter->abs()->isGreaterThan(self::MAX_TREASURY_BALANCE)) {
                throw new BusinessException('رصيد الخزينة يتجاوز الحد المسموح.');
            }

            // Bank accounts support overdrafts; physical safes and POS drawers cannot go negative.
            if ($balanceAfter->isNegative() && $treasury->type->value !== 'bank_account') {
                throw new BusinessException('رصيد الخزينة غير كافٍ لإتمام العملية.');
            }

            $transaction = TreasuryTransaction::create([
                'treasury_id' => $treasury->id,
                'shift_id' => $data['shift_id'] ?? null,
                'type' => $type,
                'amount' => (string) $amount,
                'balance_after' => (string) $balanceAfter,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $data['created_by'],
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
            ]);

            $treasury->current_balance = (string) $balanceAfter;
            $treasury->save();

            return $transaction;
        });
    }

    private function assertAmountSign(TreasuryTransactionType $type, BigDecimal $amount): void
    {
        if ((in_array($type, self::POSITIVE_TYPES, true) && ! $amount->isPositive())
            || (in_array($type, self::NEGATIVE_TYPES, true) && ! $amount->isNegative())) {
            throw new BusinessException('إشارة المبلغ غير متوافقة مع نوع حركة الخزينة.');
        }
    }

    /**
     * Approves and executes a pending treasury transfer between two accounts.
     *
     * @param  TreasuryTransfer  $transfer  Transfer to approve
     * @param  int  $approverId  User approving the transfer
     * @return TreasuryTransfer Updated transfer record
     *
     * @throws BusinessException If transfer is not pending, self-approved, or unauthorized
     */
    public function approveTransfer(TreasuryTransfer $transfer, int $approverId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $approverId): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TreasuryTransferStatus::Pending) {
                throw new BusinessException('يمكن اعتماد التحويلات المعلقة فقط.');
            }
            // Segregation of duties: creator cannot approve their own transfer.
            if ((int) $transfer->created_by === $approverId) {
                throw new BusinessException('يجب أن يعتمد تحويل الخزينة موظف آخر غير منشئه.');
            }
            $treasuries = $this->lockTreasuries([$transfer->from_treasury_id, $transfer->to_treasury_id], $approverId);
            $this->branchAccess->lockActiveBranches($treasuries->pluck('branch_id')->filter());
            // A drawer bound to an open/pending shift must not receive or emit ad-hoc
            // transfers, otherwise drawer reconciliation would be silently corrupted.
            foreach ([$transfer->from_treasury_id, $transfer->to_treasury_id] as $treasuryId) {
                $lockedTreasury = $treasuries->get($treasuryId);
                if ($lockedTreasury === null) {
                    throw new BusinessException('إحدى الخزائن المحددة غير موجودة.');
                }
                $this->assertDrawerAvailableForTransfer($lockedTreasury);
            }
            $amount = $this->parseDecimal($transfer->amount, 'مبلغ التحويل')->toScale(2, RoundingMode::HalfUp);
            if (! $amount->isPositive()) {
                throw new BusinessException('مبلغ التحويل يجب أن يكون أكبر من صفر.');
            }

            $this->recordTransferPair(
                $transfer->from_treasury_id,
                $transfer->to_treasury_id,
                (string) $amount,
                TreasuryTransfer::class,
                $transfer->id,
                $approverId,
                "تحويل #{$transfer->transfer_number} صادر",
                "تحويل #{$transfer->transfer_number} وارد"
            );

            $transfer->update([
                'status' => TreasuryTransferStatus::Approved,
                'approved_by' => $approverId,
            ]);

            return $transfer->fresh();
        }, 3);
    }

    /**
     * Reconciles POS cash drawer variance and transfers closing cash to the branch main safe.
     *
     * @param  PosShift  $shift  Completed POS shift
     * @param  int  $approverId  Manager approving the shift settlement
     *
     * @throws BusinessException If main safe is missing or unauthorized
     */
    public function settleShiftToMainSafe(PosShift $shift, int $approverId): void
    {
        DB::transaction(function () use ($shift, $approverId): void {
            // The caller's model is stale by definition; re-read under lock so two
            // concurrent approvals cannot both settle the same drawer.
            $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
            // approveShift() marks the shift approved immediately before settling, so both
            // states are valid here; the transaction-existence guard below is what makes
            // settlement exactly-once.
            if (! in_array($shift->status, [PosShiftStatus::ClosedPendingApproval, PosShiftStatus::Approved], true)) {
                throw new BusinessException('لا يمكن تسوية وردية لم تُغلق بعد.');
            }
            if ($shift->actual_closing_cash === null) {
                throw new BusinessException('لا يمكن اعتماد وردية دون نقدية فعلية عند الإغلاق.');
            }
            if ($shift->settled_at !== null) {
                throw new BusinessException('تم اعتماد هذه الوردية وتسويتها مسبقًا.');
            }
            // Opening a shift already writes opening_balance/transfer_out rows against
            // the shift reference, so only settlement-specific types prove a prior run.
            if (TreasuryTransaction::query()
                ->where('reference_type', PosShift::class)
                ->where('reference_id', $shift->id)
                ->whereIn('type', [
                    TreasuryTransactionType::TransferIn->value,
                    TreasuryTransactionType::CashShortage->value,
                    TreasuryTransactionType::CashOverage->value,
                ])
                ->exists()) {
                throw new BusinessException('تم اعتماد هذه الوردية وتسويتها مسبقًا.');
            }

            $closingCash = $this->parseDecimal($shift->actual_closing_cash, 'النقدية الفعلية عند الإغلاق')->toScale(2, RoundingMode::HalfUp);
            if ($closingCash->isNegative()) {
                throw new BusinessException('النقدية الفعلية عند الإغلاق لا يمكن أن تكون سالبة.');
            }

            // Deterministic selection picks the oldest active safe when multiple exist.
            $mainSafe = Treasury::query()
                ->where('branch_id', $shift->branch_id)
                ->where('type', 'main_safe')
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($mainSafe === null) {
                throw new BusinessException('يلزم وجود خزنة رئيسية نشطة لاعتماد الوردية وتسوية رصيد الدرج.');
            }

            $locked = $this->lockTreasuries([$shift->treasury_id, $mainSafe->id]);
            $lockedSafe = $locked->get($mainSafe->id);
            if ($lockedSafe === null || ! $lockedSafe->is_active) {
                throw new BusinessException('يلزم وجود خزنة رئيسية نشطة لاعتماد الوردية وتسوية رصيد الدرج.');
            }
            $this->branchAccess->lockActiveBranches($locked->pluck('branch_id')->filter());
            // Settlement must not mix with a newer shift reusing the same drawer.
            $otherActiveShift = PosShift::query()
                ->where('treasury_id', $shift->treasury_id)
                ->where('id', '!=', $shift->id)
                ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
                ->exists();
            if ($otherActiveShift) {
                throw new BusinessException('الدرج مرتبط بوردية أخرى نشطة؛ لا يمكن ترحيل هذه الوردية.', [], 409);
            }
            $drawer = $locked->get($shift->treasury_id);
            $expected = $this->parseDecimal($shift->expected_closing_cash ?? '0', 'النقد المتوقع')->toScale(2, RoundingMode::HalfUp);
            $drawerBalance = $this->parseDecimal($drawer->current_balance, 'رصيد الدرج')->toScale(2, RoundingMode::HalfUp);
            if (! $drawerBalance->isEqualTo($expected)) {
                throw new BusinessException(
                    'رصيد الدرج الدفتري لا يطابق الرصيد المتوقع للوردية؛ يلزم مراجعة حركات الخزينة قبل التسوية.',
                    [],
                    409,
                );
            }
            $amount = (string) $closingCash;
            $difference = $closingCash
                ->minus($drawerBalance)->toScale(2);
            if (! $difference->isZero()) {
                $this->record([
                    'treasury_id' => $drawer->id, 'shift_id' => $shift->id,
                    'type' => $difference->isNegative() ? TreasuryTransactionType::CashShortage : TreasuryTransactionType::CashOverage,
                    'amount' => (string) $difference, 'reference_type' => PosShift::class,
                    'reference_id' => $shift->id, 'created_by' => $approverId,
                    'notes' => 'تسوية فرق النقدية للوردية '.$shift->shift_number,
                ]);
            }

            if ($closingCash->isPositive()) {
                $this->recordTransferPair(
                    $shift->treasury_id,
                    $mainSafe->id,
                    $amount,
                    PosShift::class,
                    $shift->id,
                    $approverId,
                    "ترحيل إغلاق وردية #{$shift->shift_number}",
                    "ترحيل إغلاق وردية #{$shift->shift_number}"
                );
            }

            $shift->forceFill(['settled_at' => now()])->save();
        });
    }

    /**
     * Creates a pending transfer request between two treasuries.
     *
     * @param  array<string, mixed>  $data  Transfer parameters
     * @param  int  $userId  Creator identifier
     * @return TreasuryTransfer Created pending transfer
     *
     * @throws BusinessException If source equals destination or unauthorized
     */
    public function createTransfer(array $data, int $userId): TreasuryTransfer
    {
        return DB::transaction(function () use ($data, $userId): TreasuryTransfer {
            $fromId = (int) ($data['from_treasury_id'] ?? 0);
            $toId = (int) ($data['to_treasury_id'] ?? 0);
            if ($fromId === $toId) {
                throw new BusinessException('لا يمكن التحويل من خزينة إلى نفسها.');
            }

            $amount = $this->parseDecimal($data['amount'] ?? '0', 'مبلغ التحويل')->toScale(2, RoundingMode::HalfUp);
            if (! $amount->isPositive()) {
                throw new BusinessException('مبلغ التحويل يجب أن يكون أكبر من صفر.');
            }

            $treasuries = $this->lockTreasuries([$fromId, $toId], $userId);
            if ($treasuries->count() !== 2) {
                throw new BusinessException('إحدى الخزائن المحددة غير موجودة.');
            }
            foreach ($treasuries as $treasury) {
                if (! $treasury->is_active) {
                    throw new BusinessException('لا يمكن تنفيذ التحويل من أو إلى خزينة غير نشطة.');
                }
                $this->assertDrawerAvailableForTransfer($treasury);
            }
            $toTreasury = $treasuries->get($toId);
            // Pending incoming transfers are not reflected in current_balance yet, but
            // approving them later would breach the ceiling. Reserve them now.
            $pendingIncoming = (string) (TreasuryTransfer::query()
                ->where('to_treasury_id', $toId)
                ->where('status', TreasuryTransferStatus::Pending)
                ->sum('amount') ?? '0');
            if ($toTreasury === null
                || $this->parseDecimal($toTreasury->current_balance, 'رصيد الخزينة')
                    ->plus($amount)
                    ->plus(BigDecimal::of($pendingIncoming)->toScale(2, RoundingMode::HalfUp))
                    ->abs()
                    ->isGreaterThan(self::MAX_TREASURY_BALANCE)) {
                throw new BusinessException('رصيد الخزينة بعد اعتماد التحويلات المعلقة يتجاوز الحد المسموح.');
            }
            $this->branchAccess->lockActiveBranches($treasuries->pluck('branch_id')->filter());

            $number = $this->settings->generateNextNumber('treasury_transfer');

            return TreasuryTransfer::create([
                'transfer_number' => $number,
                'from_treasury_id' => $fromId,
                'to_treasury_id' => $toId,
                'amount' => (string) $amount,
                'status' => TreasuryTransferStatus::Pending,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
        }, 3);
    }

    /**
     * Cancels a pending transfer, but only the creator may do so.
     *
     * Cancellation is stored as a rejection to keep the existing status check
     * constraint intact; it carries no financial movement.
     */
    public function cancelTransfer(TreasuryTransfer $transfer, int $userId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TreasuryTransferStatus::Pending) {
                throw new BusinessException('يمكن إلغاء التحويلات المعلقة فقط.', [], 409);
            }
            if ((int) $transfer->created_by !== $userId) {
                throw new BusinessException('فقط منشئ التحويل يمكنه إلغاء طلبه.', [], 403);
            }
            $this->lockTreasuries([$transfer->from_treasury_id, $transfer->to_treasury_id], $userId);
            $transfer->update([
                'status' => TreasuryTransferStatus::Rejected,
                'rejected_by' => $userId,
                'rejection_reason' => 'ألغى المنشئ طلب التحويل قبل الاعتماد.',
            ]);

            return $transfer->fresh();
        }, 3);
    }

    /**
     * Rejects a pending treasury transfer without altering account balances.
     *
     * @param  TreasuryTransfer  $transfer  Transfer to reject
     * @param  int  $approverId  User rejecting the transfer
     * @param  string|null  $reason  Optional audit reason (kept for the AI finance tools)
     * @return TreasuryTransfer Updated transfer record
     *
     * @throws BusinessException If transfer is not pending
     */
    public function rejectTransfer(TreasuryTransfer $transfer, int $approverId, ?string $reason = null): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $approverId, $reason): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TreasuryTransferStatus::Pending) {
                throw new BusinessException('يمكن رفض التحويلات المعلقة فقط.');
            }
            if ((int) $transfer->created_by === $approverId) {
                throw new BusinessException('يمكن لمنشئ التحويل إلغاء طلبه من مسار الإلغاء.', [], 403);
            }
            foreach (Treasury::query()->whereIn('id', [$transfer->from_treasury_id, $transfer->to_treasury_id])->get() as $treasury) {
                $this->branchAccess->assertTreasury($approverId, $treasury);
            }
            $normalizedReason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;
            if ($normalizedReason === '') {
                $normalizedReason = null;
            }
            // Rejections carry no financial movement, avoiding unnecessary row locking contention.
            $transfer->update(['status' => TreasuryTransferStatus::Rejected, 'rejected_by' => $approverId, 'rejection_reason' => $normalizedReason]);

            return $transfer->fresh();
        }, 3);
    }

    /**
     * Refuse ad-hoc transfers touching a POS drawer that is bound to an active shift.
     *
     * @throws BusinessException With HTTP 409 when the drawer is locked by a shift
     */
    private function assertDrawerAvailableForTransfer(Treasury $treasury): void
    {
        if ($treasury->type !== TreasuryType::PosDrawer) {
            return;
        }

        $active = PosShift::query()
            ->where('treasury_id', $treasury->id)
            ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
            ->lockForUpdate()
            ->exists();

        if ($active) {
            throw new BusinessException(
                'لا يمكن التحويل من أو إلى درج مرتبط بوردية نشطة أو منتظرة للاعتماد؛ أغلق الوردية واعتمدها أولًا.',
                [],
                409,
            );
        }
    }

    private function parseDecimal(mixed $value, string $label): BigDecimal
    {
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && ! is_finite($value)) {
                throw new BusinessException("قيمة {$label} غير صالحة.");
            }
            $value = (string) $value;
        }
        if (! is_string($value)) {
            throw new BusinessException("قيمة {$label} غير صالحة.");
        }
        $normalized = trim($value);
        if ($normalized === '' || ! preg_match('/\A[+-]?(?:\d+(?:\.\d+)?|\.\d+)\z/', $normalized)) {
            throw new BusinessException("قيمة {$label} غير صالحة.");
        }
        try {
            return BigDecimal::of($normalized);
        } catch (MathException) {
            throw new BusinessException("قيمة {$label} غير صالحة.");
        }
    }

    /**
     * Acquires pessimistic locks on specified treasuries in consistent primary key order to prevent deadlocks.
     *
     * @param  list<int>  $ids  Treasury identifiers
     * @param  int|null  $userId  User to verify branch access against
     * @return Collection<int, Treasury> Locked treasuries keyed by ID
     */
    public function lockTreasuries(array $ids, ?int $userId = null): Collection
    {
        $treasuries = Treasury::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($userId !== null) {
            foreach ($treasuries as $treasury) {
                $this->branchAccess->assertTreasury($userId, $treasury);
            }
        }

        return $treasuries;
    }

    /**
     * Record matching outbound and inbound transactions for a treasury-to-treasury transfer.
     */
    private function recordTransferPair(
        int $fromTreasuryId,
        int $toTreasuryId,
        string $amount,
        string $referenceType,
        int $referenceId,
        int $createdBy,
        string $outNotes,
        string $inNotes
    ): void {
        $this->record([
            'treasury_id' => $fromTreasuryId,
            'type' => TreasuryTransactionType::TransferOut,
            'amount' => '-'.$amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
            'notes' => $outNotes,
        ]);

        $this->record([
            'treasury_id' => $toTreasuryId,
            'type' => TreasuryTransactionType::TransferIn,
            'amount' => $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
            'notes' => $inNotes,
        ]);
    }
}
