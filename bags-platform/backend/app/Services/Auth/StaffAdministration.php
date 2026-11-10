<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\StaffRole;
use App\Exceptions\RetryableConflict;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
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
        // M3: xact locks are meaningless outside a transaction (released at statement end).
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Staff administration lock requires a transaction.');
        }
        // Advisory lock timeout (55P03) indicates a transient concurrency conflict converted to a retryable 409.
        try {
            // task3 M2: 0 means "wait forever", not "the default" — save and
            // restore the previous value so role/database timeouts survive.
            $previousLockTimeout = (string) DB::selectOne("SELECT current_setting('lock_timeout') AS setting")->setting;
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::select('SELECT pg_advisory_xact_lock(79301, 1)');
            // M3: restore the previous value so later lockForUpdate in the same transaction is not capped at 5s.
            DB::selectOne('SELECT set_config(?, ?, true) AS setting', ['lock_timeout', $previousLockTimeout]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '55P03') {
                throw new RetryableConflict('النظام مشغول حاليًا؛ حاول مرة أخرى.', $e);
            }
            throw $e;
        }
    }

    /**
     * Verify record lock_version matches expected optimistic concurrency version.
     */
    public static function assertVersion(Model $record, ?int $expected): void
    {
        // L8: null expected_version currently bypasses optimistic locking. Log it so we can
        // measure legacy clients before enforcing required versioning in a follow-up.
        if ($expected === null) {
            logger()->warning('staff.missing_expected_version', ['model' => $record::class, 'id' => $record->getKey()]);
        }
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
     * @return array{roles: list<string>, email: string|null, branch_id: int|null, is_active: bool}
     */
    public static function userState(User $user): array
    {
        return [
            'roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
            // Mask email in audit snapshots to prevent exposing sensitive credentials while still logging account takeovers.
            'email' => self::maskEmail($user->email),
            'branch_id' => $user->branch_id,
            'is_active' => (bool) $user->is_active,
        ];
    }

    /**
     * Asserts whether an actor holds sufficient privilege to grant specified permissions.
     *
     * @param  User|null  $actor  Staff user attempting permission assignment.
     * @param  list<string>  $permissions  Permissions requested to be granted.
     */
    public static function mayGrantPermissions(?User $actor, array $permissions): bool
    {
        if (! $actor instanceof User) {
            return false;
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        return $actor->hasRole(StaffRole::SuperAdmin->value, 'staff') || $actor->hasAllPermissions(array_values(array_unique($permissions)));
    }

    /**
     * Validates whether an administrative actor has authority to manage a target user and assign roles.
     *
     * @param  mixed  $actor  Staff user initiating management action.
     * @param  User|null  $target  Target user being modified, or null if creating.
     * @param  list<string>  $roles  Roles being assigned to the target user.
     */
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

    /** @return array{name: string, is_active: bool} */
    public static function branchState(Branch $branch): array
    {
        return ['name' => $branch->name, 'is_active' => (bool) $branch->is_active];
    }

    /** @return array{name: string, permissions: list<string>} */
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
     * Masks an email address preserving only initial characters and domain for safe auditing.
     *
     * @param  mixed  $email  Raw email string to mask.
     * @return string|null Masked email or null if empty.
     */
    public static function maskEmail(mixed $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***'.($domain !== '' ? '@'.$domain : '');
    }

    /**
     * Records an administrative audit log entry with sanitized before/after metadata.
     *
     * @param  User|null  $actor  Staff member performing the operation.
     * @param  string  $action  Audited operation identifier.
     * @param  string  $targetType  Target entity morph class or identifier.
     * @param  int|string|null  $targetId  Target entity primary key.
     * @param  list<string>  $fields  Modified field keys.
     * @param  array<string, mixed>  $before  Pre-modification snapshot.
     * @param  array<string, mixed>  $after  Post-modification snapshot.
     * @param  array<string, mixed>  $via  Audit trail origin metadata.
     */
    public static function audit(?User $actor, string $action, string $targetType, int|string|null $targetId, array $fields, array $before = [], array $after = [], array $via = []): void
    {
        $allowed = array_flip(['roles', 'permissions', 'name', 'branch_id', 'is_active', 'settings', 'code', 'type', 'is_active_branch', 'email', 'discount_percentage', 'description', 'name_ar', 'name_en', 'slug', 'parent_id', 'sort_order', 'category_id', 'status', 'sales_channel', 'is_featured', 'sku', 'barcode', 'color_name_ar', 'color_name_en', 'color_hex', 'size', 'capacity', 'retail_price', 'wholesale_price', 'count', 'media_ids', 'updated_count']);
        $before = array_intersect_key($before, $allowed);
        $after = array_intersect_key($after, $allowed);
        if (array_key_exists('email', $before)) {
            $before['email'] = self::maskEmail($before['email']);
        }
        if (array_key_exists('email', $after)) {
            $after['email'] = self::maskEmail($after['email']);
        }
        DB::table('staff_audit_logs')->insert([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            // Sensitive fields (passwords, tokens) are stripped; only allowed state keys are stored
            'metadata' => json_encode(['fields' => array_values(array_diff($fields, ['password', 'current_password', 'expected_version', 'cost_price'])),
                'before' => $before, 'after' => $after,
                'via' => array_intersect_key($via, array_flip(['channel', 'tool', 'entity', 'conversation_id', 'action_id', 'source'])),
                'ip' => request()->ip(), 'method' => request()->method()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(),
        ]);
    }
}
