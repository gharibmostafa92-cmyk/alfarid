<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * نطاق الفروع للموظفين — Fail-Closed:
 * الوصول لكل الفروع امتياز صريح (Super Admin أو صلاحية inventory.all_branches)،
 * والموظف المرتبط بفرع محصور فيه، ومن لا فرع له ولا امتياز يُرفض بدل أن يرى كل شيء.
 */
class BranchAccess
{
    /** @var array<int, User> ذاكرة لكل طلب حتى لا يُعاد تحميل المستخدم مع كل فحص. */
    private array $usersById = [];

    public function restrictedBranch(?User $user): ?int
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff') || $user->can('inventory.all_branches')) {
            return null;
        }

        if ($user->branch_id !== null) {
            return (int) $user->branch_id;
        }

        throw new AuthorizationException('حسابك غير مرتبط بأي فرع ولا تملك صلاحية الوصول لكل الفروع (inventory.all_branches).');
    }

    public function assertBranch(int $userId, int ...$branchIds): void
    {
        $branchId = $this->restrictedBranch($this->usersById[$userId] ??= User::findOrFail($userId));
        if ($branchId !== null && ! in_array($branchId, $branchIds, true)) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }
    }

    public function assertCentralAccess(?User $user): void
    {
        if ($this->restrictedBranch($user) !== null) {
            throw new AuthorizationException('تعديل مجموعات العملاء والأسعار العامة يتطلب صلاحية الوصول لكل الفروع.');
        }
    }
}
