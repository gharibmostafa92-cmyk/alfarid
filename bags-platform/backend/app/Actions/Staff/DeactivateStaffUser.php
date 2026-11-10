<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\Ai\WhatsApp\WhatsAppIdentityService;
use App\Services\Auth\StaffAdministration;
use App\Services\Inventory\BranchAccess;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a staff member, terminates active sessions, and revokes access privileges.
 */
final class DeactivateStaffUser
{
    public function __construct(private BranchAccess $branches) {}

    /** @param array<string,mixed> $via */
    public function __invoke(User $actor, User $target, ?int $expectedVersion = null, array $via = []): bool
    {
        abort_if($target->is_system, 404);
        abort_if($actor->is($target), 403, 'لا يمكنك تعطيل حسابك الحالي.');

        return DB::transaction(function () use ($actor, $target, $expectedVersion, $via): bool {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['users.delete', 'users.manage']);
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $branch = $this->branches->restrictedBranch($actor);
            abort_if($branch !== null && (int) $locked->branch_id !== $branch, 403);
            abort_if($locked->is_system, 404);
            if (($locked->hasRole(StaffRole::SuperAdmin->value, 'staff')
                && (! $actor->hasRole(StaffRole::SuperAdmin->value, 'staff') || ! StaffAdministration::hasOtherActiveSuperAdmin($locked)))
                || ! StaffAdministration::mayManageUser($actor, $locked)) {
                return false;
            }
            if (! $locked->is_active) {
                return true;
            }
            if ($expectedVersion !== null) {
                StaffAdministration::assertVersion($locked, $expectedVersion);
            }
            $before = StaffAdministration::userState($locked);
            $locked->forceFill(['is_active' => false])->save();
            StaffAdministration::audit($actor, 'user.deactivated', 'user', $locked->id, ['is_active'], $before, StaffAdministration::userState($locked), $via);
            $locked->tokens()->delete();
            // task3 M8: a deactivated account must not keep a live WhatsApp
            // AI identity (mirrors UpdateStaffUser's !is_active revoke).
            app(WhatsAppIdentityService::class)->revoke($locked);

            return true;
        });
    }
}
