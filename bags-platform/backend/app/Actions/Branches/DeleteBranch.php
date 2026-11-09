<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an empty branch if no assigned users, movements, or non-zero stock exist.
 */
final class DeleteBranch
{
    public function __construct(private BranchAccess $access) {}

    /**
     * Delete the specified branch and prune its zero-balance stock rows.
     *
     * @param  User  $actor  Staff user initiating the deletion.
     * @param  Branch  $branch  Target branch to delete.
     * @param  array<string, mixed>  $via  Audit metadata context.
     * @return bool True on successful deletion, false if users or movements are attached.
     *
     * @throws BusinessException If non-zero inventory balances remain.
     */
    public function __invoke(User $actor, Branch $branch, array $via = []): bool
    {
        $this->access->assertCentralAccess($actor);

        return DB::transaction(function () use ($actor, $branch, $via): bool {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['branches.delete', 'branches.manage']);
            $this->access->assertCentralAccess($actor);

            // Lock stock levels before branch row to maintain consistent lock order and prevent 40P01 deadlocks.
            StockLevel::query()->where('branch_id', $branch->getKey())
                ->orderBy('branch_id')
                ->orderByRaw('location_id ASC NULLS FIRST')
                ->orderBy('product_variant_id')
                ->lockForUpdate()
                ->get();
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
