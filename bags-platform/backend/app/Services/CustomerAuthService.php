<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Jobs\SendCustomerPasswordReset;
use App\Models\Customer;
use App\Notifications\Channels\CustomerSmsChannel;
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
        $identifier = mb_strtolower(trim($identifier));
        $customer = Customer::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$identifier])->orWhere('phone', $identifier))
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
        if (! $email && ! $phone) {
            return;
        }

        SendCustomerPasswordReset::dispatch($email, $phone)
            ->onConnection(config('auth.customer_password_reset.connection', 'database'));
    }

    public function sendPasswordReset(?string $email, ?string $phone): void
    {
        if (! $email && ! $phone) {
            return;
        }

        $customer = Customer::query()
            ->when($email, fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]),
                fn ($q) => $q->where('phone', trim($phone)))
            ->first();

        if ($customer === null || $customer->status === CustomerStatus::Suspended) {
            return;
        }

        $plainToken = Str::random(64);
        $key = $customer->getEmailForPasswordReset();

        $stored = DB::transaction(function () use ($customer, $key, $plainToken): bool {
            $current = Customer::query()->lockForUpdate()->find($customer->id);
            if ($current === null || $current->status === CustomerStatus::Suspended) {
                return false;
            }
            $existing = DB::table('password_reset_tokens')->where('email', $key)->first();
            if ($existing && now()->subSeconds(60)->lt($existing->created_at)) {
                return false;
            }
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $key], ['token' => Hash::make($plainToken), 'created_at' => now()],
            );

            return true;
        });
        if (! $stored) {
            return;
        }

        try {
            $customer->notify(new CustomerPasswordReset($plainToken, $email ? 'mail' : CustomerSmsChannel::class));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function resetPassword(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $customer = Customer::query()
                ->when(! empty($data['email']), fn ($q) => $q->whereRaw('LOWER(email) = ?', [$data['email']]),
                    fn ($q) => $q->where('phone', $data['phone']))
                ->lockForUpdate()->first();
            $key = $customer?->getEmailForPasswordReset();
            $row = DB::table('password_reset_tokens')->where('email', $key)->lockForUpdate()->first();
            if ($customer === null || $customer->status === CustomerStatus::Suspended || $row === null
                || $row->created_at === null || now()->subMinutes(60)->gte($row->created_at)
                || now()->lt($row->created_at) || ! Hash::check($data['token'], $row->token)) {
                throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
            }
            $customer->update(['password' => $data['password'], 'remember_token' => Str::random(60)]);
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $key)->delete();
        });
    }

    /** إصدار توكن Sanctum باسم القناة (store-token / wholesale-token). */
    public function issueToken(Customer $customer, string $name): string
    {
        return DB::transaction(function () use ($customer, $name): string {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status === CustomerStatus::Suspended) {
                throw new BusinessException('هذا الحساب موقوف.', [], 403);
            }

            return $customer->createToken($name)->plainTextToken;
        });
    }
}
