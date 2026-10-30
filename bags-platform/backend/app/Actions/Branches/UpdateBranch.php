<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Enums\PosShiftStatus;
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
            if (array_key_exists('is_active', $data) && ! $data['is_active'] && (
                StockLevel::query()->where('branch_id', $locked->id)->where('in_transit', '>', 0)->exists()
                || StockTransfer::query()->where('to_branch_id', $locked->id)->where('status', 'shipped')->exists()
            )) {
                throw new BusinessException('لا يمكن تعطيل الفرع قبل استلام التحويلات المشحونة إليه.');
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
            $locked->update($data);
            $action = (array_key_exists('is_active', $data) && ! $locked->is_active) ? 'branch.deactivated' : 'branch.updated';
            StaffAdministration::audit($actor, $action, 'branch', $locked->id, array_keys($data), $before, StaffAdministration::branchState($locked), $via);
        });

        return $branch->refresh();
    }
}
