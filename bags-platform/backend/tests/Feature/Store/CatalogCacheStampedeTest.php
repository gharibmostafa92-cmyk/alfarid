<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Api\V1\Store\Catalog\CatalogController;
use App\Http\Controllers\Api\V1\Store\Catalog\HomeController;
use App\Models\Banner;
use App\Services\Catalog\BannerLinkResolver;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class CatalogCacheStampedeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cold_catalog_reference_lock_waits_at_most_two_seconds_and_lives_fifteen(): void
    {
        $controller = app(CatalogController::class);
        config(['cache.default' => 'redis']);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(2, Mockery::type('callable'))->andReturnUsing(
            fn (int $wait, callable $callback) => $callback()
        );

        Cache::shouldReceive('get')->once()->with('store:reference:test')->andReturn(null);
        Cache::shouldReceive('lock')->once()->with('store:reference:test:build', 15)->andReturn($lock);
        Cache::shouldReceive('flexible')->once()->withArgs(function (string $key, array $ttl, mixed $callback, array $lockOptions): bool {
            return $key === 'store:reference:test'
                && $ttl === [300, 900]
                && ($lockOptions['seconds'] ?? 999) <= 15;
        })->andReturn(['cached' => true]);

        $method = new ReflectionMethod(CatalogController::class, 'cachedReference');
        $result = $method->invoke($controller, 'store:reference:test', fn (): array => ['from-db' => true]);

        $this->assertSame(['cached' => true], $result);
    }

    public function test_catalog_reference_lock_timeout_stays_503(): void
    {
        $controller = app(CatalogController::class);
        config(['cache.default' => 'redis']);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(2, Mockery::type('callable'))->andThrow(new LockTimeoutException('timeout'));
        Cache::shouldReceive('get')->once()->with('store:reference:timeout')->andReturn(null);
        Cache::shouldReceive('lock')->once()->with('store:reference:timeout:build', 15)->andReturn($lock);

        $method = new ReflectionMethod(CatalogController::class, 'cachedReference');

        try {
            $method->invoke($controller, 'store:reference:timeout', fn (): array => ['from-db' => true]);
            $this->fail('Expected a 503 when the reference lock times out.');
        } catch (BusinessException $exception) {
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_home_lock_timeout_does_not_rebuild_sections_for_every_waiter(): void
    {
        config(['cache.default' => 'redis']);
        $catalog = Mockery::mock(StoreCatalogService::class);
        $catalog->shouldNotReceive('homeSectionIds');
        $controller = new HomeController($catalog, app(BannerLinkResolver::class));

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException('timeout'));
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('lock')->once()->with(StoreCatalogService::HOME_CACHE_KEY.':build', 15)->andReturn($lock);
        Cache::shouldReceive('flexible')->never();

        try {
            $controller->index(Request::create('/api/v1/store/home', 'GET'));
            $this->fail('Expected a 503 when the home lock times out with no stale payload.');
        } catch (BusinessException $exception) {
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_home_banners_are_served_from_a_short_flexible_cache(): void
    {
        Banner::factory()->create(['title' => 'عرض اليوم', 'is_active' => true]);

        $bannerQueries = function (): int {
            return collect(DB::getQueryLog())
                ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'banners'))
                ->count();
        };

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners.0.title', 'عرض اليوم');
        $this->assertGreaterThan(0, $bannerQueries());

        DB::flushQueryLog();
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners.0.title', 'عرض اليوم');
        $this->assertSame(0, $bannerQueries());
    }
}
