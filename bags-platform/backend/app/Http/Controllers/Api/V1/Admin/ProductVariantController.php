<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Variant\StoreVariantRequest;
use App\Http\Requests\Admin\Variant\UpdateVariantRequest;
use App\Http\Resources\Admin\VariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\VariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductVariantController extends Controller implements HasMiddleware
{
    public function __construct(private VariantService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index', 'show']),
            // تعديل المتغيرات معناه تعديل المنتج نفسه
            new Middleware('can:products.update', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Product $product): AnonymousResourceCollection
    {
        return VariantResource::collection($product->variants()->with('media')->get());
    }

    public function store(StoreVariantRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        if (! $request->user()->can('products.view_cost')) {
            unset($data['cost_price']);
        }

        $variant = $this->service->create($product, $data);
        $variant->load('media');
        $variant->setRelation('product', $product);

        return VariantResource::make($variant)->response()->setStatusCode(201);
    }

    public function show(Product $product, ProductVariant $variant): VariantResource
    {
        $variant->load('media');
        $variant->setRelation('product', $product);

        return VariantResource::make($variant);
    }

    public function update(UpdateVariantRequest $request, Product $product, ProductVariant $variant): VariantResource
    {
        $data = $request->validated();
        if (! $request->user()->can('products.view_cost')) {
            unset($data['cost_price']);
        }

        $variant = $this->service->update($variant, $data);
        $variant->load('media')->setRelation('product', $product);

        return VariantResource::make($variant);
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        $this->service->delete($variant);

        return response()->json(['message' => 'تم الحذف']);
    }
}
