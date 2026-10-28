<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryTransferStatus;
use App\Exceptions\BusinessException;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
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
     * @throws BusinessException If treasury is inactive or insufficient funds
     */
    public function record(array $data): TreasuryTransaction
    {
        return DB::transaction(function () use ($data): TreasuryTransaction {
            /** @var Treasury $treasury */
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);

            if (! $treasury->is_active && $data['type'] !== TreasuryTransactionType::ExpenseReversal) {
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
     * @param TreasuryTransfer $transfer Transfer to approve
     * @param int $approverId User approving the transfer
     * @return TreasuryTransfer Updated transfer record
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
            $this->lockTreasuries([$transfer->from_treasury_id, $transfer->to_treasury_id], $approverId);
            $absAmount = (string) BigDecimal::of((string) $transfer->amount)->abs()->toScale(2, RoundingMode::HalfUp);

            $this->record([
                'treasury_id' => $transfer->from_treasury_id,
                'type' => TreasuryTransactionType::TransferOut,
                'amount' => '-'.$absAmount,
                'reference_type' => TreasuryTransfer::class,
                'reference_id' => $transfer->id,
                'created_by' => $approverId,
                'notes' => "تحويل #{$transfer->transfer_number} صادر",
            ]);

            $this->record([
                'treasury_id' => $transfer->to_treasury_id,
                'type' => TreasuryTransactionType::TransferIn,
                'amount' => $absAmount,
                'reference_type' => TreasuryTransfer::class,
                'reference_id' => $transfer->id,
                'created_by' => $approverId,
                'notes' => "تحويل #{$transfer->transfer_number} وارد",
            ]);

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
     * @param PosShift $shift Completed POS shift
     * @param int $approverId Manager approving the shift settlement
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

            $this->record([
                'treasury_id' => $shift->treasury_id,
                'type' => TreasuryTransactionType::TransferOut,
                'amount' => '-'.$amount,
                'reference_type' => PosShift::class,
                'reference_id' => $shift->id,
                'created_by' => $approverId,
                'notes' => "ترحيل إغلاق وردية #{$shift->shift_number}",
            ]);

            $this->record([
                'treasury_id' => $mainSafe->id,
                'type' => TreasuryTransactionType::TransferIn,
                'amount' => $amount,
                'reference_type' => PosShift::class,
                'reference_id' => $shift->id,
                'created_by' => $approverId,
                'notes' => "ترحيل إغلاق وردية #{$shift->shift_number}",
            ]);
        });
    }

    /**
     * Creates a pending transfer request between two treasuries.
     *
     * @param array<string, mixed> $data Transfer parameters
     * @param int $userId Creator identifier
     * @return TreasuryTransfer Created pending transfer
     * @throws BusinessException If source equals destination or unauthorized
     */
    public function createTransfer(array $data, int $userId): TreasuryTransfer
    {
        foreach (Treasury::query()->whereIn('id', [$data['from_treasury_id'], $data['to_treasury_id']])->get() as $treasury) {
            app(BranchAccess::class)->assertTreasury($userId, $treasury);
        }
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
    }

    /**
     * Rejects a pending treasury transfer without altering account balances.
     *
     * @param TreasuryTransfer $transfer Transfer to reject
     * @param int $approverId User rejecting the transfer
     * @return TreasuryTransfer Updated transfer record
     * @throws BusinessException If transfer is not pending
     */
    public function rejectTransfer(TreasuryTransfer $transfer, int $approverId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $approverId): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TreasuryTransferStatus::Pending) {
                throw new BusinessException('يمكن رفض التحويلات المعلقة فقط.');
            }
            // Rejections carry no financial movement, avoiding unnecessary row locking contention.
            $transfer->update(['status' => TreasuryTransferStatus::Rejected, 'approved_by' => $approverId]);

            return $transfer->fresh();
        }, 3);
    }

    /**
     * Acquires pessimistic locks on specified treasuries in consistent primary key order to prevent deadlocks.
     *
     * @param list<int> $ids Treasury identifiers
     * @param int|null $userId User to verify branch access against
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
}
