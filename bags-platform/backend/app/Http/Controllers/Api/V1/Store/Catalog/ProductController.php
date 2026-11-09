<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ProductFilterRequest;
use App\Http\Resources\Store\Catalog\StoreProductCardResource;
use App\Http\Resources\Store\Catalog\StoreProductResource;
use App\Services\Catalog\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public storefront product browsing: filtered catalogs, product details, curated showcase, and similar items.
 */
class ProductController extends Controller
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    /**
     * Paginate published products matching applied search, category, and pricing filters.
     */
    public function index(ProductFilterRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $total = in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)
            && ! isset($filters['price_min']) && ! isset($filters['price_max'])
            ? fn () => $this->catalog->applyFilters($this->catalog->publishedQuery(), [...$filters, 'sort' => 'newest'])
                ->reorder()->count('products.id') : null;
        $products = $this->catalog->applyFilters($this->catalog->publishedQuery(), $request->validated())
            ->paginate((int) ($filters['per_page'] ?? 20), total: $total)->withQueryString();

        return StoreProductCardResource::collection($products);
    }

    /**
     * Retrieve single published product details by unique slug.
     */
    public function show(string $slug): StoreProductResource
    {
        $product = $this->catalog->publishedQuery()
            ->where('products.slug', $slug)
            ->firstOrFail();

        return StoreProductResource::make($product);
    }

    /**
     * Retrieve curated showcase products.
     */
    public function showcase(Request $request): JsonResponse
    {
        $ids = $this->catalog->showcaseIds();
        $loaded = $this->catalog->publishedQuery()->whereIn('products.id', $ids)->get()->keyBy('id');
        $products = collect($ids)->map(fn ($id) => $loaded->get($id))->filter()->values();

        return response()->json(['data' => StoreProductResource::collection($products)->resolve($request)]);
    }

    /**
     * Retrieve related products within the same category.
     */
    public function similar(string $slug): JsonResponse
    {
        $product = $this->catalog->publishedQuery()
            ->withoutEagerLoads()
            // Match similar products strictly by category_id as brand references are deprecated.
            ->select(['products.id', 'products.category_id'])
            ->where('products.slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => StoreProductCardResource::collection($this->catalog->similar($product))->resolve(),
        ]);
    }
}
