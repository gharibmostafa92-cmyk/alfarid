<?php

namespace App\Providers;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Auth\NetworkIdentity;
use App\Services\BranchAccess;
use App\Services\PricingService;
use App\Services\SettingService;
use App\Services\StoreCatalogService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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
        $this->app->scoped(StoreCatalogService::class);
        $this->app->scoped(PricingService::class);
        $this->app->scoped(CatalogRetailPriceObserver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([ProductVariant::class, PriceListItem::class, PriceList::class,
            StockLevel::class, Branch::class, Location::class] as $model) {
            $model::observe(CatalogRetailPriceObserver::class);
        }
        Event::listen(TransactionBeginning::class, function ($event): void {
            app(CatalogRetailPriceObserver::class)->transactionBegan($event->connection);
            if ($event->connection->transactionLevel() === 1) {
                app(SettingService::class)->resetReadSnapshot(true);
            }
        });
        Event::listen(TransactionCommitted::class, function ($event): void {
            app(CatalogRetailPriceObserver::class)->transactionFinished($event->connection);
            if ($event->connection->transactionLevel() === 0) {
                app(SettingService::class)->resetReadSnapshot(true);
            }
        });
        Event::listen(TransactionRolledBack::class, function ($event): void {
            app(CatalogRetailPriceObserver::class)->transactionFinished($event->connection, true);
            app(SettingService::class)->resetReadSnapshot($event->connection->transactionLevel() === 0);
        });
        Event::listen(JobProcessing::class, fn () => app(PermissionRegistrar::class)->clearPermissionsCollection());
        Gate::before(fn ($user) => $user instanceof User
            && $user->is_active && ! $user->is_system
            && $user->hasRole(StaffRole::SuperAdmin->value, 'staff') ? true : null);
        $proxies = config('app.trusted_proxies');
        if ($this->app->environment('production') && (! is_string($proxies) || trim($proxies) === '' || preg_match('/(?:^|,)\s*\*{1,2}\s*(?:,|$)/', $proxies))) {
            throw new \LogicException('Set TRUSTED_PROXIES to explicit proxy IPs/CIDRs, or none for direct access. Wildcards are not allowed in production.');
        }
        // F17: إعدادات افتراضية خطرة في الإنتاج — روابط ورموز الاستعادة
        // تُكتب في السجل مع mailer=log (استيلاء على الحسابات)، وsslmode=prefer
        // يسمح بالرجوع لاتصال غير مشفر. فشل مبكر كحراسة TRUSTED_PROXIES.
        if ($this->app->environment('production') && config('mail.default') === 'log') {
            throw new \LogicException('Set MAIL_MAILER to a real mailer in production. The log mailer writes password-reset links and OTP codes to the log file.');
        }
        $sslmode = config('database.connections.pgsql.sslmode');
        if ($this->app->environment('production') && config('database.default') === 'pgsql'
            && in_array($sslmode, ['prefer', 'allow', 'disable'], true)) {
            throw new \LogicException("Set DB_SSLMODE=require (or stricter) in production. '{$sslmode}' allows a plaintext database connection.");
        }
        TrustProxies::at(is_string($proxies) && trim($proxies) !== '' && $proxies !== 'none'
            ? array_map('trim', explode(',', $proxies)) : []);
        Event::listen(QueueBusy::class,
            fn ($event) => logger()->error('Queue backlog exceeded its configured threshold.', ['connection' => $event->connectionName, 'queue' => $event->queue, 'size' => $event->size]));

        // مستمعو نظام الإشعارات الموحد (المرحلة 13.1) يُكتشفون تلقائيًا من
        // app/Listeners عبر تلميحات أنواع الأحداث — لا تسجيل صريح هنا حتى
        // لا تُستدعى المستمعات مرتين.
        // H5: one IPv6 device owns a whole /64 (2^64 addresses). Keying by
        // the full $request->ip() lets an attacker rotate addresses and dodge
        // every IP bucket (SMS pumping, coupon guessing, account stuffing).
        // Collapse IPv6 to its /64; IPv4 stays as-is (one IPv4 may hide a
        // whole CGNAT cell, so it must not carry a daily budget alone).
        $networkIp = NetworkIdentity::forRequest(...);
        RateLimiter::for('customer-registration', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.registration_ip_minute', 20))->by('customer-register-minute:'.$networkIp($request)),
            Limit::perDay(config('auth.customer_limits.registration_ip_day', 500))->by('customer-register-day:'.$networkIp($request)),
        ]);
        RateLimiter::for('store-branding', fn (Request $request) => Limit::perMinute(1200)->by('store-branding:'.$networkIp($request)));
        // H2: filters + search ride the expensive price limiter. H3: no shared
        // global bucket — one global counter turns cheap traffic into a
        // store-wide 429 on the whole product list. Per-IP ceilings plus the
        // facets cache (H2) reduce load without a store-wide denial bucket.
        RateLimiter::for('store-catalog', function (Request $request) use ($networkIp) {
            $customer = auth('sanctum')->user();
            $identity = $customer instanceof Customer ? 'customer:'.$customer->id : $networkIp($request);
            $expensive = $request->hasAny(['price_min', 'price_max', 'search'])
                || in_array($request->input('sort'), ['price_asc', 'price_desc'], true)
                || ($request->route() !== null && $request->route()->getName() === 'store.catalog.filters');

            return [
                Limit::perMinute(config('auth.customer_limits.catalog_ip_minute', 600))->by('store-catalog:'.$identity),
                ...($expensive
                    ? [Limit::perMinute(config('auth.customer_limits.price_ip_minute', 60))->by('store-price-query:'.$identity)] : []),
            ];
        });
        $identifierKey = function (Request $request): string {
            $email = $request->input('email');
            $phone = $request->input('phone');

            return hash('sha256', is_string($email) && $email !== '' ? mb_strtolower(trim($email))
                : (is_string($phone) ? Customer::normalizePhone($phone) : 'invalid'));
        };
        RateLimiter::for('customer-reset-confirm', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.reset_confirm_ip_minute', 120))->by('reset-confirm-ip:'.$networkIp($request)),
            Limit::perMinute(10)->by('reset-confirm-id:'.$identifierKey($request)),
        ]);
        $phoneKey = fn (Request $request): string => hash('sha256', is_string($request->input('phone')) ? Customer::normalizePhone($request->input('phone')) : 'invalid');
        RateLimiter::for('customer-phone-send', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.phone_ip_minute', 20))->by('phone-send-ip:'.$networkIp($request)),
            Limit::perDay(config('auth.customer_limits.phone_ip_day', 500))->by('phone-send-day:'.$networkIp($request)),
            Limit::perMinute(1)->by('phone-send-id:'.$phoneKey($request)),
            // F16: سقف يومي لكل رقم — بلا سقف يمكن إرسال 1440 رسالة يوميًا
            // لضحية واحدة (إزعاج وتكلفة SMS pumping).
            Limit::perDay(config('auth.customer_limits.phone_id_day', 5))->by('phone-send-id-day:'.$phoneKey($request)),
        ]);
        RateLimiter::for('customer-phone-verify', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.phone_verify_ip_minute', 120))->by('phone-verify-ip:'.$networkIp($request)),
            Limit::perMinute(5)->by('phone-verify-id:'.$phoneKey($request)),
            // F16: سقف يومي لكل رقم — الرمز 6 أرقام ويُبطل بعد 5 محاولات
            // (CustomerPhoneVerificationService)، والسقف اليومي طبقة إضافية.
            Limit::perDay(config('auth.customer_limits.phone_verify_id_day', 20))->by('phone-verify-id-day:'.$phoneKey($request)),
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
            'pos_shift' => PosShift::class,
            'expense' => Expense::class,
            'treasury_transfer' => TreasuryTransfer::class,
            'wholesale_payment_notice' => WholesalePaymentNotice::class,
        ]);

        // حد دخول العملاء (متجر/جملة): بالمُعرّف (بريد/هاتف) وبالـ IP معًا
        RateLimiter::for('customer-login', function (Request $request) use ($networkIp): array {
            $identifier = $request->input('login');
            $idKey = is_string($identifier) ? hash('sha256', str_contains($identifier, '@')
                ? Str::lower(trim($identifier)) : Customer::normalizePhone($identifier)) : 'none';

            return [
                Limit::perMinute(5)->by('customer-login-id:'.$idKey),
                Limit::perHour(config('auth.customer_limits.login_id_hour', 30))->by('customer-login-id-hour:'.$idKey),
                Limit::perDay(config('auth.customer_limits.login_id_day', 100))->by('customer-login-id-day:'.$idKey),
                Limit::perMinute(config('auth.customer_limits.login_ip_minute', 120))->by('customer-login-ip:'.$networkIp($request)),
            ];
        });

        // حدود مسارات السلة وإتمام الشراء وتتبع الطلب والويب هوك (M3/M5)
        // H4: behind CGNAT one IPv4 hides thousands of shoppers, so logged-in
        // customers are keyed by customer_id and the IP minute ceiling is
        // generous — abuse is handled by account/phone limits, not the NAT.
        RateLimiter::for('cart', function (Request $request) use ($networkIp) {
            $customer = auth('sanctum')->user();
            if ($customer instanceof Customer) {
                return [Limit::perMinute(120)->by('cart-customer:'.$customer->id)];
            }

            // F2/F15: حد الزائر مخفّض وقابل للضبط — كل POST بلا جلسة قد
            // ينشئ سلة جديدة، و600/دقيقة لكل IP تضخّم carts/cart_items.
            return [
                Limit::perMinute(config('auth.customer_limits.cart_guest_ip_minute', 120))->by('cart-ip:'.$networkIp($request)),
                Limit::perDay(config('auth.customer_limits.cart_guest_ip_day', 2000))->by('cart-ip-day:'.$networkIp($request)),
            ];
        });
        // تخمين أكواد الكوبونات (F-24): حد ضيق خاص لكل IP وأيضًا لكل
        // توكن/جلسة سلة، بدل الاعتماد على حد السلة العام فقط
        RateLimiter::for('coupon', function (Request $request) use ($networkIp): array {
            $identity = $request->bearerToken() ?? $request->header('X-Cart-Session') ?? 'guest';

            return [
                Limit::perMinute(10)->by('coupon-ip:'.$networkIp($request)),
                Limit::perMinute(10)->by('coupon-id:'.hash('sha256', (string) $identity)),
            ];
        });
        // إتمام الشراء: بالـ IP وأيضًا بالهاتف المُوحَّد (F5) — الزائر بلا
        // تحقق من الهاتف لا يراكم طلبات وهمية يحبس بها مخزونًا برقم واحد
        // H4: logged-in customers are keyed by customer_id; the daily network
        // quota only applies to an IPv6 /64 (one IPv4 may hide a whole CGNAT
        // cell, so it must not carry a daily budget). H5: no 'guest' phone
        // bucket — requests without a phone get no phone key at all, and the
        // daily phone counter lives in OrderService after successful
        // validation instead of the middleware.
        RateLimiter::for('checkout', function (Request $request) use ($networkIp): array {
            $customer = auth('sanctum')->user();
            $customer = $customer instanceof Customer ? $customer : null;
            $phone = $request->input('recipient_phone');
            $normalized = is_string($phone) && trim($phone) !== ''
                ? Customer::normalizePhone($phone) : null;

            $limits = [];

            if ($customer !== null) {
                $limits[] = Limit::perMinute(30)->by('checkout-customer:'.$customer->id);
                $limits[] = Limit::perDay(100)->by('checkout-customer-day:'.$customer->id);
            } else {
                // F2: حد الزائر مخفّض وقابل للضبط، مع سقف يومي لكل IP
                // (IPv4 مشمول — لا /64 وحده) وسقف يومي لكل هاتف.
                $limits[] = Limit::perMinute(config('auth.customer_limits.checkout_guest_ip_minute', 30))->by('checkout-ip:'.$networkIp($request));
                $limits[] = Limit::perDay(config('auth.customer_limits.checkout_guest_ip_day', 200))->by('checkout-ip-day:'.$networkIp($request));
                $packedIp = inet_pton((string) $request->ip());
                if ($packedIp !== false && str_starts_with($networkIp($request), 'net64:')) {
                    $networkKey = hash('sha256', substr($packedIp, 0, 8));
                    $limits[] = Limit::perDay((int) config('inventory.checkout_daily_network_limit', 100))->by('checkout-network-day:'.$networkKey);
                }
            }

            if ($customer === null && $normalized !== null && preg_match('/\d/', $normalized) === 1) {
                $phoneKey = hash('sha256', $normalized);
                $limits[] = Limit::perMinute(5)->by('checkout-phone:'.$phoneKey);
            }

            return $limits;
        });
        RateLimiter::for('order-tracking', fn (Request $request) => [
            Limit::perMinute(10)->by('order-tracking-ip:'.$networkIp($request)),
        ]);
        RateLimiter::for('webhooks', fn (Request $request) => [
            Limit::perMinute(120)->by('webhooks-ip:'.$networkIp($request)),
        ]);

        RateLimiter::for('supplier-login', function (Request $request) use ($networkIp): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email)
                ? Str::transliterate(Str::lower(trim($email)))
                : '';
            $emailKey = hash('sha256', $normalizedEmail);

            return [
                Limit::perMinute(5)->by('supplier-login-email:'.$emailKey),
                Limit::perHour(30)->by('supplier-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('supplier-login-ip:'.$networkIp($request)),
            ];
        });

        RateLimiter::for('admin-login', function (Request $request) use ($networkIp): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email)
                ? Str::transliterate(Str::lower(trim($email)))
                : '';
            $emailKey = hash('sha256', $normalizedEmail);

            return [
                Limit::perMinute(5)->by('admin-login-email:'.$emailKey),
                Limit::perHour(30)->by('admin-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('admin-login-ip:'.$networkIp($request)),
            ];
        });

        RateLimiter::for('customer-password-reset', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.reset_ip_minute', 20))->by('customer-password-reset:'.$networkIp($request)),
            Limit::perDay(config('auth.customer_limits.reset_ip_day', 500))->by('customer-password-reset-day:'.$networkIp($request)),
            Limit::perMinute(5)->by('customer-password-reset-id:'.$identifierKey($request)),
            Limit::perDay(10)->by('customer-password-reset-id-day:'.$identifierKey($request)),
        ]);

        // حد تسجيل دخول الكاشير على نقطة البيع
        RateLimiter::for('pos-login', function (Request $request) use ($networkIp): array {
            $email = $request->input('email');
            $emailKey = hash('sha256', Str::lower(trim(is_string($email) ? $email : '')));

            return [
                Limit::perMinute(5)->by('pos-login-email:'.$emailKey),
                Limit::perHour(30)->by('pos-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('pos-login-ip:'.$networkIp($request)),
            ];
        });

        // حد الدخول الموحد: بالـ IP وبالمعرّف (بريد/هاتف) معًا
        RateLimiter::for('unified-login', function (Request $request) use ($networkIp) {
            // H2: طبّع المعرّف بنفس منطق CustomerAuthService::login، وإلا كانت
            // كل صيغة هاتف (‎+20/0020/مسافات/أرقام عربية) بعدّاد مستقل.
            // M3: المدخل قد يكون array فيصل قبل الـ validation — لا تصبه لـ string.
            $raw = $request->input('login');
            $normalized = ! is_string($raw) || trim($raw) === ''
                ? 'invalid'
                : (str_contains($raw, '@')
                    ? Str::lower(trim($raw))
                    : Customer::normalizePhone($raw));
            $id = hash('sha256', $normalized);

            return [
                // M6: حد الـ IP قابل للضبط (شبكات CGNAT والفروع المشتركة)، وحد
                // إبقاء حد ضيق لكل (IP + معرّف) ضد التخمين المركّز.
                // الحدود العامة تحسب الفشل فقط داخل UnifiedAuthService.
                Limit::perMinute((int) config('auth.unified_login_ip_minute', 120))->by('ip:'.$networkIp($request)),
                Limit::perMinute(5)->by('id:'.$id.':'.$networkIp($request)),
            ];
        });

        RateLimiter::for('unified-handoff', fn (Request $request) => Limit::perMinute((int) config('auth.unified_handoff_ip_minute', 60))->by($networkIp($request)));
        RateLimiter::for('ai-chat', fn (Request $request) => Limit::perMinute(20)->by('ai-chat:'.($request->user('staff')?->id ?? $networkIp($request))));

        // M8: الـ handoff يعتمد على cache مشترك يدعم الأقفال عبر الخوادم.
        // file/array يكسرانه بصمت على عدة nodes — نبّه في الإنتاج مبكرًا.
        if ($this->app->environment('production')
            && ! in_array((string) config('cache.default'), ['redis', 'database', 'memcached', 'dynamodb'], true)) {
            throw new \LogicException('Staff handoff requires a shared lockable cache store in production. Use Redis or a shared database cache.');
        }
    }
}
