<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
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
            if ($roles->count() === 1 && $roles->first() === StaffRole::Cashier->value) {
                // Block cashiers without an active assigned branch to prevent partially-functioning POS sessions.
                if ($actor->branch_id === null || $actor->branch === null || ! $actor->branch->is_active) {
                    throw new BusinessException('حسابك غير مرتبط بفرع نشط.', [], 403);
                }

                return ['app' => 'backoffice', 'path' => '/dashboard', 'destination' => 'pos'];
            }

            return ['app' => 'backoffice', 'path' => '/dashboard', 'destination' => 'admin'];
        }

        if ($actor instanceof SupplierUser) {
            return ['app' => 'web', 'path' => $this->safe($requested, '/supplier') ?? '/supplier', 'destination' => 'supplier'];
        }

        if ($actor->type === CustomerType::Retail) {
            // Route rejected commercial applicants to application-status page to view decision and appeal notes.
            if ($actor->status === CustomerStatus::Rejected) {
                return ['app' => 'web', 'path' => '/wholesale/application-status', 'destination' => 'wholesale_pending'];
            }
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

        $decoded = rawurldecode($path);
        $pathname = explode('?', explode('#', $decoded, 2)[0], 2)[0];
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || str_contains($decoded, '\\') || str_contains($decoded, '://')
            || preg_match('/[\x00-\x20\x7f]/', $decoded)
            || array_intersect(explode('/', $pathname), ['.', '..']) !== []
            || ($prefix !== '/' && $pathname !== $prefix && ! str_starts_with($pathname, $prefix.'/'))) {
            return null;
        }

        if ($prefix === '/' && preg_match('~^/(auth|supplier|wholesale)(/|$)~', $pathname)) {
            return null;
        }

        return $path;
    }
}
