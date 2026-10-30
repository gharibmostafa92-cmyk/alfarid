<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff administration guardrails and security coordinator.
 * Manages concurrency locks, optimistic locking, role invariants, and audit logging.
 */
final class StaffAdministration
{
    /**
     * Acquire a transactional PostgreSQL advisory lock to serialize staff membership and role changes.
     * Prevents race conditions during critical checks such as last-active-admin validation.
     */
    public static function lock(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::select('SELECT pg_advisory_xact_lock(79301, 1)');
    }

    /**
     * Verify record lock_version matches expected optimistic concurrency version.
     */
    public static function assertVersion(Model $record, ?int $expected): void
    {
        // Detects concurrent permission/profile updates occurring within the same second
        abort_if($expected !== null && (int) $record->lock_version !== $expected, 409,
            'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
    }

    /**
     * Increment monotonic lock_version counter for optimistic concurrency tracking.
     */
    public static function advanceVersion(Model $record): void
    {
        $record->forceFill(['lock_version' => (int) $record->lock_version + 1])->save();
    }

    /**
     * Invalidate cached Spatie permissions across all application instances after commit.
     */
    public static function clearPermissionCacheAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            try {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            } catch (\Throwable $exception) {
                report($exception);
                logger()->error('staff.permission_cache_invalidation_failed');
            }
        });
    }

    /**
     * Capture snapshot of user role assignments, branch scoping, and active status.
     *
     * @return array{roles: list<string>, branch_id: int|null, is_active: bool}
     */
    public static function userState(User $user): array
    {
        return ['roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
            'branch_id' => $user->branch_id, 'is_active' => (bool) $user->is_active];
    }

    /**
     * Capture snapshot of role definition and its assigned permissions.
     *
     * @return array{name: string, permissions: list<string>}
     */
    /** @return array{name: string, is_active: bool} */
    public static function mayGrantPermissions(?User $actor, array $permissions): bool
    {
        if (! $actor instanceof User) {
            return false;
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        return $actor->hasRole(StaffRole::SuperAdmin->value, 'staff') || $actor->hasAllPermissions(array_values(array_unique($permissions)));
    }

    /** @param list<string> $roles */
    public static function mayManageUser(mixed $actor, ?User $target = null, array $roles = []): bool
    {
        if (! $actor instanceof User) {
            return false;
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        if ($target?->is_system) {
            return false;
        }
        if ($actor->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
            return true;
        }
        if (in_array(StaffRole::SuperAdmin->value, $roles, true) || $target?->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
            return false;
        }
        $permissions = Role::query()->where('guard_name', 'staff')->whereIn('name', $roles)->with('permissions')->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'));
        if ($target !== null) {
            $permissions = $permissions->merge($target->getAllPermissions()->pluck('name'));
        }

        return $actor->hasAllPermissions($permissions->unique()->all());
    }

    public static function branchState(Branch $branch): array
    {
        return ['name' => $branch->name, 'is_active' => (bool) $branch->is_active];
    }

    public static function roleState(Role $role): array
    {
        return ['name' => $role->name, 'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all()];
    }

    /**
     * Shared-lock roles in deterministic ID order to ensure they exist and prevent deletion during assignment.
     *
     * @param  list<string>  $names
     */
    public static function lockRoles(array $names): void
    {
        $roles = Role::query()->where('guard_name', 'staff')->whereIn('name', $names)
            ->orderBy('id')->sharedLock()->get();
        if ($roles->count() !== count(array_unique($names))) {
            throw ValidationException::withMessages(['roles' => 'قائمة الأدوار تغيرت؛ أعد التحميل ثم حاول مرة أخرى.']);
        }
    }

    /**
     * Re-verify active status and permission grant against database state.
     *
     * @param  list<string>  $permissions
     */
    public static function authorize(User $actor, array $permissions): void
    {
        $actor->refresh();
        abort_unless($actor->is_active && ! $actor->is_system && $actor->canAny($permissions), 403);
    }

    /**
     * Check if another active, non-system SuperAdmin user exists.
     * Used to prevent orphaning the system by deactivating or demoting the last admin.
     */
    public static function hasOtherActiveSuperAdmin(User $target): bool
    {
        return User::query()->whereKeyNot($target->id)->where('is_active', true)->where('is_system', false)
            ->whereHas('roles', fn ($query) => $query->where('name', StaffRole::SuperAdmin->value)->where('guard_name', 'staff'))
            ->exists();
    }

    /**
     * Record an administrative audit entry with sanitized before/after metadata.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function audit(?User $actor, string $action, string $targetType, int|string|null $targetId, array $fields, array $before = [], array $after = [], array $via = []): void
    {
        $allowed = array_flip(['roles', 'permissions', 'name', 'branch_id', 'is_active', 'settings', 'code', 'type', 'is_active_branch']);
        DB::table('staff_audit_logs')->insert([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            // Sensitive fields (passwords, tokens) are stripped; only allowed state keys are stored
            'metadata' => json_encode(['fields' => array_values(array_diff($fields, ['current_password', 'expected_version'])),
                'before' => array_intersect_key($before, $allowed), 'after' => array_intersect_key($after, $allowed),
                'via' => array_intersect_key($via, array_flip(['channel', 'conversation_id', 'action_id'])),
                'ip' => request()->ip(), 'method' => request()->method()], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
