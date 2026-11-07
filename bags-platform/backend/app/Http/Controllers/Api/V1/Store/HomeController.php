<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Store\StoreBannerResource;
use App\Http\Resources\Store\StoreProductCardResource;
use App\Models\Banner;
use App\Services\BannerLinkResolver;
use App\Services\StoreCatalogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        try {
            $load = fn () => Cache::flexible(StoreCatalogService::HOME_CACHE_KEY, [300, 1800],
                fn () => $this->catalog->homeSectionIds(), ['seconds' => 120]);

            // Block 1s on cache stampede, falling back to direct computation to avoid returning empty homepage sections.
            $ids = Cache::has(StoreCatalogService::HOME_CACHE_KEY) ? $load() : Cache::lock(StoreCatalogService::HOME_CACHE_KEY.':build', 120)->block(1, $load);
        } catch (LockTimeoutException) {
            $ids = Cache::get(StoreCatalogService::HOME_CACHE_KEY) ?? $this->catalog->homeSectionIds();
        }
        $allIds = array_unique([...$ids['latest_ids'], ...$ids['best_seller_ids'], ...$ids['discounted_ids']]);
        $cards = StoreProductCardResource::collection($this->catalog->publishedQuery()
            ->whereIn('products.id', $allIds)->get())->resolve($request);
        $cards = collect($cards)->keyBy('id');
        $section = fn (array $sectionIds) => collect($sectionIds)->map(fn ($id) => $cards->get($id))->filter()->values();

        $banners = Banner::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id')->get();
        $banners = $this->bannerResolver->attach($banners);
        $bannersPayload = collect(StoreBannerResource::collection($banners)->resolve($request))
            ->filter(fn (array $b) => $b['image'] !== null)
            ->values();

        return response()->json([
            'data' => [
                'banners' => $bannersPayload,
                'latest_products' => $section($ids['latest_ids']),
                'best_sellers' => $section($ids['best_seller_ids']),
                'discounted_products' => $section($ids['discounted_ids'])->filter(fn ($card) => $card['price'] !== null && $card['discount_percentage'] !== null)->values(),
            ],
        ]);
    }
}
