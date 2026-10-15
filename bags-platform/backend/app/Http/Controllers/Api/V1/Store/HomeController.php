<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Store\StoreBannerResource;
use App\Http\Resources\Store\StoreProductCardResource;
use App\Models\Banner;
use App\Models\Product;
use App\Services\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * الصفحة الرئيسية للمتجر: رد مجمّع واحد (بانرات + أحدث + أكثر مبيعًا + مخفضات).
 * الكاش يخزن معرّفات الأقسام فقط (5 دقائق) — الأسعار تُحسب لكل طلب على حدة
 * لأنها شخصية (خصم مجموعة/سعر خاص)، فلا تتسرب أسعار عميل لزائر آخر أبدًا.
 */
class HomeController extends Controller
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $ids = Cache::remember('store_home_data', 300, fn () => $this->catalog->homeSectionIds());

        return response()->json([
            'data' => [
                'banners' => StoreBannerResource::collection($this->inOrder(Banner::query(), $ids['banner_ids']))->resolve($request),
                'latest_products' => StoreProductCardResource::collection($this->productsInOrder($ids['latest_ids']))->resolve($request),
                'best_sellers' => StoreProductCardResource::collection($this->productsInOrder($ids['best_seller_ids']))->resolve($request),
                'discounted_products' => StoreProductCardResource::collection($this->productsInOrder($ids['discounted_ids']))->resolve($request),
            ],
        ]);
    }

    /** @param list<int> $ids */
    private function inOrder($query, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $positions = array_flip($ids);

        return $query->whereIn('id', $ids)->get()->sortBy(fn ($m) => $positions[$m->id] ?? PHP_INT_MAX)->values();
    }

    /** @param list<int> $ids */
    private function productsInOrder(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $positions = array_flip($ids);

        return $this->catalog->publishedQuery()
            ->whereIn('products.id', $ids)
            ->get()
            ->sortBy(fn (Product $p) => $positions[$p->id] ?? PHP_INT_MAX)
            ->values();
    }
}
