<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ProductFilterRequest;
use App\Http\Resources\Store\StoreBrandResource;
use App\Http\Resources\Store\StoreCategoryResource;
use App\Http\Resources\Store\StoreCityResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use App\Services\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** قوائم التصفح الثابتة: أقسام (شجرة بعدّادات منتجات منشورة) وعلامات ومدن الشحن. */
class CatalogController extends Controller
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    public function filters(ProductFilterRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->facets($request->validated())]);
    }

    public function categories(Request $request): JsonResponse
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with('media')
            ->withCount('activeProducts')
            ->with([
                // L6: id tie-break keeps sibling order deterministic across pages.
                'children' => fn ($q) => $q->where('is_active', true)->with('media')->withCount('activeProducts')->orderBy('sort_order')->orderBy('id')
                    ->with(['children' => fn ($q2) => $q2->where('is_active', true)->with('media')->withCount('activeProducts')->orderBy('sort_order')->orderBy('id')]),
            ])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => StoreCategoryResource::collection($roots)->resolve($request)]);
    }

    /**
     * F19: brands are a legacy surface — products no longer accept a brand_id
     * (prohibited in admin requests, forced to null in the service) and the
     * storefront never filters by brand. This endpoint is retained read-only
     * for old clients; do not build new features on it.
     */
    public function brands(Request $request): JsonResponse
    {
        $brands = Brand::query()
            ->with('media')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();

        return response()->json(['data' => StoreBrandResource::collection($brands)->resolve($request)]);
    }

    public function cities(Request $request): JsonResponse
    {
        $cities = City::query()
            ->where('is_active', true)
            ->orderBy('name_ar')
            ->get();

        return response()->json(['data' => StoreCityResource::collection($cities)->resolve($request)]);
    }
}
