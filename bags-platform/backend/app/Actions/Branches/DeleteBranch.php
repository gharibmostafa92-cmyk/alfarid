<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;

final class DeleteBranch
{
    public function __construct(private BranchAccess $access) {}

    /** @param array<string,mixed> $via */
    public function __invoke(User $actor, Branch $branch, array $via = []): bool
    {
        $this->access->assertCentralAccess($actor);

        return DB::transaction(function () use ($actor, $branch, $via): bool {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['branches.delete', 'branches.manage']);
            $this->access->assertCentralAccess($actor);
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->users()->exists()) {
                return false;
            }
            if (DB::table('stock_movements')->where('branch_id', $locked->getKey())->exists()) {
                return false;
            }
            if (StockLevel::query()->where('branch_id', $locked->getKey())->hasNonZeroBalance()->exists()) {
                throw new BusinessException('لا يمكن حذف الفرع وفيه أرصدة (متاح/محجوز/تالف/في الطريق)؛ صفّرها أولًا.');
            }
            StockLevel::query()->where('branch_id', $locked->getKey())->delete();
            $before = StaffAdministration::branchState($locked);
            StaffAdministration::audit($actor, 'branch.deleted', 'branch', $locked->id, ['name', 'is_active'], $before, [], $via);
            $locked->delete();

            return true;
        });
    }
}
