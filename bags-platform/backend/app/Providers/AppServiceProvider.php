<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\ReturnRequest;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Services\BranchAccess;
use App\Services\SettingService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped لا singleton: ذاكرة المستخدمين لكل طلب تعمل داخل الطلب، ولا
        // تتسرب صلاحيات مستخدم لطلب آخر تحت Octane (L-6).
        $this->app->scoped(BranchAccess::class);
        $this->app->scoped(SettingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $proxies = config('app.trusted_proxies');
        if ($this->app->environment('production') && (! is_string($proxies) || trim($proxies) === '' || preg_match('/(?:^|,)\s*\*{1,2}\s*(?:,|$)/', $proxies))) {
            throw new \LogicException('Set TRUSTED_PROXIES to explicit proxy IPs/CIDRs, or none for direct access. Wildcards are not allowed in production.');
        }
        TrustProxies::at(is_string($proxies) && trim($proxies) !== '' && $proxies !== 'none'
            ? array_map('trim', explode(',', $proxies)) : []);
        Event::listen(QueueBusy::class,
            fn ($event) => logger()->error('Queue backlog exceeded its configured threshold.', ['connection' => $event->connectionName, 'queue' => $event->queue, 'size' => $event->size]));
        RateLimiter::for('customer-registration', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.registration_ip_minute', 20))->by('customer-register-minute:'.$request->ip()),
            Limit::perDay(config('auth.customer_limits.registration_ip_day', 500))->by('customer-register-day:'.$request->ip()),
        ]);
        RateLimiter::for('store-catalog', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.catalog_ip_minute', 600))->by('store-catalog:'.$request->ip()),
            ...($request->hasAny(['price_min', 'price_max']) || in_array($request->input('sort'), ['price_asc', 'price_desc'], true)
                ? [Limit::perMinute(config('auth.customer_limits.price_ip_minute', 20))->by('store-price-query:'.$request->ip()),
                    Limit::perMinute(config('auth.customer_limits.price_global_minute', 200))->by('store-price-query:global')] : []),
        ]);
        $identifierKey = function (Request $request): string {
            $email = $request->input('email');
            $phone = $request->input('phone');

            return hash('sha256', is_string($email) && $email !== '' ? mb_strtolower(trim($email))
                : (is_string($phone) ? Customer::normalizePhone($phone) : 'invalid'));
        };
        RateLimiter::for('customer-reset-confirm', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.reset_confirm_ip_minute', 120))->by('reset-confirm-ip:'.$request->ip()),
            Limit::perMinute(10)->by('reset-confirm-id:'.$identifierKey($request)),
        ]);
        $phoneKey = fn (Request $request): string => hash('sha256', is_string($request->input('phone')) ? Customer::normalizePhone($request->input('phone')) : 'invalid');
        RateLimiter::for('customer-phone-send', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.phone_ip_minute', 20))->by('phone-send-ip:'.$request->ip()),
            Limit::perDay(config('auth.customer_limits.phone_ip_day', 500))->by('phone-send-day:'.$request->ip()),
            Limit::perMinute(1)->by('phone-send-id:'.$phoneKey($request)),
        ]);
        RateLimiter::for('customer-phone-verify', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.phone_verify_ip_minute', 120))->by('phone-verify-ip:'.$request->ip()),
            Limit::perMinute(5)->by('phone-verify-id:'.$phoneKey($request)),
        ]);
        // أسماء قصيرة ثابتة لمراجع الدفتر (stock_movements.reference_type):
        // إعادة تسمية/نقل الكلاسات لاحقًا لا تكسر أثر التدقيق التاريخي.
        // الصفوف القديمة المخزنة باسم الكلاس الكامل تظل قابلة للقراءة.
        Relation::morphMap([
            'stock_transfer' => StockTransfer::class,
            'stocktake' => Stocktake::class,
            'order' => Order::class,
            'return_request' => ReturnRequest::class,
            'purchase_order' => PurchaseOrder::class,
        ]);

        // حد دخول العملاء (متجر/جملة): بالمُعرّف (بريد/هاتف) وبالـ IP معًا
        RateLimiter::for('customer-login', function (Request $request): array {
            $identifier = $request->input('login');
            $idKey = is_string($identifier) ? hash('sha256', str_contains($identifier, '@')
                ? Str::lower(trim($identifier)) : Customer::normalizePhone($identifier)) : 'none';

            return [
                Limit::perMinute(5)->by('customer-login-id:'.$idKey),
                Limit::perHour(config('auth.customer_limits.login_id_hour', 30))->by('customer-login-id-hour:'.$idKey),
                Limit::perDay(config('auth.customer_limits.login_id_day', 100))->by('customer-login-id-day:'.$idKey),
                Limit::perMinute(config('auth.customer_limits.login_ip_minute', 120))->by('customer-login-ip:'.($request->ip() ?? 'unknown')),
            ];
        });

        // حدود مسارات السلة وإتمام الشراء وتتبع الطلب والويب هوك (M3/M5)
        RateLimiter::for('cart', fn (Request $request) => [
            Limit::perMinute(60)->by('cart-ip:'.$request->ip()),
        ]);
        // تخمين أكواد الكوبونات (F-24): حد ضيق خاص لكل IP وأيضًا لكل
        // توكن/جلسة سلة، بدل الاعتماد على حد السلة العام فقط
        RateLimiter::for('coupon', function (Request $request): array {
            $identity = $request->bearerToken() ?? $request->header('X-Cart-Session') ?? 'guest';

            return [
                Limit::perMinute(10)->by('coupon-ip:'.$request->ip()),
                Limit::perMinute(10)->by('coupon-id:'.hash('sha256', (string) $identity)),
            ];
        });
        // إتمام الشراء: بالـ IP وأيضًا بالهاتف المُوحَّد (F5) — الزائر بلا
        // تحقق من الهاتف لا يراكم طلبات وهمية يحبس بها مخزونًا برقم واحد
        RateLimiter::for('checkout', function (Request $request): array {
            $phone = $request->input('recipient_phone');
            $phoneKey = hash('sha256', is_string($phone) && trim($phone) !== ''
                ? Customer::normalizePhone($phone) : 'guest');

            return [
                Limit::perMinute(10)->by('checkout-ip:'.$request->ip()),
                Limit::perMinute(5)->by('checkout-phone:'.$phoneKey),
                Limit::perDay(20)->by('checkout-phone-day:'.$phoneKey),
            ];
        });
        RateLimiter::for('order-tracking', fn (Request $request) => [
            Limit::perMinute(10)->by('order-tracking-ip:'.$request->ip()),
        ]);
        RateLimiter::for('webhooks', fn (Request $request) => [
            Limit::perMinute(120)->by('webhooks-ip:'.$request->ip()),
        ]);

        RateLimiter::for('supplier-login', function (Request $request): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email)
                ? Str::transliterate(Str::lower(trim($email)))
                : '';
            $emailKey = hash('sha256', $normalizedEmail);

            return [
                Limit::perMinute(5)->by('supplier-login-email:'.$emailKey),
                Limit::perHour(30)->by('supplier-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('supplier-login-ip:'.($request->ip() ?? 'unknown')),
            ];
        });

        RateLimiter::for('admin-login', function (Request $request): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email)
                ? Str::transliterate(Str::lower(trim($email)))
                : '';
            $emailKey = hash('sha256', $normalizedEmail);

            return [
                Limit::perMinute(5)->by('admin-login-email:'.$emailKey),
                Limit::perHour(30)->by('admin-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('admin-login-ip:'.($request->ip() ?? 'unknown')),
            ];
        });

        RateLimiter::for('customer-password-reset', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.reset_ip_minute', 20))->by('customer-password-reset:'.$request->ip()),
            Limit::perDay(config('auth.customer_limits.reset_ip_day', 500))->by('customer-password-reset-day:'.$request->ip()),
            Limit::perMinute(5)->by('customer-password-reset-id:'.$identifierKey($request)),
            Limit::perDay(10)->by('customer-password-reset-id-day:'.$identifierKey($request)),
        ]);
    }
}
