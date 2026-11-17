<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Services\Catalog\StoreCatalogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class StoreCatalogCacheSafetyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_facet_lock_timeout_serves_the_last_successful_result(): void
    {
        $key = 'store:facets:v3:r10:filter-hash:structure';
        $stableIdentity = 'filter-hash:structure';
        $staleKey = 'store:facets:last-good:'.hash('sha256', $stableIdentity);
        $payload = ['categories' => [], 'colors' => [], 'price_ranges' => []];
        $lock = Mockery::mock();
        $lock->shouldReceive('block')
            ->once()
            ->with(2, Mockery::type(\Closure::class))
            ->andThrow(new LockTimeoutException);

        Cache::shouldReceive('has')->once()->with($key)->andReturnFalse();
        Cache::shouldReceive('lock')->once()->with($key.':build', 15)->andReturn($lock);
        Cache::shouldReceive('get')->once()->with($staleKey)->andReturn($payload);

        $method = new ReflectionMethod(StoreCatalogService::class, 'cachedFacetsPart');
        $result = $method->invoke(app(StoreCatalogService::class), $key, fn () => $this->fail('The stale result should avoid recomputation.'));

        $this->assertSame($payload, $result);
    }
}
