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
 * خدمة الخزائن — المدخل الوحيد لأي حركة مالية في الخزائن.
 * قاعدة صارمة: ممنوع تعديل current_balance مباشرة من أي مكان آخر.
 * كل قبض أو صرف يمر من هنا: يسجل حركة في treasury_transactions أولًا،
 * ثم يحدث الرصيد — داخل نفس الـ transaction ومع قفل الصف.
 */
class TreasuryService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * تسجيل حركة مالية في خزينة مع قفل الصف وتحديث الرصيد.
     *
     * @param  array{
     *     treasury_id: int,
     *     shift_id?: int|null,
     *     type: TreasuryTransactionType,
     *     amount: float|string,
     *     reference_type?: string|null,
     *     reference_id?: int|null,
     *     created_by: int,
     *     notes?: string|null,
     * }  $data
     */
    public function record(array $data): TreasuryTransaction
    {
        return DB::transaction(function () use ($data): TreasuryTransaction {
            /** @var Treasury $treasury */
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);

            if (! $treasury->is_active) {
                throw new BusinessException('الخزينة غير نشطة.');
            }

            $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            $balanceAfter = BigDecimal::of((string) $treasury->current_balance)->plus($amount)->toScale(2);

            // منع الرصيد السالب على أدراج نقطة البيع والخزائن الرئيسية
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
     * تحويل مبلغ من خزينة إلى خزينة أخرى بعد الاعتماد.
     */
    public function approveTransfer(TreasuryTransfer $transfer, int $approverId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $approverId): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TreasuryTransferStatus::Pending) {
                throw new BusinessException('يمكن اعتماد التحويلات المعلقة فقط.');
            }
            if ((int) $transfer->created_by === $approverId) {
                throw new BusinessException('يجب أن يعتمد تحويل الخزينة موظف آخر غير منشئه.');
            }
            $this->lockTreasuries([$transfer->from_treasury_id, $transfer->to_treasury_id], $approverId);
            // خصم من المصدر
            $this->record([
                'treasury_id' => $transfer->from_treasury_id,
                'type' => TreasuryTransactionType::TransferOut,
                'amount' => -abs((float) $transfer->amount),
                'reference_type' => TreasuryTransfer::class,
                'reference_id' => $transfer->id,
                'created_by' => $approverId,
                'notes' => "تحويل #{$transfer->transfer_number} صادر",
            ]);

            // إضافة إلى الوجهة
            $this->record([
                'treasury_id' => $transfer->to_treasury_id,
                'type' => TreasuryTransactionType::TransferIn,
                'amount' => abs((float) $transfer->amount),
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
     * ترحيل نقدية الوردية المعتمدة إلى الخزنة الرئيسية للفرع.
     */
    public function settleShiftToMainSafe(PosShift $shift, int $approverId): void
    {
        DB::transaction(function () use ($shift, $approverId): void {
            // الخزنة الرئيسية للفرع
            $mainSafe = Treasury::query()
                ->where('branch_id', $shift->branch_id)
                ->where('type', 'main_safe')
                ->where('is_active', true)
                ->first();

            if ($mainSafe === null) {
                throw new BusinessException('يلزم وجود خزنة رئيسية نشطة لاعتماد الوردية وتسوية رصيد الدرج.');
            }

            // الرصيد الفعلي الحالي في الدرج
            $locked = $this->lockTreasuries([$shift->treasury_id, $mainSafe->id]);
            $drawer = $locked->get($shift->treasury_id);
            $amount = (float) $shift->actual_closing_cash;
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

            if ($amount <= 0) {
                return;
            }

            // خصم من درج الكاشير
            $this->record([
                'treasury_id' => $shift->treasury_id,
                'type' => TreasuryTransactionType::TransferOut,
                'amount' => -$amount,
                'reference_type' => PosShift::class,
                'reference_id' => $shift->id,
                'created_by' => $approverId,
                'notes' => "ترحيل إغلاق وردية #{$shift->shift_number}",
            ]);

            // إضافة للخزنة الرئيسية
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
     * إنشاء تحويل خزينة جديد (معلق حتى الاعتماد).
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
     * رفض تحويل خزينة.
     */
    public function rejectTransfer(TreasuryTransfer $transfer, int $approverId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $approverId): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TreasuryTransferStatus::Pending) {
                throw new BusinessException('يمكن رفض التحويلات المعلقة فقط.');
            }
            $this->lockTreasuries([$transfer->from_treasury_id, $transfer->to_treasury_id], $approverId);
            $transfer->update(['status' => TreasuryTransferStatus::Rejected, 'approved_by' => $approverId]);

            return $transfer->fresh();
        }, 3);
    }

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
