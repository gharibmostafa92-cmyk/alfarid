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
use App\Notifications\Auth\CustomerWelcomeNotification;
use App\Notifications\Channels\CustomerSmsChannel;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\UniqueCustomerEmail;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use App\Services\CustomerEmail\CustomerEmailOutbox;
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
    private const MAX_PASSWORD_BYTES = 72;

    private const COMMERCIAL_MIN_PASSWORD_LENGTH = 12;

    private const PLAIN_RESET_TOKEN_LENGTH = 64;

    private const SHA256_HEX_LENGTH = 64;

    private const RESET_THROTTLE_SECONDS = 60;

    private const RESET_TOKEN_TTL_MINUTES = 60;

    private const REMEMBER_TOKEN_LENGTH = 60;

    private const LOGIN_FAILURE_SAMPLE_RATE = 10;

    public function __construct(
        private readonly CustomerEmailOutbox $customerEmails,
    ) {}

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

        return $contacts->count() === 1 ? $contacts->first() : null;
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
     * Self-register a retail store customer.
     * Enforces Retail type and Approved status unconditionally.
     */
    public function registerRetail(array $data): Customer
    {
        $this->assertPasswordWithinBcryptLimit((string) ($data['password'] ?? ''));

        try {
            return DB::transaction(function () use ($data): Customer {
                $this->lockRegistrationEmail($data['email'] ?? null);
                $phoneVerified = app(CustomerPhoneVerificationService::class)->consume($data);

                $customer = $this->createRetailCustomer($data, $phoneVerified);
                CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'store']);
                $this->customerEmails->welcome($customer);
                $this->scheduleWelcomeNotification($customer);

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

        $normalized = mb_strtolower(trim($email));
        AccountEmailLock::acquire($normalized);

        Validator::make(['email' => $normalized], [
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
        $normalizedIdentifier = Customer::normalizeIdentifier($identifier) ?? '';
        $customer = $this->verifyCredentialsOrFail($normalizedIdentifier, $password, self::dummyHash());

        $this->assertAccountCanLogin($customer);
        $this->rehashPasswordIfNeeded($customer, $password);
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

        $plainToken = Str::random(self::PLAIN_RESET_TOKEN_LENGTH);
        $key = $customer->getEmailForPasswordReset();
        // Pin the budget timestamp once: a slow provider failure must refund
        // the hour/day bucket the quota was claimed from, not the later one.
        $budgetAt = CarbonImmutable::now($this->businessTimezone());

        DB::transaction(fn () => $this->attemptStoreResetToken(
            $customer,
            $key,
            $plainToken,
            $email,
            $networkKey,
            $budgetAt,
        ));
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
            $customer = $this->lockCustomerForPasswordReset($email, $phone);
            $key = $customer?->getEmailForPasswordReset();
            $plainToken = (string) ($data['token'] ?? '');

            $this->assertValidResetToken($customer, $key, $plainToken, $dummyHash);

            $password = (string) ($data['password'] ?? '');
            $this->assertNewPasswordValid($customer, $password);
            $this->applyPasswordReset($customer, (string) $key, $password);
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
        $verifiedHash = $customer->getAuthPassword();

        return DB::transaction(function () use ($customer, $name, $verifiedHash): string {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($lockedCustomer->getAuthPassword() !== $verifiedHash) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            if ($lockedCustomer->status === CustomerStatus::Suspended) {
                throw new BusinessException('هذا الحساب موقوف.', [], 403);
            }

            StaffTokenService::pruneAndCap(
                $lockedCustomer,
                (int) config('auth.customer_max_tokens', 10),
                (int) config('auth.customer_token_minutes'),
            );

            // Scope the token to its channel instead of granting wildcard abilities,
            // and keep the lifetime short because these tokens live in localStorage.
            $token = $lockedCustomer->createToken(
                $name,
                [$name],
                now()->addMinutes(max(1, (int) config('auth.customer_token_minutes'))),
            );
            CustomerAuditLog::record($lockedCustomer, 'token_issued', null, [
                'token_id' => $token->accessToken->id,
                'channel' => $name,
            ]);

            return $token->plainTextToken;
        });
    }

    private function createRetailCustomer(array $data, bool $phoneVerified): Customer
    {
        $customer = Customer::query()->make([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'password' => $data['password'],
            'type' => CustomerType::Retail,
            'phone_verified_at' => $phoneVerified ? now() : null,
        ]);
        $customer->forceFill(['status' => CustomerStatus::Approved, 'approved_at' => now()])->save();

        return $customer;
    }

    private function scheduleWelcomeNotification(Customer $customer): void
    {
        DB::afterCommit(static function () use ($customer): void {
            if (config('services.resend.capture_enabled') !== true && filled($customer->email)) {
                try {
                    $customer->notify(new CustomerWelcomeNotification);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    private function verifyCredentialsOrFail(string $identifier, string $password, string $dummyHash): Customer
    {
        $customer = $this->findForLogin($identifier);
        $hasPassword = $customer !== null && is_string($customer->password) && $customer->password !== '';
        $validPassword = Hash::check($password, $hasPassword ? $customer->password : $dummyHash) && $hasPassword;

        if ($customer === null || ! $validPassword) {
            $this->recordFailedLogin($customer, $identifier);
            throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
        }

        return $customer;
    }

    private function recordFailedLogin(?Customer $customer, string $identifier): void
    {
        // Avoid inserting unbounded audit log rows for nonexistent identifiers during stuffing attacks;
        // log sampled info metric instead, keyed by HMAC.
        if ($customer === null) {
            if (random_int(1, self::LOGIN_FAILURE_SAMPLE_RATE) === 1) {
                logger()->info('customer.login_failed', ['identifier_key' => $this->identifierKey($identifier)]);
            }

            return;
        }

        defer(fn () => CustomerAuditLog::record(
            $customer,
            'login_failed',
            null,
            ['identifier_key' => $this->identifierKey($identifier)],
        ))->always();
    }

    private function assertAccountCanLogin(Customer $customer): void
    {
        if ($customer->status === CustomerStatus::Suspended) {
            CustomerAuditLog::record($customer, 'login_failed', null, ['reason' => 'suspended']);
            throw new BusinessException('هذا الحساب موقوف. يرجى التواصل مع خدمة العملاء.', [], 403, 'account_inactive');
        }

        if ((bool) config('auth.require_phone_verification', false) && $customer->phone_verified_at === null) {
            throw new BusinessException('يرجى توثيق رقم الهاتف أولاً.', [], 403, 'phone_verification_required');
        }
    }

    private function rehashPasswordIfNeeded(Customer $customer, string $password): void
    {
        if (! Hash::needsRehash($customer->password)) {
            return;
        }

        $original = $customer->password;
        $replacement = Hash::make($password);
        $updated = $customer->newQuery()
            ->whereKey($customer->id)
            ->where('password', $original)
            ->update(['password' => $replacement]);

        if ($updated === 1) {
            $customer->forceFill(['password' => $replacement])->syncOriginalAttribute('password');

            return;
        }

        // Verify against freshly persisted hash if a concurrent request completed the rehash first.
        $fresh = $customer->newQuery()->whereKey($customer->id)->value('password');
        if (! is_string($fresh) || ! Hash::check($password, $fresh)) {
            throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
        }

        $customer->forceFill(['password' => $fresh])->syncOriginalAttribute('password');
    }

    private function attemptStoreResetToken(
        Customer $customer,
        string $key,
        string $plainToken,
        ?string $email,
        ?string $networkKey,
        CarbonImmutable $budgetAt,
    ): bool {
        $current = Customer::query()->lockForUpdate()->find($customer->id);
        if ($current === null || $current->status === CustomerStatus::Suspended) {
            return false;
        }
        if ($email && ! $this->canUseEmailForPasswordReset($current, $email)) {
            return false;
        }
        if ($this->hasRecentResetToken($key)) {
            return false;
        }

        $claimed = app(CustomerMessageBudget::class)->claim(
            'reset:customer:'.$customer->id,
            sms: ! $email,
            networkKey: $networkKey,
            at: $budgetAt,
        );
        if (! $claimed) {
            return false;
        }

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $key],
            ['token' => hash('sha256', $plainToken), 'created_at' => now()],
        );

        $this->scheduleResetNotification($customer, $key, $plainToken, $email, $networkKey, $budgetAt);

        return true;
    }

    private function hasRecentResetToken(string $key): bool
    {
        $existing = DB::table('password_reset_tokens')->where('email', $key)->first();

        return $existing !== null
            && $existing->created_at !== null
            && now()->subSeconds(self::RESET_THROTTLE_SECONDS)->lt(Carbon::parse($existing->created_at));
    }

    private function scheduleResetNotification(
        Customer $customer,
        string $key,
        string $plainToken,
        ?string $email,
        ?string $networkKey,
        CarbonImmutable $budgetAt,
    ): void {
        DB::afterCommit(function () use ($customer, $key, $plainToken, $email, $networkKey, $budgetAt): void {
            try {
                $channel = $email ? 'mail' : CustomerSmsChannel::class;
                $customer->notify(new CustomerPasswordReset($plainToken, $channel));
            } catch (\Throwable $exception) {
                // The token never reached the customer: drop it so the
                // queued retry regenerates a fresh one instead of hitting
                // the 60s throttle on a dead token, refund the budget,
                // and rethrow so the job retries with backoff.
                $this->handleResetDeliveryFailure($customer, $key, $plainToken, ! $email, $networkKey, $budgetAt, $exception);
            }
        });
    }

    private function lockCustomerForPasswordReset(string $email, string $phone): ?Customer
    {
        $customer = $this->findForPasswordReset($email !== '' ? $email : null, $phone !== '' ? $phone : null);
        if ($customer !== null) {
            $customer = Customer::query()->lockForUpdate()->find($customer->id);
        }

        if ($customer !== null && $email !== '' && ! $this->canUseEmailForPasswordReset($customer, $email)) {
            return null;
        }

        return $customer;
    }

    private function assertValidResetToken(?Customer $customer, ?string $key, string $plainToken, string $dummyHash): void
    {
        if (! is_string($key) || $key === '') {
            Hash::check($plainToken, $dummyHash);
            throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
        }

        $row = DB::table('password_reset_tokens')->where('email', $key)->lockForUpdate()->first();
        $validToken = $this->isResetTokenValid($row, $plainToken, $dummyHash);
        $createdAt = $row?->created_at !== null ? Carbon::parse($row->created_at) : null;

        if ($customer === null
            || $customer->status === CustomerStatus::Suspended
            || $row === null
            || $createdAt === null
            || now()->subMinutes(self::RESET_TOKEN_TTL_MINUTES)->gte($createdAt)
            || now()->lt($createdAt)
            || ! $validToken) {
            throw new BusinessException('رمز الاستعادة غير صالح أو انتهت صلاحيته.');
        }
    }

    private function isResetTokenValid(?object $row, string $plainToken, string $dummyHash): bool
    {
        $storedToken = $row?->token ?? '';

        return strlen($storedToken) === self::SHA256_HEX_LENGTH
            ? hash_equals($storedToken, hash('sha256', $plainToken))
            : Hash::check($plainToken, $storedToken ?: $dummyHash);
    }

    private function assertNewPasswordValid(Customer $customer, string $password): void
    {
        $this->assertPasswordWithinBcryptLimit($password);

        if ($customer->isCommercialTier() && mb_strlen($password) < self::COMMERCIAL_MIN_PASSWORD_LENGTH) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور يجب أن تكون 12 حرفًا على الأقل.']);
        }
    }

    private function applyPasswordReset(Customer $customer, string $key, string $password): void
    {
        $customer->forceFill([
            'password' => $password,
            'remember_token' => Str::random(self::REMEMBER_TOKEN_LENGTH),
        ])->save();

        $customer->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $key)->delete();
        CustomerAuditLog::record($customer, 'password_reset', null);
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

            return $customer !== null && $this->isEmailResetEligible($customer)
                ? $customer
                : null;
        }

        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        return Customer::query()->where('phone', Customer::normalizePhone($phone))->first();
    }

    private function canUseEmailForPasswordReset(Customer $customer, string $email): bool
    {
        $emailMatches = mb_strtolower((string) $customer->email) === mb_strtolower(trim($email));

        return $emailMatches && $this->isEmailResetEligible($customer);
    }

    private function isEmailResetEligible(Customer $customer): bool
    {
        return $customer->email_verified_at !== null || $customer->isCommercialTier();
    }

    private function assertPasswordWithinBcryptLimit(string $password): void
    {
        // Defense-in-depth: bcrypt truncates past 72 bytes, so reject early
        // on every entry path even when request validation already caps it.
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور يجب ألا تتجاوز 72 بايت.']);
        }
    }

    /**
     * Compute HMAC key for phone/email to prevent rainbow-table reversal on small phone number spaces in audit logs.
     */
    private function identifierKey(string $identifier): string
    {
        return hash_hmac('sha256', $identifier, (string) config('app.key'));
    }

    private function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Africa/Cairo');
    }

    private function isAmbiguousDeliveryTimeout(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'timeout');
    }
}
