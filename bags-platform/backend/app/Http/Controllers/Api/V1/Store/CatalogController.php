<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Store\StoreBrandResource;
use App\Http\Resources\Store\StoreCategoryResource;
use App\Http\Resources\Store\StoreCityResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** قوائم التصفح الثابتة: أقسام (شجرة بعدّادات منتجات منشورة) وعلامات ومدن الشحن. */
class CatalogController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->withCount('activeProducts')
            ->with([
                'children' => fn ($q) => $q->where('is_active', true)->withCount('activeProducts')->orderBy('sort_order')
                    ->with(['children' => fn ($q2) => $q2->where('is_active', true)->withCount('activeProducts')->orderBy('sort_order')]),
            ])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => StoreCategoryResource::collection($roots)->resolve($request)]);
    }

    public function brands(Request $request): JsonResponse
    {
        $brands = Brand::query()
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
