<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Enforces staff branch scoping using a fail-closed access policy:
 * Multi-branch access is an explicit privilege (Super Admin role or inventory.all_branches permission).
 * A branch-assigned staff member is strictly confined to their branch.
 * Users with no branch assignment and no all-branch privilege are denied access by default.
 */
class BranchAccess
{
    /** @var array<int, User> Per-request memory cache to avoid reloading user instances on repeated checks. */
    private array $usersById = [];

    /** @var array<int, bool> M4: حالة تفعيل الفرع للمستخدم المرتبط — cache بمستوى الطلب. */
    private array $branchActive = [];

    /**
     * Determine branch scope restriction for the given user.
     *
     * Returns null if user possesses global multi-branch access, returns branch ID if confined,
     * or throws an authorization exception if unassigned without global privileges.
     *
     * @param  User|null  $user
     * @return int|null
     *
     * @throws AuthorizationException
     */
    public function restrictedBranch(?User $user): ?int
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff') || $user->can('inventory.all_branches')) {
            return null;
        }

        if ($user->branch_id !== null) {
            // M4: الفرع المعطّل مقفول — مقيّد بفرع معطّل مالوش أي وصول
            // (fail-closed) بدل ما يكمل POS/مخزون عادي على فرع مزعّم إنه مقفول.
            $branchId = (int) $user->branch_id;
            $this->branchActive[$branchId] ??= (bool) (Branch::query()->find($branchId)?->is_active);
            if (! $this->branchActive[$branchId]) {
                throw new AuthorizationException('الفرع المرتبط بحسابك متوقف حالياً.');
            }

            return $branchId;
        }

        throw new AuthorizationException('حسابك غير مرتبط بأي فرع ولا تملك صلاحية الوصول لكل الفروع (inventory.all_branches).');
    }

    /**
     * Assert that the user is authorized to access at least one of the specified branches.
     *
     * @param  int  $userId
     * @param  int  ...$branchIds
     * @return void
     *
     * @throws AuthorizationException
     */
    public function assertBranch(int $userId, int ...$branchIds): void
    {
        $branchId = $this->restrictedBranch($this->usersById[$userId] ??= User::findOrFail($userId));
        if ($branchId !== null && ! in_array($branchId, $branchIds, true)) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }
    }

    /**
     * Assert that the user possesses global multi-branch access required for central headquarters operations.
     *
     * @param  User|null  $user
     * @return void
     *
     * @throws AuthorizationException
     */
    public function assertCentralAccess(?User $user): void
    {
        if ($this->restrictedBranch($user) !== null) {
            throw new AuthorizationException('هذه العملية المركزية تتطلب صلاحية الوصول لكل الفروع.');
        }
    }

    /**
     * Assert that the user is authorized to perform operations on the specified treasury.
     *
     * Central treasuries (branch_id = null) require global access; branch treasuries require branch access.
     *
     * @param  int  $userId
     * @param  Treasury  $treasury
     * @return void
     *
     * @throws AuthorizationException
     */
    public function assertTreasury(int $userId, Treasury $treasury): void
    {
        if ($treasury->branch_id === null) {
            $this->assertCentralAccess(User::findOrFail($userId));
        } else {
            $this->assertBranch($userId, (int) $treasury->branch_id);
        }
    }
}
