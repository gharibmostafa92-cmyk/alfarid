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
        $limits = [
            'hour' => [max(1, (int) config('auth.unified_login_failures_hour', 100)), 3600],
            'day' => [max(1, (int) config('auth.unified_login_failures_day', 100)), 86400],
        ];
        foreach ($limits as $window => [$limit]) {
            if (RateLimiter::tooManyAttempts($key.':'.$window, $limit)) {
                throw new TooManyRequestsHttpException(RateLimiter::availableIn($key.':'.$window), 'محاولات دخول كثيرة؛ حاول لاحقًا.');
            }
        }

        try {
            return $this->resolve($identifier, $password);
        } catch (BusinessException $exception) {
            if ($exception->status === 401) {
                foreach ($limits as $window => [$limit, $seconds]) {
                    RateLimiter::hit($key.':'.$window, $seconds);
                }
            }
            throw $exception;
        }
    }

    private function resolve(string $login, string $password): User|SupplierUser|Customer
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            $email = mb_strtolower($login);

            // H3: bcrypt واحدة ثابتة لكل محاولة بالبريد — نجلب المرشحين من
            // الجداول الثلاثة (استعلامات indexed رخيصة)، ثم نفحص مرشحًا واحدًا
            // بالأولوية (staff ثم supplier ثم customer) أو dummy hash عند غياب
            // الجميع. الفحص الإضافي يحدث فقط في حالة البريد المشترك الشاذة.
            $staff = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $supplierUser = SupplierUser::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $customer = Customer::query()
                ->whereNotNull('email_verified_at')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

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
                if ($candidate instanceof User && ! $candidate->is_active) {
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
            if (Hash::needsRehash($matched->password)) {
                $matched->forceFill(['password' => $password])->save();
            }
            CustomerAuditLog::record($matched, 'login_succeeded', null);

            return $matched;
        }

        // يرمي BusinessException ‏401 أو 403 بنفسه
        return $this->customerAuth->login($login, $password);
    }

    private function rehashIfNeeded(User|SupplierUser $model, string $password): void
    {
        if (Hash::needsRehash($model->password)) {
            $model->forceFill(['password' => $password])->save();
        }
    }
}
