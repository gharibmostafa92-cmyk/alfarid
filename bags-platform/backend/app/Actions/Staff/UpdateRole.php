<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\Ai\WhatsApp\WhatsAppIdentityService;
use App\Services\Inventory\BranchAccess;
use App\Services\Auth\StaffAdministration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Modifies an existing staff role name and permissions, maintaining system role invariants.
 */
final class UpdateRole
{
    public function __construct(private BranchAccess $access) {}

    /** @param array{name?:string,permissions?:list<string>|null,expected_version?:int|null} $data @param array<string,mixed> $via @return string|null failure code */
    public function __invoke(User $actor, Role $role, array $data, array $via = []): ?string
    {
        $permissions = $data['permissions'] ?? null;
        if (array_key_exists('name', $data) && StaffRole::isReserved((string) $data['name'])) {
            abort(403, 'هذا الاسم محجوز لدور أساسي في النظام.');
        }
        if (is_array($permissions) && ! StaffAdministration::mayGrantPermissions($actor, $permissions)) {
            abort(403, 'لا يمكنك إسناد صلاحيات لا تملكها.');
        }

        return DB::transaction(function () use ($actor, $role, $data, $permissions, $via): ?string {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['roles.update', 'roles.manage']);
            $this->access->assertCentralAccess($actor);
            $locked = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->guard_name === 'staff', 404);
            StaffAdministration::assertVersion($locked, isset($data['expected_version']) ? (int) $data['expected_version'] : null);
            $before = StaffAdministration::roleState($locked);
            if ($locked->name === StaffRole::SuperAdmin->value || (StaffRole::isReserved($locked->name) && array_key_exists('name', $data))) {
                return 'protected';
            }
            abort_unless(StaffAdministration::mayGrantPermissions($actor, array_unique(array_merge($locked->permissions()->pluck('name')->all(), $permissions ?? []))), 403);
            StaffAdministration::clearPermissionCacheAfterCommit();
            if (array_key_exists('name', $data)) {
                $locked->name = $data['name'];
                try {
                    $locked->save();
                } catch (UniqueConstraintViolationException $e) {
                    throw ValidationException::withMessages([
                        'name' => 'اسم الدور مستخدم بالفعل؛ أعد التحميل ثم حاول مرة أخرى.',
                    ]);
                }
            }
            if ($permissions !== null) {
                $locked->syncPermissions($permissions);
                if (in_array('ai_agent.whatsapp', $before['permissions'], true) && ! in_array('ai_agent.whatsapp', $permissions, true)) {
                    app(WhatsAppIdentityService::class)->revokeUsersOfRole($locked);
                }
            }
            StaffAdministration::advanceVersion($locked);
            StaffAdministration::audit($actor, 'role.updated', 'role', $locked->id, array_keys($data), $before, StaffAdministration::roleState($locked), $via);

            return null;
        });
    }
}
