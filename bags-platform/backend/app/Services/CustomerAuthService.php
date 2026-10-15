<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * دورة مصادقة العملاء (متجر قطاعي + جملة): التسجيل، الدخول، واستعادة كلمة المرور.
 * القيم الحساسة (type/status) تُفرض هنا برمجيًا ولا تأتي من الطلب أبدًا.
 */
class CustomerAuthService
{
    /** تسجيل متجر قطاعي: نوع retail وحالة approved دائمًا. */
    public function registerRetail(array $data): Customer
    {
        return Customer::query()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'password' => $data['password'],
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);
    }

    /**
     * دخول بالهاتف أو البريد. الحساب الموقوف يُرفض 403 بسبب الإيقاف؛
     * أي حالة أخرى (بما فيها pending/rejected للجملة) تستطيع الدخول —
     * بوابات النوع والحالة (wholesale.approved) تحكم ما بعد الدخول.
     */
    public function login(string $identifier, string $password): Customer
    {
        $customer = Customer::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if ($customer === null || ! Hash::check($password, $customer->password)) {
            throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
        }

        if ($customer->status === CustomerStatus::Suspended) {
            throw new BusinessException(
                'هذا الحساب موقوف.'.($customer->suspension_reason ? ' السبب: '.$customer->suspension_reason : ''),
                ['suspension_reason' => $customer->suspension_reason],
                403,
            );
        }

        return $customer;
    }

    /**
     * استعادة كلمة المرور: رمز في password_reset_tokens + إشعار للعميل.
     * الرد واحد دائمًا (نجاح ظاهري) حتى لا يُستدل على وجود الحساب.
     */
    public function forgotPassword(?string $email, ?string $phone): void
    {
        $customer = Customer::query()
            ->when($email, fn ($q) => $q->where('email', $email))
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->first();

        if ($customer === null) {
            return;
        }

        $plainToken = Str::random(64);
        $key = $customer->email ?? $customer->phone;

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $key],
            ['token' => Hash::make($plainToken), 'created_at' => now()],
        );

        if ($customer->email) {
            $customer->notify(new CustomerPasswordReset($plainToken));
        }
    }

    /** إصدار توكن Sanctum باسم القناة (store-token / wholesale-token). */
    public function issueToken(Customer $customer, string $name): string
    {
        return $customer->createToken($name)->plainTextToken;
    }
}
