<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\StockLevel;
use App\Models\StockTransfer;
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
            if ($expectedUpdatedAt !== null && $locked->updated_at?->toIso8601String() !== $expectedUpdatedAt) {
                abort(409, 'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
            }
            $before = StaffAdministration::branchState($locked);
            if (array_key_exists('is_active', $data) && ! $data['is_active'] && (
                StockLevel::query()->where('branch_id', $locked->id)->where('in_transit', '>', 0)->exists()
                || StockTransfer::query()->where('to_branch_id', $locked->id)->where('status', 'shipped')->exists()
            )) {
                throw new BusinessException('لا يمكن تعطيل الفرع قبل استلام التحويلات المشحونة إليه.');
            }
            $locked->update($data);
            $action = (array_key_exists('is_active', $data) && ! $locked->is_active) ? 'branch.deactivated' : 'branch.updated';
            StaffAdministration::audit($actor, $action, 'branch', $locked->id, array_keys($data), $before, StaffAdministration::branchState($locked), $via);
        });

        return $branch->refresh();
    }
}
