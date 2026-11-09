<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Inventory\TransferStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\City;
use App\Models\PosShift;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\DB;

/**
 * Updates branch metadata, enforces optimistic concurrency, and validates deactivation blockers.
 */
/**
 * Updates branch metadata, verifying open shifts, in-transit transfers, and active stock before deactivation.
 */
final class UpdateBranch
{
    public function __construct(private BranchAccess $access) {}

    /**
     * Update branch attributes with concurrency checks and dependency validation.
     *
     * @param  User  $actor  Staff user initiating the update.
     * @param  Branch  $branch  Target branch model.
     * @param  array<string, mixed>  $data  Validated branch attributes.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @param  string|null  $expectedUpdatedAt  Legacy ISO timestamp for optimistic concurrency.
     * @return Branch Refreshed branch model.
     *
     * @throws BusinessException If active transfers, balances, or shifts block deactivation.
     */
    public function __invoke(User $actor, Branch $branch, array $data, array $via = [], ?string $expectedUpdatedAt = null): Branch
    {
        $this->access->assertCentralAccess($actor);
        DB::transaction(function () use ($actor, $branch, $data, $via, $expectedUpdatedAt): void {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['branches.update', 'branches.manage']);
            $this->access->assertCentralAccess($actor);
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            // Enforce optimistic concurrency via integer version counter, falling back to ISO timestamp for legacy clients.
            $expectedLockVersion = $data['lock_version'] ?? null;
            unset($data['lock_version']);
            if ($expectedLockVersion !== null) {
                abort_if((int) $locked->lock_version !== (int) $expectedLockVersion, 409, 'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
            } elseif ($expectedUpdatedAt !== null && $locked->updated_at?->toIso8601String() !== $expectedUpdatedAt) {
                abort(409, 'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
            }
            $before = StaffAdministration::branchState($locked);

            // City relation ID takes precedence over raw text to keep location columns synchronized.
            if (array_key_exists('city_id', $data) && ! empty($data['city_id'])
                && ($city = City::query()->find($data['city_id']))) {
                $data['city'] = $city->name_ar ?: $city->name_en;
            }

            // An approved transfer from a disabled branch cannot be cancelled and in-transit shipments cannot be received.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $hasOpenTransfers = StockTransfer::query()
                    ->where(fn ($q) => $q->where('from_branch_id', $locked->id)->orWhere('to_branch_id', $locked->id))
                    ->whereIn('status', [TransferStatus::Approved->value, TransferStatus::Shipped->value])
                    ->exists();
                if ($hasOpenTransfers) {
                    throw new BusinessException('لا يمكن تعطيل الفرع وبه تحويلات معتمدة أو مشحونة لم تُستلم؛ ألغها أو أكمل استلامها أولًا.');
                }
                if (StockLevel::query()->where('branch_id', $locked->id)->hasNonZeroBalance()->exists()) {
                    throw new BusinessException('لا يمكن تعطيل الفرع وبه أرصدة غير صفرية؛ صفّرها أولًا.');
                }
            }

            // Prevent deactivation if POS shifts or non-zero treasuries remain, as assigned staff are locked out immediately.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                if (PosShift::query()->where('branch_id', $locked->id)->where('status', PosShiftStatus::Open->value)->exists()) {
                    throw new BusinessException('لا يمكن تعطيل الفرع وبه وردية POS مفتوحة؛ أغلقها أولًا.');
                }
                if (Treasury::query()->where('branch_id', $locked->id)->where('current_balance', '!=', 0)->exists()) {
                    throw new BusinessException('لا يمكن تعطيل الفرع وخزائنه بها أرصدة؛ صفّرها أولًا.');
                }
            }

            $wasActive = (bool) $locked->is_active;

            // Prevent changing to warehouse while open POS shifts exist to prevent unclosable shifts.
            if (array_key_exists('type', $data) && (string) $locked->type->value === 'branch'
                && (string) ($data['type'] instanceof \App\Enums\BranchType ? $data['type']->value : $data['type']) === 'warehouse'
                && PosShift::query()->where('branch_id', $locked->id)->where('status', PosShiftStatus::Open->value)->exists()) {
                throw new BusinessException('لا يمكن تحويل الفرع إلى مخزن وبه وردية POS مفتوحة؛ أغلقها أولًا.');
            }
            $locked->update($data);
            $deactivated = array_key_exists('is_active', $data) && ! (bool) $data['is_active'] && $wasActive;
            $action = $deactivated ? 'branch.deactivated' : 'branch.updated';
            StaffAdministration::audit($actor, $action, 'branch', $locked->id, array_keys($data), $before, StaffAdministration::branchState($locked), $via);
        });

        return $branch->refresh();
    }
}
