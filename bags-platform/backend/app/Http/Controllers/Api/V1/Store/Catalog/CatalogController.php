<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ProductFilterRequest;
use App\Http\Resources\Store\Catalog\StoreBrandResource;
use App\Http\Resources\Store\Catalog\StoreCategoryResource;
use App\Http\Resources\Store\Orders\StoreCityResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function categories(Request $request): JsonResponse
    {
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

        return response()->json(['data' => StoreCategoryResource::collection($roots)->resolve($request)]);
    }

    /**
     * Retrieve active brands for legacy client compatibility.
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

    /**
     * Retrieve active shipping cities for checkout delivery selection.
     */
    public function cities(Request $request): JsonResponse
    {
        $cities = City::query()
            ->where('is_active', true)
            ->orderBy('name_ar')
            ->get();

        return response()->json(['data' => StoreCityResource::collection($cities)->resolve($request)]);
    }
}
