<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class StaffAdministration
{
    // Serialize membership and role mutations, including the last-active-admin check.
    // This transaction lock is shared by both controllers and released on rollback too.
    public static function lock(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::select('SELECT pg_advisory_xact_lock(79301, 1)');
    }

    public static function assertVersion(Model $record, ?int $expected): void
    {
        // A monotonic version also detects two permission-only updates in the same second.
        abort_if($expected !== null && (int) $record->lock_version !== $expected, 409,
            'تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.');
    }

    public static function advanceVersion(Model $record): void
    {
        $record->forceFill(['lock_version' => (int) $record->lock_version + 1])->save();
    }

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

    public static function userState(User $user): array
    {
        return ['roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
            'branch_id' => $user->branch_id, 'is_active' => (bool) $user->is_active];
    }

    public static function roleState(Role $role): array
    {
        return ['name' => $role->name, 'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all()];
    }

    public static function lockRoles(array $names): void
    {
        $roles = Role::query()->where('guard_name', 'staff')->whereIn('name', $names)
            ->orderBy('id')->sharedLock()->get();
        if ($roles->count() !== count(array_unique($names))) {
            throw ValidationException::withMessages(['roles' => 'قائمة الأدوار تغيرت؛ أعد التحميل ثم حاول مرة أخرى.']);
        }
    }

    public static function authorize(User $actor, array $permissions): void
    {
        $actor->refresh();
        abort_unless($actor->is_active && ! $actor->is_system && $actor->canAny($permissions), 403);
    }

    public static function hasOtherActiveSuperAdmin(User $target): bool
    {
        return User::query()->whereKeyNot($target->id)->where('is_active', true)->where('is_system', false)
            ->whereHas('roles', fn ($query) => $query->where('name', StaffRole::SuperAdmin->value)->where('guard_name', 'staff'))
            ->exists();
    }

    public static function audit(?User $actor, string $action, string $targetType, int|string|null $targetId, array $fields, array $before = [], array $after = []): void
    {
        $allowed = array_flip(['roles', 'permissions', 'name', 'branch_id', 'is_active', 'settings']);
        DB::table('staff_audit_logs')->insert([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            // Only explicitly allowed state can be recorded. Password values and tokens never enter metadata.
            'metadata' => json_encode(['fields' => array_values(array_diff($fields, ['current_password', 'expected_version'])),
                'before' => array_intersect_key($before, $allowed), 'after' => array_intersect_key($after, $allowed),
                'ip' => request()->ip(), 'method' => request()->method()], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
