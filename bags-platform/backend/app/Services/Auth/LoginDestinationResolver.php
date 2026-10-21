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

final class LoginDestinationResolver
{
    /** @return array{app: string, path: string, destination: string} */
    public function resolve(User|SupplierUser|Customer $actor, ?string $requested = null): array
    {
        if ($actor instanceof User) {
            $roles = $actor->getRoleNames();
            if ($roles->isEmpty()) {
                throw new BusinessException('حسابك غير مرتبط بأي صلاحية. تواصل مع الإدارة.', [], 403);
            }
            if ($roles->count() === 1 && $roles->first() === StaffRole::Cashier->value) {
                // M14: الفرع المحذوف أو الموقوف يُوجَّه للـ POS خطأً — ارفضه هنا
                // بدل واجهة تعمل جزئيًا.
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
            // L3: المرفوض (بقرار إداري) كان يُوجَّه لـ '/' المعطلة بالكامل —
            // وجّهه لصفحة الحالة التي تعرض سبب الرفض (مسموحة للمرفوض).
            if ($actor->status === CustomerStatus::Rejected) {
                return ['app' => 'web', 'path' => '/wholesale/application-status', 'destination' => 'wholesale_pending'];
            }
            $path = $this->safe($requested, '/');
            if ($path !== null && (str_starts_with($path, '/wholesale') || str_starts_with($path, '/supplier') || str_starts_with($path, '/auth'))) {
                $path = null;
            }

            return ['app' => 'web', 'path' => $path ?? '/', 'destination' => 'retail'];
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
