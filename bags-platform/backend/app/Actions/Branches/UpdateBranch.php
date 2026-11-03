<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Enums\PosShiftStatus;
use App\Enums\TransferStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\PosShift;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;

final class UpdateBranch
{
    public function __construct(private BranchAccess $access) {}

    /** @param array<string,mixed> $data @param array<string,mixed> $via */
    public function __invoke(User $actor, Branch $branch, array $data, array $via = [], ?string $expectedUpdatedAt = null): Branch
    {
        $this->access->assertCentralAccess($actor);
        DB::transaction(function () use ($actor, $branch, $data, $via, $expectedUpdatedAt): void {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['branches.update', 'branches.manage']);
            $this->access->assertCentralAccess($actor);
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            // M3: قفل توافقي حقيقي بالـ lock_version (بدقة أقل من الثانية)،
            // مع دعم expectedUpdatedAt القديم احتياطًا.
            $expectedLockVersion = $data['lock_version'] ?? null;
            unset($data['lock_version']);
            if ($expectedLockVersion !== null) {
                abort_if((int) $locked->lock_version !== (int) $expectedLockVersion, 409, 'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
            } elseif ($expectedUpdatedAt !== null && $locked->updated_at?->toIso8601String() !== $expectedUpdatedAt) {
                abort(409, 'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
            }
            $before = StaffAdministration::branchState($locked);
            // city_id wins over free text so the two columns cannot drift.
            if (array_key_exists('city_id', $data) && ! empty($data['city_id'])
                && ($city = \App\Models\City::query()->find($data['city_id']))) {
                $data['city'] = $city->name_ar ?: $city->name_en;
            }
            // M4: same rule as SetLocationStatus — an approved transfer from a
            // disabled branch can never be cancelled (release path is locked),
            // and a shipped transfer to it can never be received (goods stay
            // "in transit" forever). Non-zero balances would freeze too.
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
            // M-12: وردية POS مفتوحة أو خزينة بها رصيد — موظفو الفرع المعطّل
            // ممنوعون من أي وصول (fail-closed) فلن يستطيعوا إغلاقها بعده.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                if (PosShift::query()->where('branch_id', $locked->id)->where('status', PosShiftStatus::Open->value)->exists()) {
                    throw new BusinessException('لا يمكن تعطيل الفرع وبه وردية POS مفتوحة؛ أغلقها أولًا.');
                }
                if (Treasury::query()->where('branch_id', $locked->id)->where('current_balance', '!=', 0)->exists()) {
                    throw new BusinessException('لا يمكن تعطيل الفرع وخزائنه بها أرصدة؛ صفّرها أولًا.');
                }
            }
            // branch.deactivated is recorded only on a real true -> false transition.
            $wasActive = (bool) $locked->is_active;
            // Changing a branch into a warehouse while POS shifts are open is
            // rejected: open shifts cannot be closed from a deactivated branch.
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
