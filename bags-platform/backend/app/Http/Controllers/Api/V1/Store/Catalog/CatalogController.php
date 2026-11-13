<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Catalog;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ProductFilterRequest;
use App\Http\Resources\Store\Catalog\StoreBrandResource;
use App\Http\Resources\Store\Catalog\StoreCategoryResource;
use App\Http\Resources\Store\Orders\StoreCityResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Serves storefront taxonomy and reference metadata: category trees, filter facets, and shipping cities.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    /**
     * Compute active filter facets based on query parameters.
     */
    public function filters(ProductFilterRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->facets($request->validated())]);
    }

    /**
     * Retrieve hierarchical category tree with active product counts.
     */
    public function categories(): JsonResponse
    {
        $data = $this->cachedReference('store:categories:v1:'.app()->getLocale(), function (): array {
            $childrenScope = fn ($q) => $q->where('is_active', true)
                ->with('media')
                ->withCount('activeProducts')
                ->orderBy('sort_order')
                ->orderBy('id');

            $roots = Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with('media')
                ->withCount('activeProducts')
                ->with([
                    // Tie-break with ID keeps sibling ordering deterministic across pagination.
                    'children' => fn ($q) => $childrenScope($q)->with(['children' => $childrenScope]),
                ])
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            return StoreCategoryResource::collection($roots)->resolve(Request::create('/'));
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Retrieve active brands for legacy client compatibility.
     */
    public function brands(): JsonResponse
    {
        $data = $this->cachedReference('store:brands:v1:'.app()->getLocale(), function (): array {
            $brands = Brand::query()
                ->with('media')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name_ar')
                ->get();

            return StoreBrandResource::collection($brands)->resolve(Request::create('/'));
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Retrieve active shipping cities for checkout delivery selection.
     */
    public function cities(): JsonResponse
    {
        $data = $this->cachedReference('store:cities:v1:'.app()->getLocale(), function (): array {
            $cities = City::query()
                ->where('is_active', true)
                ->orderBy('name_ar')
                ->get();

            return StoreCityResource::collection($cities)->resolve(Request::create('/'));
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Cache near-static reference payloads with a single builder lock so a burst of
     * cold requests cannot stampede the database.
     *
     * @param  \Closure(): array<int|string, mixed>  $load
     * @return array<int|string, mixed>
     */
    private function cachedReference(string $key, \Closure $load): array
    {
        if ((string) config('cache.default') === 'array') {
            return $load();
        }
        $flexible = fn (): array => Cache::flexible($key, [300, 900], $load, ['seconds' => 120]);
        if (Cache::has($key)) {
            return $flexible();
        }
        try {
            return Cache::lock($key.':build', 120)->block(5, $flexible);
        } catch (LockTimeoutException) {
            throw new BusinessException('جاري تحديث البيانات المرجعية؛ أعد المحاولة بعد لحظات.', [], 503);
        }
    }
}
