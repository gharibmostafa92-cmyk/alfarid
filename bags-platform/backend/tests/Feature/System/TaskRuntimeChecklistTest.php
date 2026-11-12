<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Exceptions\BusinessException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ThrottleApiBeforeAuthentication;
use App\Notifications\Channels\CustomerSmsChannel;
use App\Providers\AppServiceProvider;
use App\Services\Finance\StatementBudget;
use App\Services\Media\MediaUrlGenerator;
use App\Services\Media\UuidPathGenerator;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\QueryException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class TaskRuntimeChecklistTest extends TestCase
{
    public function test_api_limit_runs_before_sanctum_reads_the_token(): void
    {
        $router = app(Router::class);
        $route = $router->getRoutes()->getByName('unified-auth.me');
        $middleware = $router->gatherRouteMiddleware($route);

        $throttle = array_search(ThrottleApiBeforeAuthentication::class, $middleware, true);
        $auth = array_search(Authenticate::class.':sanctum', $middleware, true);
        $this->assertIsInt($throttle);
        $this->assertIsInt($auth);
        $this->assertLessThan($auth, $throttle);
    }

    #[DataProvider('legacyLoginLimiters')]
    public function test_legacy_login_limit_for_one_network_does_not_lock_another(string $name): void
    {
        $limiter = RateLimiter::limiter($name);
        $credentials = ['login' => 'employee@example.test', 'email' => 'employee@example.test'];
        $first = Request::create('/', 'POST', $credentials, [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $second = Request::create('/', 'POST', $credentials, [], [], ['REMOTE_ADDR' => '198.51.100.1']);

        $keys = fn (Request $request): array => array_map(fn ($limit) => $limit->key, $limiter($request));
        $this->assertSame([], array_intersect($keys($first), $keys($second)));
    }

    public static function legacyLoginLimiters(): array
    {
        return [['customer-login'], ['supplier-login'], ['admin-login'], ['pos-login']];
    }

    #[DataProvider('mediaTtls')]
    public function test_private_media_urls_are_always_signed_with_bounded_lifetimes(int $configured, int $expected): void
    {
        $this->freezeTime();
        config(['filesystems.disks.r2.download_url_ttl_seconds' => $configured, 'filesystems.disks.r2.url' => 'https://public.example.test']);
        $expiration = null;
        Storage::disk('r2')->buildTemporaryUrlsUsing(function ($path, $expires) use (&$expiration) {
            $expiration = $expires;

            return 'https://private.example.test/signed';
        });
        $media = new Media;
        $media->forceFill(['id' => 12, 'uuid' => (string) Str::uuid(), 'disk' => 'r2', 'file_name' => 'private.pdf']);
        $paths = new UuidPathGenerator;
        $this->assertSame('12/', $paths->getPath($media));
        $media->setCustomProperty('path_layout', 'uuid');
        $this->assertSame($media->uuid.'/', $paths->getPath($media));
        $generator = app(MediaUrlGenerator::class)->setMedia($media)->setPathGenerator($paths);
        $this->assertSame('https://private.example.test/signed', $generator->getUrl());
        $this->assertSame(now()->addSeconds($expected)->timestamp, $expiration->getTimestamp());
    }

    public static function mediaTtls(): array
    {
        return [[0, 60], [3600, 3600], [1000000000, 3600]];
    }

    #[DataProvider('databaseFailures')]
    public function test_database_error_responses_hide_sql(string $method, string $state, int $status): void
    {
        Route::match([$method], '/api/checklist/database-error', function () use ($state): never {
            $cause = new PDOException('private connection details');
            $cause->errorInfo = [$state];
            throw new QueryException('pgsql', 'SELECT secret_column FROM private_table', [], $cause);
        });
        $response = $this->json($method, '/api/checklist/database-error')->assertStatus($status)
            ->assertDontSee('secret_column')->assertDontSee('private connection details');
        if ($state === '23503' || ($method === 'POST' && ($state === '57014' || str_starts_with($state, '08')))) {
            $response->assertHeaderMissing('Retry-After');
        } elseif ($state === '57014') {
            $retryAfter = (int) $response->headers->get('Retry-After');
            $this->assertGreaterThanOrEqual(5, $retryAfter);
            $this->assertLessThanOrEqual(15, $retryAfter);
        } else {
            $response->assertHeader('Retry-After', '1');
        }
    }

    public static function databaseFailures(): array
    {
        $cases = [];
        foreach (['GET', 'POST'] as $method) {
            foreach (['57014', '08001', '08006', '55P03', '40P01', '40001', '23503'] as $state) {
                $status = $state === '23503' ? 409 : (in_array($state, ['55P03', '40P01', '40001'], true) && $method === 'POST' ? 409 : 503);
                $cases[$method.' '.$state] = [$method, $state, $status];
            }
        }

        return $cases;
    }

    public function test_real_statement_timeout_rolls_back_and_restores_the_session_budget(): void
    {
        $before = DB::selectOne("SELECT current_setting('statement_timeout') AS value")->value;
        try {
            StatementBudget::run(1, fn () => DB::select('SELECT pg_sleep(0.05)'));
            $this->fail('The real PostgreSQL query must time out.');
        } catch (QueryException $exception) {
            $this->assertSame('57014', $exception->errorInfo[0]);
        }
        $this->assertSame($before, DB::selectOne("SELECT current_setting('statement_timeout') AS value")->value);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('productionSettings')]
    public function test_production_boot_rejects_unsafe_settings(string $setting, mixed $value, string $message): void
    {
        $this->app['env'] = 'production';
        // Assume the runtime login (non-owner) like production; the suite
        // connects as the table owner.
        DB::statement("SET ROLE 'alfarid_app'");
        try {
            config(['app.trusted_proxies' => 'none', 'mail.default' => 'smtp', 'database.default' => 'pgsql', 'database.connections.pgsql.sslmode' => 'verify-full', 'cache.limiter' => 'redis', $setting => $value]);
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage($message);
            (new AppServiceProvider($this->app))->boot();
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public static function productionSettings(): array
    {
        return [
            ['app.trusted_proxies', '', 'TRUSTED_PROXIES'],
            ['app.trusted_proxies', '*', 'TRUSTED_PROXIES'],
            ['app.trusted_proxies', '172.31.0.10, **', 'TRUSTED_PROXIES'],
            ['mail.default', 'log', 'MAIL_MAILER'],
            ['database.connections.pgsql.sslmode', 'prefer', 'DB_SSLMODE'],
            ['database.connections.pgsql.sslmode', 'allow', 'DB_SSLMODE'],
            ['database.connections.pgsql.sslmode', 'disable', 'DB_SSLMODE'],
        ];
    }

    public function test_safe_production_settings_allow_direct_access(): void
    {
        $this->app['env'] = 'production';
        DB::statement("SET ROLE 'alfarid_app'");
        try {
            config(['app.trusted_proxies' => 'none', 'mail.default' => 'smtp', 'database.default' => 'pgsql', 'database.connections.pgsql.sslmode' => 'verify-full', 'cache.default' => 'database', 'cache.limiter' => 'redis']);
            (new AppServiceProvider($this->app))->boot();
        } finally {
            DB::statement('RESET ROLE');
        }
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        (new TrustProxies)->handle($request, function ($request) {
            $this->assertSame('192.0.2.1', $request->ip());

            return response('ok');
        });
    }

    #[DataProvider('requestIds')]
    public function test_request_ids_and_node_header(string $id, bool $valid, bool $expose): void
    {
        config(['app.expose_node_header' => $expose]);
        $request = Request::create('/');
        $request->headers->set('X-Request-ID', $id);
        $response = (new AssignRequestId)->handle($request, fn () => response('ok'));
        $actual = $response->headers->get('X-Request-ID');
        // L8: without a trusted proxy the server always mints the
        // correlation id; a well-formed client value is kept aside, never adopted.
        $this->assertTrue(Str::isUuid((string) $actual));
        if ($valid) {
            $this->assertNotSame($id, $actual);
        }
        $this->assertSame($expose, $response->headers->has('X-Served-By'));
    }

    public static function requestIds(): array
    {
        return [['1234567', false, false], [str_repeat('a', 129), false, true], ["valid-id\ninjected", false, false], ['valid:request-123', true, true], ['valid.request-456', true, false]];
    }

    #[DataProvider('smsFailures')]
    public function test_sms_provider_failures_are_generic_service_unavailable(string $url, string $token, string $failure): void
    {
        config(['services.customer_sms.url' => $url, 'services.customer_sms.token' => $token]);
        Http::preventStrayRequests();
        Http::fake(fn () => match ($failure) {
            'http' => Http::response(['error' => 'private-provider-detail'], 500),
            'connection' => Http::failedConnection('private-provider-detail'),
            default => Http::response(['success' => false]),
        });
        $customer = new class
        {
            public string $phone = '01012345678';

            public function routeNotificationFor(...$arguments): string
            {
                return $this->phone;
            }
        };
        $notification = new class extends Notification
        {
            public function toSms($customer): string
            {
                return 'verification 123456';
            }
        };
        try {
            app(CustomerSmsChannel::class)->send($customer, $notification);
            $this->fail('SMS failure must reach the caller.');
        } catch (BusinessException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertStringNotContainsString('private-provider-detail', $exception->getMessage());
            $this->assertStringNotContainsString('private-token', $exception->getMessage());
        }
        if ($url === 'http://sms.example.test' || $token === '') {
            Http::assertNothingSent();
        }
    }

    public static function smsFailures(): array
    {
        return [
            ['http://sms.example.test', 'private-token', 'config'],
            ['https://sms.example.test', '', 'config'],
            ['https://sms.example.test', 'private-token', 'http'],
            ['https://sms.example.test', 'private-token', 'rejected'],
            ['https://sms.example.test', 'private-token', 'connection'],
        ];
    }
}
