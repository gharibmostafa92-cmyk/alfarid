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
        $filters = $request->validated();
        $total = in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)
            && ! isset($filters['price_min']) && ! isset($filters['price_max'])
            ? fn () => $this->catalog->applyFilters($this->catalog->publishedQuery(), [...$filters, 'sort' => 'newest'])
                ->reorder()->count('products.id') : null;
        $products = $this->catalog->applyFilters($this->catalog->publishedQuery(), $request->validated())
            ->paginate(20, total: $total)->withQueryString();

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
            ->withoutEagerLoads()
            ->select(['products.id', 'products.category_id', 'products.brand_id'])
            ->where('products.slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => StoreProductCardResource::collection($this->catalog->similar($product))->resolve(),
        ]);
    }
}
