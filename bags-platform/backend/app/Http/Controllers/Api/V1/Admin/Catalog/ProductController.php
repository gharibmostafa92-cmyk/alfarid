<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\Admin\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductService;
use App\Services\Shared\CostAccess;
use App\Services\Shared\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Backoffice catalog product management: listing with facet aggregations, creation, updates, and cost protection.
 */
class ProductController extends Controller implements HasMiddleware
{
    public function __construct(private ProductService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index', 'show']),
            new Middleware('can:products.create', only: ['store']),
            new Middleware('can:products.update', only: ['update']),
            new Middleware('can:products.delete', only: ['destroy']),
        ];
    }

    /**
     * List paginated products with category tree resolution and price aggregations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Validate filter inputs upfront to prevent unhandled database query exceptions.
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['prohibited'],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'sales_channel' => ['nullable', Rule::enum(SalesChannel::class)],
            'is_featured' => ['nullable', 'boolean'],
            'include_children' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['latest', 'oldest', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $q = Product::query()
            ->with(['category.media', 'media' => fn ($media) => $media->where('collection_name', 'images')->orderBy('order_column')->orderBy('id')->limit(1)])
            ->withCount('variants')
            ->withMin(['variants as min_retail_price' => fn ($v) => $v->where('is_active', true)], 'retail_price')
            ->withMax(['variants as max_retail_price' => fn ($v) => $v->where('is_active', true)], 'retail_price');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $s = $filters['search'];
            $pattern = SearchTerm::pattern($s);
            // SKUs/barcodes/codes are stored uppercased; match case-insensitively using
            // functional index backing on lower(sku) / lower(barcode).
            $u = strtoupper($s);
            $q->where(fn ($w) => $w->whereLike('name_ar', $pattern, caseSensitive: false)
                ->orWhereLike('name_en', $pattern, caseSensitive: false)
                ->orWhere('code', $u)
                ->orWhereIn('products.id', ProductVariant::query()->select('product_id')
                    ->where(fn ($v) => $v->whereRaw('LOWER(sku) = LOWER(?)', [$s])->orWhereRaw('LOWER(barcode) = LOWER(?)', [$s]))));
        }
        if (! empty($filters['category_id'])) {
            $cat = Category::findOrFail($filters['category_id']);
            $ids = ! empty($filters['include_children']) ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
            $q->whereIn('category_id', $ids);
        }
        $q->when($filters['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when($filters['sales_channel'] ?? null, fn ($x, $v) => $x->where('sales_channel', $v))
            ->when(isset($filters['is_featured']), fn ($x) => $x->where('is_featured', filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN)));

        // Deterministic tiebreaker ID prevents page drift and missing rows across pagination offsets.
        match ($filters['sort'] ?? 'latest') {
            'oldest' => $q->oldest()->orderBy('id'),
            'name' => $q->orderBy('name_en')->orderBy('id'),
            default => $q->latest()->orderByDesc('id'),
        };

        $paginator = $q->paginate($perPage)->withQueryString();

        return ProductResource::collection($paginator);
    }

    /**
     * Retrieve full details of a single product.
     */
    public function show(Product $product): ProductResource
    {
        return $this->productResource($product);
    }

    /**
     * Store a newly created product with its variants.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $this->filterCostPrices($request->validated(), $request);
        $product = $this->service->create($data, $request->user());

        return $this->productResource($product)->response()->setStatusCode(201);
    }

    /**
     * Update an existing product and its attributes.
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product = $this->service->update($product, $request->validated(), $request->user());

        return $this->productResource($product);
    }

    /**
     * Delete a product and its associated variants.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->service->delete($product, $request->user());

        return response()->json(['message' => 'تم الحذف']);
    }

    private function productResource(Product $product): ProductResource
    {
        $product->loadMissing(['category.media', 'variants.media', 'media']);

        return ProductResource::make($product);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterCostPrices(array $data, Request $request): array
    {
        if (! CostAccess::canView($request->user())) {
            foreach ($data['variants'] ?? [] as $index => $variant) {
                unset($data['variants'][$index]['cost_price']);
            }
        }

        return $data;
    }
}
