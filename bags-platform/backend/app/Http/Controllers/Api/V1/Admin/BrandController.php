<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Brand\UpdateBrandRequest;
use App\Http\Resources\Admin\BrandResource;
use App\Models\Brand;
use App\Services\Catalog\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BrandController extends Controller implements HasMiddleware
{
    public function __construct(private BrandService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:brands.view', only: ['index', 'show']),
            new Middleware('can:brands.create', only: ['store']),
            new Middleware('can:brands.update', only: ['update']),
            new Middleware('can:brands.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $brands = Brand::query()
            ->with('media')
            ->withCount('products')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->input('search');
                $query->where(function ($q) use ($search): void {
                    $q->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return BrandResource::collection($brands);
    }

    public function show(Brand $brand): BrandResource
    {
        $brand->load('media')->loadCount('products');

        return BrandResource::make($brand);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->service->create($request->validated(), $request->file('logo'));
        $brand->load('media')->loadCount('products');

        return BrandResource::make($brand)->response()->setStatusCode(201);
    }

    // ملحوظة: تحديث براند مع لوجو بيتبعت POST مع _method=PUT، لأن PHP لا يقرأ ملفات multipart مع PUT.
    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        $brand = $this->service->update($brand, $request->validated(), $request->file('logo'));
        $brand->load('media')->loadCount('products');

        return BrandResource::make($brand);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->service->delete($brand);

        return response()->json(['message' => 'تم الحذف']);
    }
}
