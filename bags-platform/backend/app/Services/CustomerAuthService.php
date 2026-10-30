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
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\UniqueCustomerEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer authentication lifecycle coordinator (retail store and wholesale portal).
 * Handles self-registration, credential authentication, password recovery, and token issuance.
 * Security boundaries: sensitive attributes (type/status) are strictly assigned programmatically.
 */
class CustomerAuthService
{
    /** Resolve a unique trader contact email without treating it as verified. */
    public function findForLogin(string $identifier): ?Customer
    {
        if (! str_contains($identifier, '@')) {
            return Customer::query()->where('phone', Customer::normalizePhone($identifier))->first();
        }

        $query = Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($identifier))]);
        $verified = (clone $query)->whereNotNull('email_verified_at')->first();
        if ($verified !== null) {
            return $verified;
        }
        $contacts = $query->limit(2)->get();
        $customer = $contacts->count() === 1 ? $contacts->first() : null;

        return $customer !== null && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
                ? $customer : null;
    }

    /**
     * Generate or return a cached dummy bcrypt/hasher hash to prevent user-enumeration timing attacks.
     */
    public static function dummyHash(): string
    {
        if (config('hashing.driver', 'bcrypt') === 'bcrypt') {
            return sprintf('$2y$%02d$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', (int) config('hashing.bcrypt.rounds', 12));
        }
        static $hashes = [];
        $key = json_encode(config('hashing'));

        return $hashes[$key] ??= Hash::make('not-a-customer-password');
    }

    /**
     * Compute HMAC key for phone/email to prevent rainbow-table reversal on small phone number spaces in audit logs.
     */
    private function identifierKey(string $identifier): string
    {
        return hash_hmac('sha256', $identifier, (string) config('app.key'));
    }

    /**
     * Self-register a retail store customer.
     * Enforces Retail type and Approved status unconditionally.
     */
    public function registerRetail(array $data): Customer
    {
        return DB::transaction(function () use ($data): Customer {
            $this->lockRegistrationEmail($data['email'] ?? null);
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
        });
    }

    /** Recheck registration rules while reserving the email until the surrounding transaction commits. */
    public function lockRegistrationEmail(?string $email): void
    {
        if ($email === null || trim($email) === '') {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Registration email claims require a transaction.');
        }
        $email = mb_strtolower(trim($email));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
        Validator::make(['email' => $email], [
            'email' => [new UniqueCustomerEmail, new EmailNotUsedByOtherAccountType('customers')],
        ])->validate();
    }

    /**
     * Authenticate customer credentials via phone or verified email.
     * Suspended accounts are rejected with 403; pending/rejected wholesale accounts may log in
     * to check application status, with channel guards restricting subsequent actions.
     *
     * @throws BusinessException If credentials invalid or account suspended
     */
    public function login(string $identifier, string $password): Customer
    {
        $emailLogin = str_contains($identifier, '@');
        $identifier = $emailLogin ? mb_strtolower(trim($identifier)) : Customer::normalizePhone($identifier);
        $dummyHash = self::dummyHash();
        $customer = $this->findForLogin($identifier);

        $validPassword = Hash::check($password, $customer?->password ?? $dummyHash);
        if ($customer === null || ! $validPassword) {
            // Avoid inserting unbounded audit log rows for nonexistent identifiers during stuffing attacks;
            // log sampled info metric instead, keyed by HMAC.
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
            $original = $customer->password;
            $replacement = Hash::make($password);
            if ($customer->newQuery()->whereKey($customer->id)->where('password', $original)->update(['password' => $replacement]) !== 1) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            $customer->forceFill(['password' => $replacement])->syncOriginalAttribute('password');
        }
        CustomerAuditLog::record($customer, 'login_succeeded', null);

        return $customer;
    }

    /**
     * Initiate asynchronous password reset workflow.
     * Returns generic success regardless of account existence to prevent user enumeration.
     */
    public function forgotPassword(?string $email, ?string $phone): void
    {
        if (! $email && ! $phone) {
            return;
        }

        SendCustomerPasswordReset::dispatch($email, $phone)
            ->onConnection(config('auth.customer_password_reset.connection', 'database'));
    }

    /**
     * Generate password reset token and dispatch notification.
     * Enforces token throttling and message budget limits.
     */
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

    /**
     * Validate reset token and update customer password.
     * Revokes all active sessions upon successful reset.
     *
     * @throws BusinessException If token is invalid or expired
     */
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
            if (in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
                && mb_strlen($data['password']) < 12) {
                throw ValidationException::withMessages(['password' => 'كلمة المرور يجب أن تكون 12 حرفًا على الأقل.']);
            }
            $customer->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $key)->delete();
            CustomerAuditLog::record($customer, 'password_reset', null);
        });
    }

    /**
     * Issue a channel-scoped Sanctum access token (e.g. store-token, wholesale-token).
     * Prunes expired tokens and limits maximum active concurrent sessions.
     *
     * @throws BusinessException If account is suspended
     */
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

            $token = $customer->createToken($name, ['*'], now()->addMinutes(max(1, (int) config('auth.customer_token_minutes', 1440))));
            CustomerAuditLog::record($customer, 'token_issued', null, ['token_id' => $token->accessToken->id, 'channel' => $name]);

            return $token->plainTextToken;
        });
    }
}
