<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\CustomerAuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class UnifiedAuthService
{
    public function __construct(private readonly CustomerAuthService $customerAuth) {}

    public function attempt(string $login, string $password): User|SupplierUser|Customer
    {
        $identifier = str_contains($login, '@') ? mb_strtolower(trim($login)) : Customer::normalizePhone($login);
        $key = 'unified-login:failures:'.hash_hmac('sha256', $identifier, (string) config('app.key'));
        $ipKey = 'unified-login:ip-failures:'.NetworkIdentity::key(request());
        $pairKey = $key.':ip:'.NetworkIdentity::key(request()).':hour';
        $dayKey = $key.':day';
        $hourKey = $key.':hour';
        $staff = str_contains($identifier, '@') ? User::query()->whereRaw('LOWER(email) = ?', [$identifier])->first() : null;
        $dayLimit = max(1, (int) config('auth.unified_login_failures_day', 100));
        $hourLimit = max(1, (int) config('auth.unified_login_identifier_failures_hour', 30));
        $pairLimit = max(1, (int) config('auth.unified_login_failures_hour', 10));
        $ipLimit = max(1, (int) config('auth.unified_login_ip_failures_hour', 1000));
        if (RateLimiter::tooManyAttempts($pairKey, $pairLimit)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($pairKey), 'محاولات دخول كثيرة؛ حاول لاحقًا.');
        }
        $hourLimitReached = RateLimiter::tooManyAttempts($hourKey, $hourLimit);
        $broadLimitReached = RateLimiter::tooManyAttempts($ipKey, $ipLimit);
        $dayLimitReached = RateLimiter::tooManyAttempts($dayKey, $dayLimit);
        try {
            $actor = $this->resolve($identifier, $password, $staff);
        } catch (BusinessException $exception) {
            if ($exception->status === 401) {
                RateLimiter::hit($pairKey, 3600);
                RateLimiter::hit($hourKey, 3600);
                if (RateLimiter::hit($ipKey, 3600) === $ipLimit) {
                    app(AuthenticationAlerts::class)->report('ip_failures', $staff?->id);
                }
                if (RateLimiter::hit($dayKey, 86400) === $dayLimit) {
                    app(AuthenticationAlerts::class)->report('identifier_failures', $staff?->id);
                }
                // Broad quotas must not lock out valid credentials behind NAT or CGNAT.
                if ($broadLimitReached || $dayLimitReached || $hourLimitReached) {
                    throw new TooManyRequestsHttpException(60, 'محاولات دخول كثيرة؛ حاول لاحقًا.');
                }
            }
            throw $exception;
        }
        RateLimiter::clear($pairKey);

        return $actor;
    }

    private function resolve(string $login, string $password, ?User $staff = null): User|SupplierUser|Customer
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            $email = mb_strtolower($login);

            // H3: bcrypt واحدة ثابتة لكل محاولة بالبريد — نجلب المرشحين من
            // الجداول الثلاثة (استعلامات indexed رخيصة)، ثم نفحص مرشحًا واحدًا
            // بالأولوية (staff ثم supplier ثم customer) أو dummy hash عند غياب
            // الجميع. الفحص الإضافي يحدث فقط في حالة البريد المشترك الشاذة.
            $supplierUser = null; // Supplier accounts are historical; dealers use Customer accounts.
            $customer = $this->customerAuth->findForLogin($email);

            // L4: رصد أي بريد مشترك بين جدولين — بما فيها العملاء.
            $present = array_values(array_filter([$staff, $supplierUser, $customer]));
            if (count($present) >= 2) {
                logger()->warning('auth.shared_email', ['identifier_key' => hash_hmac('sha256', $email, (string) config('app.key'))]);
            }

            if ($present === []) {
                Hash::check($password, CustomerAuthService::dummyHash());
                logger()->info('unified.login', ['result' => 'wrong_password']);

                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }

            $matched = null;
            foreach ($present as $candidate) {
                if (! Hash::check($password, $candidate->password)) {
                    continue;
                }
                if ($candidate instanceof User && (! $candidate->is_active || $candidate->is_system)) {
                    StaffAuthenticationAudit::record($candidate, 'inactive');
                    logger()->info('staff.login', ['user_id' => $candidate->id, 'ip' => request()->ip(), 'result' => 'inactive']);

                    continue;
                }
                if ($candidate instanceof SupplierUser && (! $candidate->is_active || $candidate->supplier_id === null
                    || $candidate->supplier?->is_active !== true)) {
                    logger()->info('supplier.login', ['supplier_user_id' => $candidate->id, 'ip' => request()->ip(), 'result' => 'inactive']);

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

                foreach ([$staff, $supplierUser] as $account) {
                    if ($account instanceof User) {
                        StaffAuthenticationAudit::record($account, 'wrong_password');
                    }
                    if ($account !== null) {
                        logger()->info($account instanceof User ? 'staff.login' : 'supplier.login', [
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

            if ($matched instanceof SupplierUser) {
                $this->rehashIfNeeded($matched, $password);

                logger()->info('supplier.login', ['supplier_user_id' => $matched->id, 'ip' => request()->ip(), 'result' => 'success']);

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
            // L7: إعادة التجزئة بعد فحص الإيقاف.
            $this->rehashIfNeeded($matched, $password);
            CustomerAuditLog::record($matched, 'login_succeeded', null);

            return $matched;
        }

        // يرمي BusinessException ‏401 أو 403 بنفسه
        return $this->customerAuth->login($login, $password);
    }

    private function rehashIfNeeded(User|SupplierUser|Customer $model, string $password): void
    {
        if (Hash::needsRehash($model->password)) {
            $original = $model->password;
            $replacement = Hash::make($password);
            // A rehash must never overwrite an administrator's concurrent password reset.
            if ($model->newQuery()->whereKey($model->id)->where('password', $original)->update(['password' => $replacement]) !== 1) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            // Keep the exact credential snapshot just verified; a later reset invalidates its handoff.
            $model->forceFill(['password' => $replacement])->syncOriginalAttribute('password');
        }
    }
}
