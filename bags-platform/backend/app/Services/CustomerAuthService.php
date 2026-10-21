<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Jobs\SendCustomerPasswordReset;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
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
    public static function dummyHash(): string
    {
        if (config('hashing.driver', 'bcrypt') === 'bcrypt') {
            return sprintf('$2y$%02d$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', (int) config('hashing.bcrypt.rounds', 12));
        }
        static $hashes = [];
        $key = json_encode(config('hashing'));

        return $hashes[$key] ??= Hash::make('not-a-customer-password');
    }

    private function identifierKey(string $identifier): string
    {
        return hash_hmac('sha256', $identifier, (string) config('app.key'));
    }

    /** تسجيل متجر قطاعي: نوع retail وحالة approved دائمًا. */
    public function registerRetail(array $data): Customer
    {
        $verified = app(CustomerPhoneVerificationService::class)->consume($data);

        $customer = Customer::query()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'password' => $data['password'],
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
            'phone_verified_at' => $verified ? now() : null,
            'approved_at' => now(),
        ]);
        CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'store']);

        return $customer;
    }

    /**
     * دخول بالهاتف أو البريد. الحساب الموقوف يُرفض 403 بسبب الإيقاف؛
     * أي حالة أخرى (بما فيها pending/rejected للجملة) تستطيع الدخول —
     * بوابات النوع والحالة (wholesale.approved) تحكم ما بعد الدخول.
     */
    public function login(string $identifier, string $password): Customer
    {
        $emailLogin = str_contains($identifier, '@');
        $identifier = $emailLogin ? mb_strtolower(trim($identifier)) : Customer::normalizePhone($identifier);
        $dummyHash = self::dummyHash();
        $customer = Customer::query()
            ->when($emailLogin, fn ($q) => $q->whereNotNull('email_verified_at')->whereRaw('LOWER(email) = ?', [$identifier]), fn ($q) => $q->where('phone', $identifier))
            ->first();

        $validPassword = Hash::check($password, $customer?->password ?? $dummyHash);
        if ($customer === null || ! $validPassword) {
            // M11: لا تكتب صف audit لمعرّف غير موجود (هجمات stuffing تتحول لكتابات
            // غير محدودة)، واكتفِ بسجل info مع sampling. المعرّف يُحمى بـ HMAC
            // بمفتاح التطبيق لأن sha256 وحده قابل للعكس على مساحة الهواتف.
            if ($customer === null) {
                if (random_int(1, 10) === 1) {
                    logger()->info('customer.login_failed', ['identifier_key' => $this->identifierKey($identifier)]);
                }
            } else {
                defer(fn () => CustomerAuditLog::record($customer, 'login_failed', null, ['identifier_key' => $this->identifierKey($identifier)]))->always();
            }
            throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
        }

        if ($customer->status === CustomerStatus::Suspended) {
            CustomerAuditLog::record($customer, 'login_failed', null, ['reason' => 'suspended']);
            throw new BusinessException(
                'هذا الحساب موقوف.'.($customer->suspension_reason ? ' السبب: '.$customer->suspension_reason : ''),
                ['suspension_reason' => $customer->suspension_reason],
                403,
            );
        }

        if (Hash::needsRehash($customer->password)) {
            $customer->forceFill(['password' => $password])->save();
        }
        CustomerAuditLog::record($customer, 'login_succeeded', null);

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
            ->when($email, fn ($q) => $q->whereNotNull('email_verified_at')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]),
                fn ($q) => $q->where('phone', Customer::normalizePhone($phone)))
            ->first();

        if ($customer === null || $customer->status === CustomerStatus::Suspended) {
            return;
        }

        $plainToken = Str::random(64);
        $key = $customer->getEmailForPasswordReset();

        $stored = DB::transaction(function () use ($customer, $key, $plainToken, $email): bool {
            $current = Customer::query()->lockForUpdate()->find($customer->id);
            if ($current === null || $current->status === CustomerStatus::Suspended) {
                return false;
            }
            if ($email && ($current->email_verified_at === null || $current->email !== mb_strtolower(trim($email)))) {
                return false;
            }
            $existing = DB::table('password_reset_tokens')->where('email', $key)->first();
            if ($existing && now()->subSeconds(60)->lt($existing->created_at)) {
                return false;
            }
            if (! app(CustomerMessageBudget::class)->claim('reset:customer:'.$customer->id, sms: ! $email)) {
                return false;
            }
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $key], ['token' => hash('sha256', $plainToken), 'created_at' => now()],
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
        $dummyHash = self::dummyHash();
        DB::transaction(function () use ($data, $dummyHash): void {
            $customer = Customer::query()
                ->when(! empty($data['email']), fn ($q) => $q->whereNotNull('email_verified_at')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))]),
                    fn ($q) => $q->where('phone', $data['phone']))
                ->lockForUpdate()->first();
            $key = $customer?->getEmailForPasswordReset();
            $row = DB::table('password_reset_tokens')->where('email', $key)->lockForUpdate()->first();
            $storedToken = $row?->token ?? '';
            $validToken = strlen($storedToken) === 64 ? hash_equals($storedToken, hash('sha256', $data['token']))
                : Hash::check($data['token'], $storedToken ?: $dummyHash);
            if ($customer === null || $customer->status === CustomerStatus::Suspended || $row === null
                || $row->created_at === null || now()->subMinutes(60)->gte($row->created_at)
                || now()->lt($row->created_at) || ! $validToken) {
                throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
            }
            $customer->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $key)->delete();
            CustomerAuditLog::record($customer, 'password_reset', null);
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

            $expiry = config('sanctum.expiration');
            $customer->tokens()->where(fn ($q) => $q->where('expires_at', '<=', now())
                ->when($expiry, fn ($q) => $q->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();
            $limit = max(1, (int) config('auth.customer_max_tokens', 10));
            $old = $customer->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
            if ($old->isNotEmpty()) {
                $customer->tokens()->whereIn('id', $old)->delete();
            }

            $token = $customer->createToken($name);
            CustomerAuditLog::record($customer, 'token_issued', null, ['token_id' => $token->accessToken->id, 'channel' => $name]);

            return $token->plainTextToken;
        });
    }
}
