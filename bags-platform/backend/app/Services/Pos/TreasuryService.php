<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SettingService;

use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for treasury mutations, enforcing balance integrity and audit logging.
 */
class TreasuryService
{
    public function __construct(private readonly SettingService $settings) {}

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
        $this->assertAccountBranch($actor, isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        return DB::transaction(function () use ($data, $actor, $opening): Treasury {
            $treasury = Treasury::create($data + ['current_balance' => '0.00']);
            if (bccomp($opening, '0', 2) !== 0) {
                $this->record([
                    'treasury_id' => $treasury->id,
                    'type' => TreasuryTransactionType::OpeningBalance,
                    'amount' => $opening,
                    'created_by' => $actor->id,
                    'notes' => "رصيد افتتاحي #{$treasury->code}",
                ]);
            }

            return $treasury->refresh();
        });
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

        return DB::transaction(function () use ($treasury, $data, $actor): Treasury {
            $locked = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
            app(BranchAccess::class)->assertTreasury($actor->id, $locked);
            if (isset($data['type']) && $data['type'] !== $locked->type->value) {
                throw new BusinessException('نوع الخزينة غير قابل للتغيير.');
            }
            $unsettled = $locked->shifts()->whereIn('status', ['open', 'closed_pending_approval'])->exists();
            if (array_key_exists('branch_id', $data) && $data['branch_id'] != $locked->branch_id
                && ($unsettled || bccomp($locked->current_balance, '0', 2) !== 0 || $locked->transactions()->exists())) {
                throw new BusinessException('لا يمكن نقل خزينة لها رصيد أو سجل حركات إلى فرع آخر.');
            }
            if ($unsettled && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw new BusinessException('لا يمكن تعطيل درج له وردية لم تُسوَّ.');
            }
            $locked->update($data);

            return $locked;
        });
    }

    /**
     * Deletes an unused treasury account with zero balance and no transaction history.
     *
     * @param  Treasury  $treasury  Target treasury
     * @param  User  $actor  Staff user initiating deletion
     *
     * @throws BusinessException If treasury has non-zero balance, transactions, or shifts
     */
    public function deleteAccount(Treasury $treasury, User $actor): void
    {
        DB::transaction(function () use ($treasury, $actor): void {
            $locked = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
            app(BranchAccess::class)->assertTreasury($actor->id, $locked);
            if (bccomp($locked->current_balance, '0', 2) !== 0 || $locked->transactions()->exists() || $locked->shifts()->exists()) {
                throw new BusinessException('لا يمكن حذف خزينة لها رصيد أو سجل حركات أو ورديات.');
            }
            $locked->delete();
        });
    }

    private function assertAccountBranch(User $actor, ?int $branchId): void
    {
        $branches = app(BranchAccess::class);
        if ($branchId === null) {
            $branches->assertCentralAccess($actor);
        } else {
            $branches->assertBranch($actor->id, $branchId);
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
                app(BranchAccess::class)->lockActiveBranch((int) $treasury->branch_id);
            }

            if (! $treasury->is_active && ! in_array($data['type'], [TreasuryTransactionType::ExpenseReversal, TreasuryTransactionType::CustomerReceiptReversal], true)) {
                throw new BusinessException('الخزينة غير نشطة.');
            }

            $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            $balanceAfter = BigDecimal::of((string) $treasury->current_balance)->plus($amount)->toScale(2);

            // Bank accounts support overdrafts; physical safes and POS drawers cannot go negative.
            if ($balanceAfter->isNegative() && $treasury->type->value !== 'bank_account') {
                throw new BusinessException('رصيد الخزينة غير كافٍ لإتمام العملية.');
            }

            $transaction = TreasuryTransaction::create([
                'treasury_id' => $treasury->id,
                'shift_id' => $data['shift_id'] ?? null,
                'type' => $data['type'],
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
            app(BranchAccess::class)->lockActiveBranches($treasuries->pluck('branch_id')->filter());
            $absAmount = (string) BigDecimal::of((string) $transfer->amount)->abs()->toScale(2, RoundingMode::HalfUp);

            $this->recordTransferPair(
                $transfer->from_treasury_id,
                $transfer->to_treasury_id,
                $absAmount,
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
            app(BranchAccess::class)->lockActiveBranches($locked->pluck('branch_id')->filter());
            $drawer = $locked->get($shift->treasury_id);
            $amount = (string) BigDecimal::of((string) $shift->actual_closing_cash)->toScale(2, RoundingMode::HalfUp);
            $difference = BigDecimal::of((string) $shift->actual_closing_cash)
                ->minus((string) $drawer->current_balance)->toScale(2);
            if (! $difference->isZero()) {
                $this->record([
                    'treasury_id' => $drawer->id, 'shift_id' => $shift->id,
                    'type' => $difference->isNegative() ? TreasuryTransactionType::CashShortage : TreasuryTransactionType::CashOverage,
                    'amount' => (string) $difference, 'reference_type' => PosShift::class,
                    'reference_id' => $shift->id, 'created_by' => $approverId,
                    'notes' => 'تسوية فرق النقدية للوردية '.$shift->shift_number,
                ]);
            }

            if (BigDecimal::of($amount)->isLessThanOrEqualTo('0')) {
                return;
            }

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
            $treasuries = Treasury::query()->whereIn('id', [$data['from_treasury_id'], $data['to_treasury_id']])->get();
            foreach ($treasuries as $treasury) {
                app(BranchAccess::class)->assertTreasury($userId, $treasury);
            }
            app(BranchAccess::class)->lockActiveBranches($treasuries->pluck('branch_id')->filter());
            if ($data['from_treasury_id'] === $data['to_treasury_id']) {
                throw new BusinessException('لا يمكن التحويل من خزينة إلى نفسها.');
            }

            $number = $this->settings->generateNextNumber('treasury_transfer');

            return TreasuryTransfer::create([
                'transfer_number' => $number,
                'from_treasury_id' => $data['from_treasury_id'],
                'to_treasury_id' => $data['to_treasury_id'],
                'amount' => $data['amount'],
                'status' => TreasuryTransferStatus::Pending,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
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
            // Rejections carry no financial movement, avoiding unnecessary row locking contention.
            $transfer->update(['status' => TreasuryTransferStatus::Rejected, 'approved_by' => $approverId, 'rejection_reason' => $reason]);

            return $transfer->fresh();
        }, 3);
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
                app(BranchAccess::class)->assertTreasury($userId, $treasury);
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
