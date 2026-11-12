<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** H1/H7/M6: production guards fail the boot when proxy, log, limiter, mailer or TLS settings are unsafe. */
class ProductionGuardsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('K_SERVICE');
        try {
            DB::statement('RESET ROLE');
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_cloud_run_refuses_untrusted_proxy_none(): void
    {
        // H1: Cloud Run always fronts the app (K_SERVICE is set); 'none'
        // would resolve every client to the proxy IP and globalize all limits.
        $this->forceProduction(['app.trusted_proxies' => 'none']);
        putenv('K_SERVICE=alfarid-api');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/TRUSTED_PROXIES/');
        $this->runGuards();
    }

    public function test_direct_access_may_still_use_proxy_none(): void
    {
        $this->forceProduction(['app.trusted_proxies' => 'none']);

        $this->runGuards();
        $this->assertTrue(true);
    }

    public function test_cloud_run_refuses_file_log_channels(): void
    {
        // H7: the container filesystem is ephemeral memory; file logs never
        // reach Cloud Logging and grow until OOM.
        $this->forceProduction([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
        ]);
        putenv('K_SERVICE=alfarid-api');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/stderr/');
        $this->runGuards();
    }

    public function test_shared_atomic_limiter_is_required(): void
    {
        // M6: array disables every limit; file is per-instance on Cloud Run.
        foreach (['array', 'file'] as $store) {
            $this->forceProduction(['cache.limiter' => $store]);
            try {
                $this->runGuards();
                $this->fail("CACHE_LIMITER={$store} must fail production guards.");
            } catch (\LogicException $e) {
                $this->assertMatchesRegularExpression('/CACHE_LIMITER/', $e->getMessage());
            }
        }
        $this->assertTrue(true);
    }

    public function test_failover_and_array_mailers_are_rejected(): void
    {
        // M6: failover silently falls back to the log mailer (which the guard
        // already bans); array drops password resets and OTPs on the floor.
        foreach (['failover', 'array'] as $mailer) {
            $this->forceProduction(['mail.default' => $mailer]);
            try {
                $this->runGuards();
                $this->fail("MAIL_MAILER={$mailer} must fail production guards.");
            } catch (\LogicException $e) {
                $this->assertMatchesRegularExpression('/MAIL_MAILER/', $e->getMessage());
            }
        }
        $this->assertTrue(true);
    }

    public function test_sslmode_inside_db_url_cannot_bypass_the_check(): void
    {
        // M6: pgsql connections merge DB_URL query parameters, so an embedded
        // sslmode=require would skip verification of the server identity.
        $this->forceProduction([
            'database.connections.pgsql.url' => 'pgsql://runtime:secret@127.0.0.1:5432/alfarid?sslmode=require',
            'database.connections.pgsql.sslmode' => 'verify-full',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/DB_SSLMODE/');
        $this->runGuards();
    }

    public function test_valid_production_config_passes(): void
    {
        $this->forceProduction();
        putenv('K_SERVICE=alfarid-api');

        $this->runGuards();
        $this->assertTrue(true);
    }

    public function test_owner_login_is_refused(): void
    {
        // F-02: an owner login voids every REVOKE the migrations granted.
        $this->forceProduction([], true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/owns or can assume ownership of .* tables/');
        $this->runGuards();
    }

    public function test_superuser_default_credentials_are_refused(): void
    {
        // F-24: postgres/empty-password must never reach production.
        $this->forceProduction([
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.password' => '',
            'database.connections.pgsql.url' => null,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/DB_USERNAME\/DB_PASSWORD/');
        $this->runGuards();
    }

    /**
     * @param array<string, mixed> $overrides
     * @param bool $asOwner Leave the suite's owner connection as-is (F-02 case).
     */
    private function forceProduction(array $overrides = [], bool $asOwner = false): void
    {
        // Open the database handle before tightening sslmode: the guards under
        // test only run SELECTs on it, while building a NEW handle with
        // verify-full against the local non-SSL server would fail to connect.
        DB::selectOne('SELECT 1');
        if (! $asOwner) {
            // The suite connects as the table owner; assume the runtime login
            // so the ownership check observes a non-owner, like production.
            DB::statement("SET ROLE 'alfarid_app'");
        }
        app()->instance('env', 'production');
        config()->set(array_merge([
            'app.trusted_proxies' => '10.0.0.5',
            'cache.default' => 'redis',
            'cache.limiter' => 'redis',
            'mail.default' => 'smtp',
            'database.default' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'database.connections.pgsql.url' => null,
            'logging.default' => 'stderr',
        ], $overrides));
    }

    private function runGuards(): void
    {
        $method = new ReflectionMethod(AppServiceProvider::class, 'enforceProductionGuards');
        $method->setAccessible(true);
        $method->invoke(new AppServiceProvider(app()));
    }
}
