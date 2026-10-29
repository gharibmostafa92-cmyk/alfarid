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
 * الصفحة الرئيسية للمتجر: رد مجمّع واحد (بانرات + أحدث + أكثر مبيعًا + مخفضات).
 * الكاش يخزن معرّفات الأقسام فقط (5 دقائق) — الأسعار تُحسب لكل طلب على حدة
 * لأنها شخصية (خصم مجموعة/سعر خاص)، فلا تتسرب أسعار عميل لزائر آخر أبدًا.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly StoreCatalogService $catalog,
        private readonly BannerLinkResolver $bannerResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $load = fn () => Cache::flexible(StoreCatalogService::HOME_CACHE_KEY, [300, 1800],
                fn () => $this->catalog->homeSectionIds(), ['seconds' => 120]);
            // F10: انتظار ثانية على البداية الباردة، وعند انتهاء المهلة
            // نحسب مباشرة (3 استعلامات LIMIT 12) بدل صفحة فارغة لكل الزوار.
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
