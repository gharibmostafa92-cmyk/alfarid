<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\CustomerAuthService;
use Illuminate\Support\Facades\Hash;

final class UnifiedAuthService
{
    public function __construct(private readonly CustomerAuthService $customerAuth) {}

    public function attempt(string $login, string $password): User|SupplierUser|Customer
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            $email = mb_strtolower($login);

            $staff = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($staff !== null && Hash::check($password, $staff->password)) {
                if (! $staff->is_active) {
                    throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
                }

                return $staff;
            }

            $supplierUser = SupplierUser::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($supplierUser !== null && Hash::check($password, $supplierUser->password)) {
                if (! $supplierUser->is_active || $supplierUser->supplier_id === null) {
                    throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
                }

                return $supplierUser;
            }
        }

        // يرمي BusinessException ‏401 أو 403 بنفسه
        return $this->customerAuth->login($login, $password);
    }
}
