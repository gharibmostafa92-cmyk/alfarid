<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Exceptions\BusinessException;
use App\Jobs\SendCustomerPasswordReset;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Notifications\Auth\CustomerPasswordReset;
use App\Notifications\Channels\CustomerSmsChannel;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\UniqueCustomerEmail;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use App\Support\AccountEmailLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
        $contacts = $query->commercial()->limit(2)->get();
        $customer = $contacts->count() === 1 ? $contacts->first() : null;

        return $customer;
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
        // Defense-in-depth: bcrypt truncates past 72 bytes, so reject early
        // on every entry path even when request validation already caps it.
        if (strlen((string) ($data['password'] ?? '')) > 72) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور يجب ألا تتجاوز 72 بايت.']);
        }
        try {
            return DB::transaction(function () use ($data): Customer {
                $this->lockRegistrationEmail($data['email'] ?? null);
                $verified = app(CustomerPhoneVerificationService::class)->consume($data);

                $customer = Customer::query()->make([
                    'name' => $data['name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'type' => CustomerType::Retail,
                    'phone_verified_at' => $verified ? now() : null,
                ]);
                $customer->forceFill(['status' => CustomerStatus::Approved, 'approved_at' => now()])->save();
                CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'store']);

                return $customer;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->translateRegistrationConflict($e);
        }
    }

    /**
     * Map database unique constraint violations to user-friendly validation exceptions.
     *
     * Prevents 500 errors on concurrent registrations with identical phone or email.
     *
     * @param  \Throwable  $e  Captured database or runtime exception.
     * @return \Throwable Converted ValidationException or original exception.
     */
    public function translateRegistrationConflict(\Throwable $e): \Throwable
    {
        return Customer::translateUniqueViolation($e);
    }

    /**
     * Acquire a transactional advisory lock on the normalized email to prevent race conditions.
     *
     * @param  string|null  $email  Email address to lock.
     */
    public function lockRegistrationEmail(?string $email): void
    {
        if ($email === null || trim($email) === '') {
            return;
        }
        $email = mb_strtolower(trim($email));
        AccountEmailLock::acquire($email);
        Validator::make(['email' => $email], [
            'email' => [new UniqueCustomerEmail, new EmailNotUsedByOtherAccountType('customers', true)],
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
        $identifier = Customer::normalizeIdentifier($identifier) ?? '';
        $dummyHash = self::dummyHash();
        $customer = $this->findForLogin($identifier);

        $hasPassword = $customer !== null && is_string($customer->password) && $customer->password !== '';
        $validPassword = Hash::check($password, $hasPassword ? $customer->password : $dummyHash) && $hasPassword;
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
            throw new BusinessException('هذا الحساب موقوف. يرجى التواصل مع خدمة العملاء.', [], 403, 'account_inactive');
        }

        if ((bool) config('auth.require_phone_verification', false) && $customer->phone_verified_at === null) {
            throw new BusinessException('يرجى توثيق رقم الهاتف أولاً.', [], 403, 'phone_verification_required');
        }

        if (Hash::needsRehash($customer->password)) {
            $original = $customer->password;
            $replacement = Hash::make($password);
            if ($customer->newQuery()->whereKey($customer->id)->where('password', $original)->update(['password' => $replacement]) === 1) {
                $customer->forceFill(['password' => $replacement])->syncOriginalAttribute('password');
            } else {
                // Verify against freshly persisted hash if a concurrent request completed the rehash first.
                $fresh = $customer->newQuery()->whereKey($customer->id)->value('password');
                if (! is_string($fresh) || ! Hash::check($password, $fresh)) {
                    throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
                }
                $customer->forceFill(['password' => $fresh])->syncOriginalAttribute('password');
            }
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

        // Capture the requester's subnet now: the queued job runs without the
        // original request context and must still enforce network-level caps.
        $networkKey = CustomerMessageBudget::subnetKey(request()->ip());

        SendCustomerPasswordReset::dispatch($email, $phone, $networkKey)
            ->onConnection(config('auth.customer_password_reset.connection', 'database'));
    }

    /**
     * Generate password reset token and dispatch notification.
     * Enforces token throttling and message budget limits.
     */
    public function sendPasswordReset(?string $email, ?string $phone, ?string $networkKey = null): void
    {
        if (! $email && ! $phone) {
            return;
        }

        $customer = $this->findForPasswordReset($email, $phone);

        if ($customer === null || $customer->status === CustomerStatus::Suspended) {
            return;
        }

        $plainToken = Str::random(64);
        $key = $customer->getEmailForPasswordReset();
        // Pin the budget timestamp once: a slow provider failure must refund
        // the hour/day bucket the quota was claimed from, not the later one.
        $budgetAt = CarbonImmutable::now((string) config('app.business_timezone', 'Africa/Cairo'));

        $stored = DB::transaction(function () use ($customer, $key, $plainToken, $email, $networkKey, $budgetAt): bool {
            $current = Customer::query()->lockForUpdate()->find($customer->id);
            if ($current === null || $current->status === CustomerStatus::Suspended) {
                return false;
            }
            if ($email) {
                $emailMatches = mb_strtolower((string) $current->email) === mb_strtolower(trim($email));
                $mayUseEmail = $current->email_verified_at !== null || $current->isCommercialTier();
                if (! $emailMatches || ! $mayUseEmail) {
                    return false;
                }
            }
            $existing = DB::table('password_reset_tokens')->where('email', $key)->first();
            if ($existing && $existing->created_at !== null && now()->subSeconds(60)->lt(Carbon::parse($existing->created_at))) {
                return false;
            }
            if (! app(CustomerMessageBudget::class)->claim('reset:customer:'.$customer->id, sms: ! $email, networkKey: $networkKey, at: $budgetAt)) {
                return false;
            }
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $key], ['token' => hash('sha256', $plainToken), 'created_at' => now()],
            );

            DB::afterCommit(function () use ($customer, $key, $plainToken, $email, $networkKey, $budgetAt): void {
                try {
                    $customer->notify(new CustomerPasswordReset($plainToken, $email ? 'mail' : CustomerSmsChannel::class));
                } catch (\Throwable $exception) {
                    // The token never reached the customer: drop it so the
                    // queued retry regenerates a fresh one instead of hitting
                    // the 60s throttle on a dead token, refund the budget,
                    // and rethrow so the job retries with backoff.
                    $this->handleResetDeliveryFailure($customer, $key, $plainToken, ! $email, $networkKey, $budgetAt, $exception);
                }
            });

            return true;
        });
        if (! $stored) {
            return;
        }
    }

    /**
     * Delete a password-reset token only when it is still the stored one.
     * A concurrent re-request issues a newer token that must survive an
     * older delivery worker's failure cleanup (compare-and-delete).
     */
    public function deleteStaleResetToken(string $key, string $plainToken): void
    {
        DB::table('password_reset_tokens')
            ->where('email', $key)
            ->where('token', hash('sha256', $plainToken))
            ->delete();
    }

    /**
     * Clean up after a failed reset delivery: drop only the token that failed,
     * refund the exact hour/day bucket the quota was claimed from, and rethrow
     * so the job retries with backoff.
     */
    public function handleResetDeliveryFailure(
        Customer $customer,
        string $key,
        string $plainToken,
        bool $sms,
        ?string $networkKey,
        CarbonImmutable $at,
        \Throwable $exception,
    ): never {
        // If the provider may have already accepted the message before timing out,
        // keep the issued token valid and do not refund the quota.
        if (! $this->isAmbiguousDeliveryTimeout($exception)) {
            // Compare-and-delete: never remove a newer concurrent token.
            $this->deleteStaleResetToken($key, $plainToken);
            try {
                app(CustomerMessageBudget::class)->releaseResetClaim($customer->id, $sms, $at, $networkKey);
            } catch (\Throwable $refundException) {
                report($refundException);
            }
        }
        report($exception);
        throw $exception;
    }

    private function isAmbiguousDeliveryTimeout(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'timeout');
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
        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';
        DB::transaction(function () use ($data, $dummyHash, $email, $phone): void {
            $customer = $this->findForPasswordReset($email !== '' ? $email : null, $phone !== '' ? $phone : null);
            if ($customer !== null) {
                $customer = Customer::query()->lockForUpdate()->find($customer->id);
            }
            if ($customer !== null && $email !== '') {
                $emailMatches = mb_strtolower((string) $customer->email) === mb_strtolower($email);
                $mayUseEmail = $customer->email_verified_at !== null || $customer->isCommercialTier();
                if (! $emailMatches || ! $mayUseEmail) {
                    $customer = null;
                }
            }
            $key = $customer?->getEmailForPasswordReset();
            if (! is_string($key) || $key === '') {
                Hash::check((string) ($data['token'] ?? ''), $dummyHash);
                throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
            }
            $row = DB::table('password_reset_tokens')->where('email', $key)->lockForUpdate()->first();
            $storedToken = $row?->token ?? '';
            $validToken = strlen($storedToken) === 64 ? hash_equals($storedToken, hash('sha256', $data['token']))
                : Hash::check($data['token'], $storedToken ?: $dummyHash);
            $createdAt = $row?->created_at !== null ? Carbon::parse($row->created_at) : null;
            if ($customer === null || $customer->status === CustomerStatus::Suspended || $row === null
                || $createdAt === null || now()->subMinutes(60)->gte($createdAt)
                || now()->lt($createdAt) || ! $validToken) {
                throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
            }
            if (strlen((string) ($data['password'] ?? '')) > 72) {
                throw ValidationException::withMessages(['password' => 'كلمة المرور يجب ألا تتجاوز 72 بايت.']);
            }
            if ($customer->isCommercialTier() && mb_strlen($data['password']) < 12) {
                throw ValidationException::withMessages(['password' => 'كلمة المرور يجب أن تكون 12 حرفًا على الأقل.']);
            }
            $customer->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $customer->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $key)->delete();
            CustomerAuditLog::record($customer, 'password_reset', null);
        });
    }

    /**
     * Resolve the customer a password reset may address.
     *
     * Commercial accounts match findForLogin, including an unverified login email.
     * Retail email resets still require email_verified_at.
     */
    private function findForPasswordReset(?string $email, ?string $phone): ?Customer
    {
        if (is_string($email) && trim($email) !== '') {
            $customer = $this->findForLogin($email);
            if ($customer === null) {
                return null;
            }
            if ($customer->email_verified_at !== null || $customer->isCommercialTier()) {
                return $customer;
            }

            return null;
        }

        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        return Customer::query()->where('phone', Customer::normalizePhone($phone))->first();
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

            StaffTokenService::pruneAndCap($customer, (int) config('auth.customer_max_tokens', 10), (int) config('auth.customer_token_minutes', 1440));

            // Scope the token to its channel instead of granting wildcard abilities,
            // and keep the lifetime short because these tokens live in localStorage.
            $token = $customer->createToken($name, [$name], now()->addMinutes(max(1, (int) config('auth.customer_token_minutes', 1440))));
            CustomerAuditLog::record($customer, 'token_issued', null, ['token_id' => $token->accessToken->id, 'channel' => $name]);

            return $token->plainTextToken;
        });
    }
}
