<?php

namespace App\Providers;

use App\Enums\StaffRole;
use App\Listeners\Wholesale\WholesaleLifecycleSubscriber;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionReceipt;
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
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\PricingService;
use App\Services\Shared\SettingService;
use App\Services\Catalog\StoreCatalogService;
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
        if (! $this->app->environment('production')) {
            return;
        }

        $proxies = config('app.trusted_proxies');
        if (! is_string($proxies) || trim($proxies) === '' || preg_match('/(?:^|,)\s*\*{1,2}\s*(?:,|$)/', $proxies)) {
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
        Relation::morphMap([
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
        // Key coupon rate limiting on network IP and cart session to prevent brute-force discount code guessing.
        RateLimiter::for('coupon', function (Request $request) use ($networkIp): array {
            $identity = $request->bearerToken() ?? $request->header('X-Cart-Session') ?? 'guest';

            return [
                Limit::perMinute(10)->by('coupon-ip:'.$networkIp($request)),
                Limit::perMinute(10)->by('coupon-id:'.hash('sha256', (string) $identity)),
            ];
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
            ];
        });

        RateLimiter::for('unified-handoff', fn (Request $request) => Limit::perMinute((int) config('auth.unified_handoff_ip_minute', 60))->by($networkIp($request)));
        RateLimiter::for('ai-chat', fn (Request $request) => Limit::perMinute(20)->by('ai-chat:'.($request->user('staff')?->id ?? $networkIp($request))));
    }
}
