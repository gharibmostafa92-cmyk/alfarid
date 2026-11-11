<?php

namespace App\Providers;

use App\Enums\StaffRole;
use App\Listeners\Wholesale\WholesaleLifecycleSubscriber;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\SupplierUser;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Models\WholesalePaymentNotice;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Ai\Catalog\CatalogToolProvider;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceToolProvider;
use App\Services\Ai\Inventory\InventoryToolProvider;
use App\Services\Ai\Pricing\PricingToolProvider;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\ShippingToolProvider;
use App\Services\Ai\Storefront\StorefrontToolProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\WhatsApp\WhatsAppActorContext;
use App\Services\Ai\Wholesale\WholesaleToolProvider;
use App\Services\Auth\NetworkIdentity;
use App\Services\Catalog\StoreCatalogService;
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\PricingService;
use App\Services\Shared\SettingService;
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
        require_once __DIR__.'/../Services/ClassAliases.php';
        require_once __DIR__.'/../Notifications/ClassAliases.php';
        require_once __DIR__.'/../Events/ClassAliases.php';
        require_once __DIR__.'/../Http/Requests/ClassAliases.php';
        require_once __DIR__.'/../Http/Resources/ClassAliases.php';
        require_once __DIR__.'/../Enums/ClassAliases.php';
        require_once __DIR__.'/../Listeners/ClassAliases.php';

        // Scoped rather than singleton: isolates user-specific state per request, avoiding credential leaks across requests under Octane.
        $this->app->scoped(BranchAccess::class);
        $this->app->scoped(SettingService::class);
        $this->app->scoped(StoreCatalogService::class);
        $this->app->scoped(PricingService::class);
        $this->app->scoped(CatalogRetailPriceObserver::class);
        $this->app->scoped(ProposalBuilder::class);
        $this->app->scoped(WhatsAppActorContext::class);
        $this->app->tag([
            CatalogToolProvider::class,
            InventoryToolProvider::class,
            PricingToolProvider::class,
            FinanceToolProvider::class,
            WholesaleToolProvider::class,
            ShippingToolProvider::class,
            StorefrontToolProvider::class,
        ], 'ai.tool_providers');
        $this->app->scoped(ToolRegistry::class, function ($app) {
            return new ToolRegistry(
                $app->make(EntityResolver::class),
                $app->make(BranchAccess::class),
                $app->make(SettingService::class),
                $app->tagged('ai.tool_providers'),
                $app->make(ProposalBuilder::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(WholesaleLifecycleSubscriber::class);
        $this->configureModelObservers();
        $this->configureLifecycleEvents();
        $this->configureSecurityAndGates();
        $this->enforceProductionGuards();
        $this->configureMorphMaps();
        $this->configureRateLimiters();
    }

    private function configureModelObservers(): void
    {
        foreach ([ProductVariant::class, PriceListItem::class, PriceList::class,
            StockLevel::class, Branch::class, Location::class] as $model) {
            $model::observe(CatalogRetailPriceObserver::class);
        }
    }

    private function configureLifecycleEvents(): void
    {
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
        Event::listen(JobProcessing::class, function (): void {
            app(PermissionRegistrar::class)->clearPermissionsCollection();
            // Clear memoized settings and document numbering sequences between queue jobs to prevent state pollution in long-running workers.
            app(SettingService::class)->forgetMemoryState();
        });
        Event::listen(QueueBusy::class,
            fn ($event) => logger()->error('Queue backlog exceeded its configured threshold.', ['connection' => $event->connectionName, 'queue' => $event->queue, 'size' => $event->size]));
    }

    /**
     * Whether a TRUSTED_PROXIES value is safe for production: explicit IPs/CIDRs
     * or 'none'. Wildcards and /0 CIDRs (0.0.0.0/0, ::/0) trust every address,
     * letting any client forge X-Forwarded-For past every IP-based limit.
     */
    public static function trustedProxyConfigIsSafe(mixed $proxies): bool
    {
        if (! is_string($proxies)) {
            return false;
        }
        $value = trim($proxies);
        if ($value === '') {
            return false;
        }
        if ($value === 'none') {
            return true;
        }
        if (preg_match('/(?:^|,)\s*\*{1,2}\s*(?:,|$)/', $value)) {
            return false;
        }
        foreach (explode(',', $value) as $entry) {
            if (! self::trustedProxyEntryIsSafe(trim($entry))) {
                return false;
            }
        }

        return true;
    }

    /** Accept only a literal IP or a narrow CIDR; hostnames and special tokens are ambiguous. */
    private static function trustedProxyEntryIsSafe(string $entry): bool
    {
        if ($entry === '' || strcasecmp($entry, 'REMOTE_ADDR') === 0) {
            return false;
        }
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $address = trim((string) $address);
        $isV4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = ! $isV4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $isV4 && ! $isV6) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }
        $prefix = trim((string) $prefix);
        if ($prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }
        $prefix = (int) $prefix;
        $max = $isV4 ? 32 : 128;

        // M1: a /0 (including "00") trusts every peer; equally broad /1-/7
        // ranges are an operational mistake, not a proxy definition.
        return $prefix >= ($isV4 ? 8 : 16) && $prefix <= $max;
    }

    private function configureSecurityAndGates(): void
    {
        Gate::before(fn ($user) => $user instanceof User
            && $user->is_active && ! $user->is_system
            && $user->hasRole(StaffRole::SuperAdmin->value, 'staff') ? true : null);

        $proxies = config('app.trusted_proxies');
        TrustProxies::at(is_string($proxies) && trim($proxies) !== '' && $proxies !== 'none'
            ? array_map('trim', explode(',', $proxies)) : []);
    }

    private function enforceProductionGuards(): void
    {
        // M2: staging/demo often carries real data; only local and testing may
        // run with weak proxy, mailer, cache, and database settings.
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        $proxies = config('app.trusted_proxies');
        if (! self::trustedProxyConfigIsSafe($proxies)) {
            throw new \LogicException('Set TRUSTED_PROXIES to explicit proxy IPs/CIDRs, or none for direct access. Wildcards are not allowed in production.');
        }
        if (in_array(config('cache.default'), ['file', 'array'], true)) {
            logger()->critical('AI agent locks require a shared atomic cache store (redis/database).');
        }
        // Disallow log mailer and plaintext database connections in production to prevent credential leakage.
        if (config('mail.default') === 'log') {
            throw new \LogicException('Set MAIL_MAILER to a real mailer in production. The log mailer writes password-reset links and OTP codes to the log file.');
        }
        $sslmode = config('database.connections.pgsql.sslmode');
        if (config('database.default') === 'pgsql' && in_array($sslmode, ['prefer', 'allow', 'disable'], true)) {
            throw new \LogicException("Set DB_SSLMODE=require (or stricter) in production. '{$sslmode}' allows a plaintext database connection.");
        }
        // Require shared lockable cache store across workers in production for multi-node staff handoff synchronization.
        if (! in_array((string) config('cache.default'), ['redis', 'database', 'memcached', 'dynamodb'], true)) {
            throw new \LogicException('Staff handoff requires a shared lockable cache store in production. Use Redis or a shared database cache.');
        }
    }

    private function configureMorphMaps(): void
    {
        // Morph map aliases decouple ledger reference records (e.g. stock_movements) from PHP class namespaces.
        Relation::enforceMorphMap([
            'production_receipt' => ProductionReceipt::class,
            'stock_transfer' => StockTransfer::class,
            'stocktake' => Stocktake::class,
            'order' => Order::class,
            'return_request' => ReturnRequest::class,
            'purchase_order' => PurchaseOrder::class,
            'pos_shift' => PosShift::class,
            'expense' => Expense::class,
            'treasury_transfer' => TreasuryTransfer::class,
            'wholesale_payment_notice' => WholesalePaymentNotice::class,
            // Keep the historical identity used by Spatie roles, media and idempotency records.
            User::class => User::class,
            Customer::class => Customer::class,
            SupplierUser::class => SupplierUser::class,
            Product::class => Product::class,
            ProductVariant::class => ProductVariant::class,
            Brand::class => Brand::class,
            Category::class => Category::class,
            WalletAdjustment::class => WalletAdjustment::class,
        ]);
    }

    private function configureRateLimiters(): void
    {
        // Collapse IPv6 addresses to their /64 subnet to prevent rotating through transient host addresses to bypass rate limits.
        $networkIp = NetworkIdentity::forRequest(...);
        RateLimiter::for('customer-registration', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.registration_ip_minute', 20))->by('customer-register-minute:'.$networkIp($request)),
            Limit::perDay(config('auth.customer_limits.registration_ip_day', 500))->by('customer-register-day:'.$networkIp($request)),
        ]);
        RateLimiter::for('store-branding', fn (Request $request) => Limit::perMinute(1200)->by('store-branding:'.$networkIp($request)));
        // Expensive catalog filters and price sorting use tighter limits, avoiding global shared buckets that cause storewide outages.
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
        $resetIdentifiers = function (Request $request): array {
            $email = $request->input('email');
            $phone = $request->input('phone');
            $identifiers = [];
            if (is_string($email) && trim($email) !== '') {
                $identifiers[] = 'email:'.mb_strtolower(trim($email));
            }
            if (is_string($phone) && trim($phone) !== '') {
                $identifiers[] = 'phone:'.Customer::normalizePhone($phone);
            }

            return array_map(fn (string $identifier): string => hash('sha256', $identifier), $identifiers);
        };
        RateLimiter::for('customer-reset-confirm', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.reset_confirm_ip_minute', 120))->by('reset-confirm-ip:'.$networkIp($request)),
            ...array_map(fn (string $key): Limit => Limit::perMinute(10)->by('reset-confirm-id:'.$key), $resetIdentifiers($request)),
        ]);
        $phoneKey = fn (Request $request): string => hash('sha256', is_string($request->input('phone')) ? Customer::normalizePhone($request->input('phone')) : 'invalid');
        RateLimiter::for('customer-phone-send', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.phone_ip_minute', 20))->by('phone-send-ip:'.$networkIp($request)),
            Limit::perDay(config('auth.customer_limits.phone_ip_day', 500))->by('phone-send-day:'.$networkIp($request)),
            Limit::perMinute(1)->by('phone-send-id:'.$phoneKey($request)),
            // Daily limit per phone number mitigates SMS toll fraud and recipient harassment.
            Limit::perDay(config('auth.customer_limits.phone_id_day', 5))->by('phone-send-id-day:'.$phoneKey($request)),
        ]);
        RateLimiter::for('customer-phone-verify', fn (Request $request) => [
            Limit::perMinute(config('auth.customer_limits.phone_verify_ip_minute', 120))->by('phone-verify-ip:'.$networkIp($request)),
            Limit::perMinute(5)->by('phone-verify-id:'.$phoneKey($request)),
            // Daily verification attempt ceiling adds an additional defense layer on top of per-code invalidation thresholds.
            Limit::perDay(config('auth.customer_limits.phone_verify_id_day', 20))->by('phone-verify-id-day:'.$phoneKey($request)),
        ]);

        // Customer authentication rate limiters (retail & wholesale): throttled jointly by normalized identifier and client IP.
        RateLimiter::for('customer-login', function (Request $request) use ($networkIp): array {
            $identifier = $request->input('login');
            $idKey = is_string($identifier) && trim($identifier) !== ''
                ? hash('sha256', (string) Customer::normalizeIdentifier($identifier))
                : 'none';

            return [
                Limit::perMinute(5)->by('customer-login-id:'.$idKey),
                Limit::perHour(config('auth.customer_limits.login_id_hour', 30))->by('customer-login-id-hour:'.$idKey),
                Limit::perDay(config('auth.customer_limits.login_id_day', 100))->by('customer-login-id-day:'.$idKey),
                Limit::perMinute(config('auth.customer_limits.login_ip_minute', 120))->by('customer-login-ip:'.$networkIp($request)),
            ];
        });

        // Key authenticated customers by customer_id while applying network ceilings for unauthenticated carts.
        RateLimiter::for('cart', function (Request $request) use ($networkIp) {
            $customer = auth('sanctum')->user();
            if ($customer instanceof Customer) {
                return [Limit::perMinute(120)->by('cart-customer:'.$customer->id)];
            }

            // Guest cart limits prevent rapid generation of ephemeral cart sessions and database row bloat.
            return [
                Limit::perMinute(config('auth.customer_limits.cart_guest_ip_minute', 120))->by('cart-ip:'.$networkIp($request)),
                Limit::perDay(config('auth.customer_limits.cart_guest_ip_day', 2000))->by('cart-ip-day:'.$networkIp($request)),
            ];
        });
        RateLimiter::for('coupon', function (Request $request) use ($networkIp): array {
            $customer = auth('sanctum')->user();
            if ($customer instanceof Customer) {
                return [Limit::perMinute(10)->by('coupon-customer:'.$customer->id)];
            }

            return [Limit::perMinute(config('auth.customer_limits.coupon_guest_ip_minute', 30))->by('coupon-ip:'.$networkIp($request))];
        });
        // Authenticated checkout is keyed by customer ID; guest checkout limits apply daily network ceilings and per-phone limits.
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
                // Guest checkout limits enforce daily quotas per IP and per destination phone number.
                $limits[] = Limit::perMinute(config('auth.customer_limits.checkout_guest_ip_minute', 30))->by('checkout-ip:'.$networkIp($request));
                $limits[] = Limit::perDay(config('auth.customer_limits.checkout_guest_ip_day', 200))->by('checkout-ip-day:'.$networkIp($request));
            }

            if ($customer === null && $normalized !== null && preg_match('/\d/', $normalized) === 1) {
                $phoneKey = hash('sha256', $normalized);
                $limits[] = Limit::perMinute(5)->by('checkout-phone:'.$phoneKey);
                $limits[] = Limit::perDay(config('auth.customer_limits.checkout_phone_day', 20))->by('checkout-phone-day:'.$phoneKey);
            }

            return $limits;
        });
        RateLimiter::for('order-tracking', fn (Request $request) => [
            Limit::perMinute(10)->by('order-tracking-ip:'.$networkIp($request)),
        ]);
        // M14: the readiness probe hits PostgreSQL (and optionally Redis) —
        // cap it generously so floods cannot convert health checks into a DB DoS
        // while load-balancer probes (a few per minute) never notice.
        RateLimiter::for('health-ready', fn (Request $request) => [
            Limit::perMinute(120)->by('health-ready:'.$networkIp($request)),
        ]);
        RateLimiter::for('webhooks', fn (Request $request) => [
            Limit::perMinute(120)->by('webhooks-ip:'.$networkIp($request)),
        ]);
        // H4: isolated buckets per webhook purpose so a WhatsApp peak cannot
        // starve payment notifications (which would expire orders via
        // orders:expire-pending while the provider keeps retrying).
        // H3: an attacker-supplied gateway slug must not mint unbounded buckets.
        // Unknown slugs collapse into one bucket and every request also counts
        // against a per-IP outer ceiling.
        RateLimiter::for('webhooks-payment', function (Request $request) use ($networkIp): array {
            $gateway = strtolower((string) $request->route('gateway'));
            $bucket = array_key_exists($gateway, (array) config('webhooks.payment', [])) ? $gateway : 'unknown';

            return [
                Limit::perMinute((int) config('webhooks.rate_limits.payment_ip_per_minute', 240))
                    ->by('webhooks-payment-ip:'.$networkIp($request)),
                Limit::perMinute((int) config('webhooks.rate_limits.payment_per_minute', 120))
                    ->by('webhooks-payment:'.$bucket.':'.$networkIp($request)),
            ];
        });
        RateLimiter::for('webhooks-shipping', function (Request $request) use ($networkIp): array {
            return [
                Limit::perMinute((int) config('webhooks.rate_limits.shipping_ip_per_minute', 240))
                    ->by('webhooks-shipping-ip:'.$networkIp($request)),
                Limit::perMinute((int) config('webhooks.rate_limits.shipping_per_minute', 120))
                    ->by('webhooks-shipping:'.strtolower((string) $request->route('company')).':'.$networkIp($request)),
            ];
        });
        // H4: the merchant gateway and Meta webhooks no longer share a bucket,
        // and the trusted gateway gets a larger (still bounded) allowance.
        RateLimiter::for('webhooks-whatsapp', function (Request $request) use ($networkIp): array {
            $inbound = $request->route()?->getName() === 'webhooks.whatsapp.inbound';
            $internal = $inbound && $this->internalWhatsAppGateway($request);
            $limit = match (true) {
                $internal => (int) config('webhooks.rate_limits.whatsapp_internal_per_minute', 600),
                $inbound => (int) config('webhooks.rate_limits.whatsapp_inbound_per_minute', 120),
                default => (int) config('webhooks.rate_limits.whatsapp_customers_per_minute', 120),
            };

            return [Limit::perMinute($limit)->by('webhooks-whatsapp:'.($inbound ? 'inbound' : 'customers').':'.$networkIp($request))];
        });

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
            ...array_map(fn (string $key): Limit => Limit::perMinute(5)->by('customer-password-reset-id:'.$key), $resetIdentifiers($request)),
        ]);

        // Cashier POS terminal login rate limits by staff email and client IP.
        RateLimiter::for('pos-login', function (Request $request) use ($networkIp): array {
            $email = $request->input('email');
            $emailKey = hash('sha256', Str::lower(trim(is_string($email) ? $email : '')));

            return [
                Limit::perMinute(5)->by('pos-login-email:'.$emailKey),
                Limit::perHour(30)->by('pos-login-email-hour:'.$emailKey),
                Limit::perMinute(120)->by('pos-login-ip:'.$networkIp($request)),
            ];
        });

        RateLimiter::for('unified-login', function (Request $request) use ($networkIp) {
            // Canonicalize login identifier using Customer::normalizeIdentifier to prevent circumventing limits via alternate phone formats.
            // Guard against non-string payloads that arrive prior to controller validation.
            $raw = $request->input('login');
            $normalized = is_string($raw) && trim($raw) !== ''
                ? Customer::normalizeIdentifier($raw)
                : null;
            $id = hash('sha256', $normalized ?? 'invalid');

            return [
                // Combined IP and identifier rate limiting protects against targeted credential stuffing across shared network gateways.
                Limit::perMinute((int) config('auth.unified_login_ip_minute', 120))->by('ip:'.$networkIp($request)),
                Limit::perMinute(5)->by('id:'.$id.':'.$networkIp($request)),
                // H5: per-account ceilings live in UnifiedAuthService and count
                // failures only; counting every attempt here let an attacker lock
                // the real owner out of every IP with a handful of successes.
            ];
        });

        RateLimiter::for('unified-handoff', fn (Request $request) => Limit::perMinute((int) config('auth.unified_handoff_ip_minute', 60))->by($networkIp($request)));

        // H1: named limiters replace inline numeric throttles so each surface owns
        // its bucket, keyed by authenticated identity (never shared across routes or user types).
        $staffKey = fn (Request $request): string => ($actor = $request->user()) instanceof User
            ? 'staff:'.$actor->id : $networkIp($request);
        RateLimiter::for('admin-reports', fn (Request $request) => Limit::perMinute(30)->by('admin-reports:'.$staffKey($request)));
        RateLimiter::for('admin-media-uploads', fn (Request $request) => Limit::perMinute(30)->by('admin-media-uploads:'.$staffKey($request)));
        RateLimiter::for('admin-banners', fn (Request $request) => Limit::perMinute(30)->by('admin-banners:'.$staffKey($request)));
        RateLimiter::for('ai-conversations', fn (Request $request) => Limit::perMinute(30)->by('ai-conversations:'.$staffKey($request)));
        RateLimiter::for('ai-attachments', fn (Request $request) => Limit::perMinute(30)->by('ai-attachments:'.$staffKey($request)));
        RateLimiter::for('ai-mentions', fn (Request $request) => Limit::perMinute(60)->by('ai-mentions:'.$staffKey($request)));
        RateLimiter::for('supplier-portal', fn (Request $request) => Limit::perMinute(120)
            ->by(($actor = $request->user()) instanceof SupplierUser ? 'supplier:'.$actor->id : $networkIp($request)));
        RateLimiter::for('store-returns', function (Request $request) use ($networkIp) {
            $customer = auth('sanctum')->user();
            $identity = $customer instanceof Customer ? 'customer:'.$customer->id : $networkIp($request);

            return Limit::perHour(10)->by('store-returns:'.$identity);
        });
        // M6: CPU-heavy endpoints (PDF/Excel generation, spreadsheet
        // parsing, document uploads) get tight dedicated buckets so one actor
        // cannot saturate every PHP-FPM worker with repeated heavy requests.
        RateLimiter::for('wholesale-heavy', function (Request $request) use ($networkIp) {
            $customer = auth('sanctum')->user();
            $identity = $customer instanceof Customer ? 'customer:'.$customer->id : $networkIp($request);

            return Limit::perMinute(10)->by('wholesale-heavy:'.$identity.':'.($request->route()?->getName() ?? 'unknown'));
        });
        RateLimiter::for('admin-heavy', fn (Request $request) => Limit::perMinute(10)->by('admin-heavy:'.$staffKey($request).':'.($request->route()?->getName() ?? 'unknown')));
        RateLimiter::for('ai-chat', fn (Request $request) => Limit::perMinute(20)->by($staffKey($request)));
        RateLimiter::for('pos-customers', fn (Request $request) => Limit::perMinute(30)->by($staffKey($request)));
    }

    /** The merchant's own WhatsApp gateway authenticates with the shared secret header. */
    private function internalWhatsAppGateway(Request $request): bool
    {
        $expected = config('services.whatsapp_gateway.secret');
        $provided = $request->header('X-WA-Gateway-Secret');

        return is_string($expected) && $expected !== ''
            && is_string($provided) && hash_equals($expected, $provided);
    }
}
