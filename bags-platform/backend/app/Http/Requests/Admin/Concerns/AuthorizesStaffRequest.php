<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\User;

trait AuthorizesStaffRequest
{
    protected function staffRequestUser(): ?User
    {
        $user = $this->user('staff') ?? $this->user();

        return $user instanceof User ? $user : null;
    }

    protected function authorizeStaffPermission(string $permission): bool
    {
        return $this->staffRequestUser()?->can($permission) ?? false;
    }

    /** @param array<string, string> $permissionsByAction */
    protected function authorizeStaffAction(array $permissionsByAction): bool
    {
        $action = $this->route()?->getActionMethod();
        $permission = is_string($action) ? ($permissionsByAction[$action] ?? null) : null;

        return is_string($permission) && $this->authorizeStaffPermission($permission);
    }
}
