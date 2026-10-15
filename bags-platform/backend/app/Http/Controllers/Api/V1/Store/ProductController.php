<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ProductFilterRequest;
use App\Http\Resources\Store\StoreProductCardResource;
use App\Http\Resources\Store\StoreProductResource;
use App\Services\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** تصفح منتجات المتجر: قائمة بفلاتر، وصفحة منتج بالـ slug، ومنتجات مشابهة. كله عام. */
class ProductController extends Controller
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    public function index(ProductFilterRequest $request): AnonymousResourceCollection
    {
        $products = $this->catalog->applyFilters($this->catalog->publishedQuery(), $request->validated())
            ->paginate(20);

        return StoreProductCardResource::collection($products);
    }

    public function show(string $slug): StoreProductResource
    {
        $product = $this->catalog->publishedQuery()
            ->where('products.slug', $slug)
            ->firstOrFail();

        $resource = new StoreProductResource($product);
        $resource->availability = $this->catalog->availabilityMap($product->variants->pluck('id')->all());

        return $resource;
    }

    public function similar(string $slug): JsonResponse
    {
        $product = $this->catalog->publishedQuery()
            ->where('products.slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => StoreProductCardResource::collection($this->catalog->similar($product))->resolve(),
        ]);
    }
}
