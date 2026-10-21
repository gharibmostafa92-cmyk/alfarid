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

            // H1: ثبّت التكلفة دائمًا — افحص الباسورد مقابل الجداول الثلاثة
            // حتى لو لم يوجد صف، عبر dummy hash، فيكلّف أي إيميل ثلاث عمليات
            // bcrypt بغض النظر عن وجود الحساب، فلا يُستدل على وجوده بالتوقيت.
            $staff = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $staffPasswordOk = Hash::check($password, $staff?->password ?? CustomerAuthService::dummyHash());

            $supplierUser = SupplierUser::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $supplierPasswordOk = Hash::check($password, $supplierUser?->password ?? CustomerAuthService::dummyHash());

            // الأولوية للأعلى مرتبة عند تطابق الباسورد في أكثر من جدول.
            if ($staff !== null && $staffPasswordOk) {
                $this->rehashIfNeeded($staff, $password);
                if (! $staff->is_active) {
                    logger()->info('staff.login', ['user_id' => $staff->id, 'result' => 'inactive']);
                    throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
                }

                logger()->info('staff.login', ['user_id' => $staff->id, 'result' => 'success']);

                return $staff;
            }

            if ($supplierUser !== null && $supplierPasswordOk) {
                $this->rehashIfNeeded($supplierUser, $password);
                // H5/M4: المورد الموقوف شركته أو المحذوفة، أو المستخدم غير المرتبط
                // بمورد، يُعامل كحساب غير نشط بدل إصدار توكن أو الخطأ 500.
                $supplierActive = $supplierUser->is_active
                    && $supplierUser->supplier_id !== null
                    && $supplierUser->supplier?->is_active === true;
                if (! $supplierActive) {
                    logger()->info('supplier.login', ['supplier_user_id' => $supplierUser->id, 'result' => 'inactive']);
                    throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
                }

                logger()->info('supplier.login', ['supplier_user_id' => $supplierUser->id, 'result' => 'success']);

                return $supplierUser;
            }

            if ($staff !== null || $supplierUser !== null) {
                logger()->info('unified.login', ['result' => 'wrong_password']);
            }

            // L4: رصد التكرار الخطر — إيميل مشترك بين جدولين بباسورد واحد يعني
            // أن الأدنى أولوية لا يصل لحسابه أبدًا. سجّل للتنظيف والمنع.
            if ($staff !== null && $supplierUser !== null) {
                logger()->warning('auth.shared_email', ['email' => $email]);
            }
        }

        // يرمي BusinessException ‏401 أو 403 بنفسه
        return $this->customerAuth->login($login, $password);
    }

    private function rehashIfNeeded(User|SupplierUser|Customer $model, string $password): void
    {
        if (Hash::needsRehash($model->password)) {
            $model->forceFill(['password' => $password])->save();
        }
    }
}
