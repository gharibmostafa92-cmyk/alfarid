<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Auth\CustomerAuthService;
use App\Rules\TurnstileToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Unified authentication coordinator across staff, dealer, and customer account types.
 *
 * Implements multi-tier rate limiting against brute force, IP pooling, and constant-time dummy password hashing.
 */
final class UnifiedAuthService
{
    public function __construct(private readonly CustomerAuthService $customerAuth) {}

    /**
     * Authenticates an actor using email or phone credentials across multiple user guards.
     *
     * @param  string  $login  Raw login identifier (email address or phone number).
     * @param  string  $password  Plaintext credentials.
     * @return User|SupplierUser|Customer Authenticated model instance.
     *
     * @throws BusinessException If credentials are invalid (401) or account is suspended (403).
     * @throws TooManyRequestsHttpException If brute-force rate limit thresholds are exceeded (429).
     */
    public function attempt(string $login, string $password): User|SupplierUser|Customer
    {
        $identifier = Customer::normalizeIdentifier($login) ?? '';
        // Hash identifier in cache keys to prevent exposing plaintext emails or phone numbers in key stores.
        $key = 'unified-login:failures:'.hash_hmac('sha256', $identifier, (string) config('app.key'));
        $ipKey = 'unified-login:ip-failures:'.NetworkIdentity::key(request());
        $pairKey = $key.':ip:'.NetworkIdentity::key(request()).':hour';
        $dayKey = $key.':day';
        $hourKey = $key.':hour';
        $dayLimit = max(1, (int) config('auth.unified_login_failures_day', 100));
        $hourLimit = max(1, (int) config('auth.unified_login_identifier_failures_hour', 30));
        $pairLimit = max(1, (int) config('auth.unified_login_failures_hour', 10));
        $ipLimit = max(1, (int) config('auth.unified_login_ip_failures_hour', 1000));
        // H1: broader /48 bucket for IPv6 (one /48 holds 65,536 /64 networks).
        $wideKey = 'unified-login:wide-failures:'.NetworkIdentity::wideKey(request());
        $wideLimit = max(1, (int) config('auth.unified_login_wide_failures_hour', 200));
        // Reserve the per-identifier+network slot atomically before any bcrypt work (H2).
        if (RateLimiter::hit($pairKey, 3600) > $pairLimit) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($pairKey), 'محاولات دخول كثيرة؛ حاول لاحقًا.');
        }
        $staff = str_contains($identifier, '@') ? User::query()->whereRaw('LOWER(email) = ?', [$identifier])->first() : null;
        $hourLimitReached = RateLimiter::tooManyAttempts($hourKey, $hourLimit);
        $broadLimitReached = RateLimiter::tooManyAttempts($ipKey, $ipLimit);
        $dayLimitReached = RateLimiter::tooManyAttempts($dayKey, $dayLimit);
        $wideLimitReached = RateLimiter::tooManyAttempts($wideKey, $wideLimit);
        $suppressAudit = $hourLimitReached || $dayLimitReached || $broadLimitReached || $wideLimitReached;
        // H1: after any soft limit, do not burn bcrypt before a human challenge.
        // Legit users behind shared NAT complete Turnstile; bots and free bcrypt stop here.
        if ($suppressAudit) {
            $validator = Validator::make(
                ['turnstile_token' => request('turnstile_token')],
                ['turnstile_token' => [new TurnstileToken(request()->ip())]]
            );
            if ($validator->fails()) {
                throw new BusinessException('أكمل التحقق البشري للمتابعة.', [], 429, 'challenge_required');
            }
        }
        try {
            $actor = $this->resolve($identifier, $password, $staff, $suppressAudit);
        } catch (BusinessException $exception) {
            if ($exception->status === 401) {
                RateLimiter::hit($hourKey, 3600);
                RateLimiter::hit($wideKey, 3600);
                if (RateLimiter::hit($ipKey, 3600) === $ipLimit) {
                    // ip_failures aggregates a network, not one account: never attribute it to the last identifier tried.
                    app(AuthenticationAlerts::class)->report('ip_failures', null);
                }
                if (RateLimiter::hit($dayKey, 86400) === $dayLimit) {
                    app(AuthenticationAlerts::class)->report('identifier_failures', $staff?->id);
                }
                // Broad IP/Day quotas must not lock out valid credentials behind shared NAT or enterprise proxies.
                if ($broadLimitReached || $dayLimitReached || $hourLimitReached || $wideLimitReached) {
                    throw new TooManyRequestsHttpException(60, 'محاولات دخول كثيرة؛ حاول لاحقًا.');
                }
            }
            throw $exception;
        }
        RateLimiter::clear($pairKey);

        return $actor;
    }

    private function resolve(string $login, string $password, ?User $staff = null, bool $suppressAudit = false): User|SupplierUser|Customer
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            $email = mb_strtolower($login);

            // Enforce constant-time verification using dummy hash when candidate is missing to prevent user enumeration.
            // L4: supplier portal has its own AuthController; unified login only serves staff + customers.
            $customer = $this->customerAuth->findForLogin($email);

            // Log security warning if identical email exists across multiple user tables.
            $present = array_values(array_filter([$staff, $customer]));
            if (count($present) >= 2) {
                logger()->warning('auth.shared_email', ['identifier_key' => hash_hmac('sha256', $email, (string) config('app.key'))]);
            }

            if ($present === []) {
                Hash::check($password, CustomerAuthService::dummyHash());
                logger()->info('unified.login', ['result' => 'wrong_password']);

                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }

            $matched = null;
            $inactiveAuditedIds = [];
            foreach ($present as $candidate) {
                if (! Hash::check($password, $candidate->password)) {
                    continue;
                }
                if ($candidate instanceof User && (! $candidate->is_active || $candidate->is_system)) {
                    // L3: record inactive once; the wrong_password block below must not double-count it.
                    $inactiveAuditedIds[] = $candidate->id;
                    if (! $suppressAudit) {
                        StaffAuthenticationAudit::record($candidate, 'inactive');
                    }
                    logger()->info('staff.login', ['user_id' => $candidate->id, 'ip' => request()->ip(), 'result' => 'inactive']);

                    continue;
                }
                $matched = $candidate;
                break;
            }

            if ($matched === null) {
                if ($customer !== null) {
                    defer(fn () => CustomerAuditLog::record($customer, 'login_failed', null, [
                        'identifier_key' => hash_hmac('sha256', $email, (string) config('app.key')),
                    ]))->always();
                } else {
                    logger()->info('unified.login', ['result' => 'wrong_password']);
                }

                foreach ([$staff] as $account) {
                    if ($account instanceof User && ! $suppressAudit && ! in_array($account->id, $inactiveAuditedIds, true)) {
                        StaffAuthenticationAudit::record($account, 'wrong_password');
                    }
                    if ($account !== null) {
                        logger()->info('staff.login', [
                            'user_id' => $account->id, 'ip' => request()->ip(), 'result' => 'wrong_password',
                        ]);
                    }
                }
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }

            if ($matched instanceof User) {
                $this->rehashIfNeeded($matched, $password);
                StaffAuthenticationAudit::record($matched, 'success');

                logger()->info('staff.login', ['user_id' => $matched->id, 'ip' => request()->ip(), 'result' => 'success']);

                return $matched;
            }

            /** @var Customer $matched */
            if ($matched->status === CustomerStatus::Suspended) {
                CustomerAuditLog::record($matched, 'login_failed', null, ['reason' => 'suspended']);

                throw new BusinessException(
                    'هذا الحساب موقوف.'.($matched->suspension_reason ? ' السبب: '.$matched->suspension_reason : ''),
                    ['suspension_reason' => $matched->suspension_reason],
                    403,
                );
            }
            // Rehash password only after verifying active account status.
            $this->rehashIfNeeded($matched, $password);
            CustomerAuditLog::record($matched, 'login_succeeded', null);

            return $matched;
        }

        return $this->customerAuth->login($login, $password);
    }

    private function rehashIfNeeded(User|SupplierUser|Customer $model, string $password): void
    {
        if (Hash::needsRehash($model->password)) {
            $original = $model->password;
            $replacement = Hash::make($password);
            // A rehash must never overwrite an administrator's concurrent password reset.
            if ($model->newQuery()->whereKey($model->id)->where('password', $original)->update(['password' => $replacement]) === 1) {
                // Keep the exact credential snapshot just verified; a later reset invalidates its handoff.
                $model->forceFill(['password' => $replacement])->syncOriginalAttribute('password');

                return;
            }
            // A concurrent request may have completed the rehash first; verify against the fresh hash.
            $fresh = $model->newQuery()->whereKey($model->id)->value('password');
            if (! is_string($fresh) || ! Hash::check($password, $fresh)) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            $model->forceFill(['password' => $fresh])->syncOriginalAttribute('password');
        }
    }
}
