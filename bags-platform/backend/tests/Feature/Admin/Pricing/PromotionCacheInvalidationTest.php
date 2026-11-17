<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Promotion;
use App\Observers\PromotionObserver;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PromotionCacheInvalidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_promotion_mutation_invalidates_public_catalog_and_refreshes_price_snapshots(): void
    {
        Bus::fake([RefreshCatalogRetailPrices::class]);
        Cache::flush();
        Cache::put(StoreCatalogService::HOME_CACHE_KEY, ['stale' => true]);
        Cache::put(StoreCatalogService::HOME_BANNERS_CACHE_KEY, ['stale' => true]);
        Cache::put(StoreCatalogService::FACETS_VERSION_KEY, 3);

        app(PromotionObserver::class)->updated(new Promotion);

        $this->assertNull(Cache::get(StoreCatalogService::HOME_CACHE_KEY));
        $this->assertNull(Cache::get(StoreCatalogService::HOME_BANNERS_CACHE_KEY));
        $this->assertSame(4, (int) Cache::get(StoreCatalogService::FACETS_VERSION_KEY));
        Bus::assertDispatchedTimes(RefreshCatalogRetailPrices::class, 1);
    }
}
