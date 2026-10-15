<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class BranchAccess
{
    public function restrictedBranch(?User $user): ?int
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        return $user->branch_id !== null && ! $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
            ? (int) $user->branch_id : null;
    }

    public function assertBranch(int $userId, int ...$branchIds): void
    {
        $branchId = $this->restrictedBranch(User::findOrFail($userId));
        if ($branchId !== null && ! in_array($branchId, $branchIds, true)) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }
    }
}
