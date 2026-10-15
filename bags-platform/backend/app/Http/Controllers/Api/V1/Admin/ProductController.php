<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\Admin\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

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

    public function index(Request $request): AnonymousResourceCollection
    {
        // فلاتر القائمة بتتفحص الأول: أي قيمة غلط تبقى 422 بدل 500 من Postgres
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'sales_channel' => ['nullable', Rule::enum(SalesChannel::class)],
            'is_featured' => ['nullable', 'boolean'],
            'include_children' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['latest', 'oldest', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $q = Product::query()
            ->with(['category.media', 'brand.media', 'media'])
            ->withCount('variants')
            ->withMin(['variants as min_retail_price' => fn ($v) => $v->where('is_active', true)], 'retail_price')
            ->withMax(['variants as max_retail_price' => fn ($v) => $v->where('is_active', true)], 'retail_price');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $s = $filters['search'];
            $pattern = SearchTerm::pattern($s);
            $q->where(fn ($w) => $w->whereLike('name_ar', $pattern, caseSensitive: false)
                ->orWhereLike('name_en', $pattern, caseSensitive: false)
                ->orWhere('code', $s)
                ->orWhereHas('variants', fn ($v) => $v->where('sku', $s)->orWhere('barcode', $s)));
        }
        if (! empty($filters['category_id'])) {
            $cat = Category::findOrFail($filters['category_id']);
            $ids = ! empty($filters['include_children']) ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
            $q->whereIn('category_id', $ids);
        }
        $q->when($filters['brand_id'] ?? null, fn ($x, $v) => $x->where('brand_id', $v))
            ->when($filters['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when($filters['sales_channel'] ?? null, fn ($x, $v) => $x->where('sales_channel', $v))
            ->when(isset($filters['is_featured']), fn ($x) => $x->where('is_featured', filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN)));

        // ترتيب ثابت: id مفتاح كاسر للتعادل عشان الصفحات متتكررش ولا تختفي منها عناصر
        match ($filters['sort'] ?? 'latest') {
            'oldest' => $q->oldest()->orderBy('id'),
            'name' => $q->orderBy('name_en')->orderBy('id'),
            default => $q->latest()->orderByDesc('id'),
        };

        return ProductResource::collection($q->paginate($perPage)->withQueryString());
    }

    public function show(Product $product): ProductResource
    {
        $product->load(['category.media', 'brand.media', 'variants.media', 'media']);

        return ProductResource::make($product);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! $request->user()->can('products.view_cost')) {
            foreach ($data['variants'] ?? [] as $index => $variant) {
                unset($data['variants'][$index]['cost_price']);
            }
        }
        $product = $this->service->create($data, $request->user());

        return ProductResource::make($product)->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product = $this->service->update($product, $request->validated(), $request->user());

        return ProductResource::make($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->service->delete($product);

        return response()->json(['message' => 'تم الحذف']);
    }
}
