<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;

/**
 * Resolves post-authentication routing destinations across multiple frontend applications and user roles.
 */
final class LoginDestinationResolver
{
    // Shared permissions shown in the POS group of the backoffice role editor.
    private const POS_SHARED_PERMISSIONS = [
        'inventory.view',
        'orders.view',
        'orders.update',
        'stocktakes.view',
        'stocktakes.update',
        'stocktakes.approve',
        'transfers.view',
        'transfers.create',
        'transfers.receive',
        'transfers.approve_shortage',
    ];

    /**
     * Resolves target application and path based on actor role and account status.
     *
     * @param  User|SupplierUser|Customer  $actor  Authenticated entity.
     * @param  string|null  $requested  Optional redirect path requested by client.
     * @return array{app: string, path: string, destination: string}
     */
    public function resolve(User|SupplierUser|Customer $actor, ?string $requested = null): array
    {
        if ($actor instanceof User) {
            $roles = $actor->getRoleNames();
            if ($roles->isEmpty()) {
                throw new BusinessException('حسابك غير مرتبط بأي صلاحية. تواصل مع الإدارة.', [], 403);
            }
            // Super Admin always starts in the backoffice.
            if ($actor->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
                return ['app' => 'backoffice', 'path' => '/dashboard', 'destination' => 'admin'];
            }
            $firstRole = mb_strtolower(trim((string) $roles->first()));
            $isCashierName = $firstRole === mb_strtolower(StaffRole::Cashier->value)
                || $firstRole === 'cashier'
                || $firstRole === 'كاشير';
            $onlyPosPermissions = $actor->getAllPermissions()
                ->every(fn ($permission) => str_starts_with($permission->name, 'pos.')
                    || str_starts_with($permission->name, 'pos_shifts.')
                    || in_array($permission->name, self::POS_SHARED_PERMISSIONS, true));
            // A cashier role carrying admin permissions still starts in the backoffice.
            $isPosRole = $roles->count() === 1 && $onlyPosPermissions
                && ($isCashierName || $actor->can('pos.access'));
            if ($isPosRole) {
                // Block cashiers without an active assigned branch to prevent partially-functioning POS sessions.
                $actor->loadMissing('branch');
                if ($actor->branch_id === null || $actor->branch === null || ! $actor->branch->is_active) {
                    throw new BusinessException('حسابك غير مرتبط بفرع نشط.', [], 403, 'pos_branch_unavailable');
                }

                return ['app' => 'backoffice', 'path' => '/pos', 'destination' => 'pos'];
            }

            return ['app' => 'backoffice', 'path' => '/dashboard', 'destination' => 'admin'];
        }

        if ($actor instanceof SupplierUser) {
            return ['app' => 'web', 'path' => $this->safe($requested, '/supplier') ?? '/supplier', 'destination' => 'supplier'];
        }

        if ($actor->type === CustomerType::Retail) {
            // Respect requested redirect paths (e.g. /checkout) before falling back to configured retail home.
            $home = (string) config('store.retail_home', '/shop');
            $path = $this->safe($requested, '/');

            return ['app' => 'web', 'path' => $path ?? $home, 'destination' => 'retail'];
        }

        if ($actor->status === CustomerStatus::Approved) {
            return ['app' => 'web', 'path' => $this->safe($requested, '/wholesale') ?? '/wholesale', 'destination' => 'wholesale'];
        }

        return ['app' => 'web', 'path' => '/wholesale/application-status', 'destination' => 'wholesale_pending'];
    }

    private function safe(?string $path, string $prefix): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $pathnameEnd = strcspn($path, '?#');
        $rawPathname = substr($path, 0, $pathnameEnd);
        $suffix = substr($path, $pathnameEnd);
        $pathname = rawurldecode($rawPathname);
        // Reject double-encoded paths: a second decode must be stable (L1).
        if (rawurldecode($pathname) !== $pathname) {
            return null;
        }
        if (! str_starts_with($pathname, '/') || str_starts_with($pathname, '//')
            || str_contains($pathname, '\\') || str_contains($pathname, '://')
            || str_contains($pathname, '?') || str_contains($pathname, '#')
            || preg_match('/[\x00-\x20\x7f]/', $pathname)
            || array_intersect(explode('/', $pathname), ['.', '..']) !== []
            || ($prefix !== '/' && $pathname !== $prefix && ! str_starts_with($pathname, $prefix.'/'))) {
            return null;
        }

        if ($prefix === '/' && preg_match('~^/(auth|supplier|wholesale)(/|$)~', $pathname)) {
            return null;
        }

        return $pathname.$suffix;
    }
}
