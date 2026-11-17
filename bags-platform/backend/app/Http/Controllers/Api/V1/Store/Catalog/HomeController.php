<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Catalog;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Store\Catalog\StoreBannerResource;
use App\Http\Resources\Store\Catalog\StoreProductCardResource;
use App\Models\Banner;
use App\Services\Catalog\BannerLinkResolver;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Storefront homepage aggregator: active banners, latest arrivals, best sellers, and discounts.
 *
 * Caches product ID sets while calculating prices per-request to preserve customer-specific pricing.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly StoreCatalogService $catalog,
        private readonly BannerLinkResolver $bannerResolver,
    ) {}

    /**
     * Retrieve aggregated homepage sections.
     */
    public function index(Request $request): JsonResponse
    {
        $ids = $this->homeSectionIds();
        $ids['discounted_ids'] = $this->catalog->personalizedDiscountedIds() ?? $ids['discounted_ids'];
        $allIds = array_unique([...$ids['latest_ids'], ...$ids['best_seller_ids'], ...$ids['discounted_ids']]);
        $cards = StoreProductCardResource::collection($this->catalog->publishedQuery()
            ->whereIn('products.id', $allIds)->get())->resolve($request);
        $cards = collect($cards)->keyBy('id');
        $section = fn (array $sectionIds) => collect($sectionIds)->map(fn ($id) => $cards->get($id))->filter()->values();

        $banners = $this->bannerResolver->attach($this->activeBanners());
        $bannersPayload = collect(StoreBannerResource::collection($banners)->resolve($request))
            ->filter(fn (array $b) => $b['image'] !== null)
            ->values();

        return response()->json([
            'data' => [
                'banners' => $bannersPayload,
                'latest_products' => $section($ids['latest_ids']),
                'best_sellers' => $section($ids['best_seller_ids']),
                'discounted_products' => $section($ids['discounted_ids'])->filter(fn ($card) => $card['price'] !== null && $card['discount_percentage'] !== null)->take(12)->values(),
            ],
        ]);
    }

    /**
     * @return array{latest_ids: list<int>, best_seller_ids: list<int>, discounted_ids: list<int>}
     */
    private function homeSectionIds(): array
    {
        $key = StoreCatalogService::HOME_CACHE_KEY;
        $load = fn (): array => $this->catalog->homeSectionIds();
        if ((string) config('cache.default') === 'array') {
            return $load();
        }

        $flexible = fn (): array => Cache::flexible($key, [300, 1800], $load, ['seconds' => 15]);
        if (is_array(Cache::get($key))) {
            return $flexible();
        }

        try {
            return Cache::lock($key.':build', 15)->block(2, $flexible);
        } catch (LockTimeoutException) {
            $stale = Cache::get($key);
            if (is_array($stale) && isset($stale['latest_ids'], $stale['best_seller_ids'], $stale['discounted_ids'])) {
                return $stale;
            }

            throw new BusinessException('جاري تحديث البيانات المرجعية؛ أعد المحاولة بعد لحظات.', [], 503);
        }
    }

    /**
     * @return Collection<int, Banner>
     */
    private function activeBanners(): Collection
    {
        $load = fn (): Collection => Banner::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $banners = Cache::flexible('store:home:banners:v1', [60, 300], $load, ['seconds' => 15]);

        return $banners instanceof Collection ? $banners : collect($banners);
    }
}
