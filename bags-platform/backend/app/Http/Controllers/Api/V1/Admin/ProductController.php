<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\Admin\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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
        $perPage = min((int) $request->input('per_page', 15), 100);

        $q = Product::query()
            ->with(['category:id,name_ar,name_en', 'brand:id,name_ar,name_en', 'media'])
            ->withCount('variants')
            ->withMin('variants as min_retail_price', 'retail_price')
            ->withMax('variants as max_retail_price', 'retail_price');

        if ($s = $request->search) {
            $q->where(fn ($w) => $w->where('name_ar', 'like', "%$s%")->orWhere('name_en', 'like', "%$s%")
                ->orWhere('code', $s)
                ->orWhereHas('variants', fn ($v) => $v->where('sku', $s)->orWhere('barcode', $s)));
        }
        if ($request->filled('category_id')) {
            $cat = Category::findOrFail($request->category_id);
            $ids = $request->boolean('include_children') ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
            $q->whereIn('category_id', $ids);
        }
        $q->when($request->brand_id, fn ($x, $v) => $x->where('brand_id', $v))
            ->when($request->status, fn ($x, $v) => $x->where('status', $v))
            ->when($request->sales_channel, fn ($x, $v) => $x->where('sales_channel', $v))
            ->when($request->has('is_featured'), fn ($x) => $x->where('is_featured', $request->boolean('is_featured')));

        match ($request->input('sort', 'latest')) {
            'oldest' => $q->oldest(),
            'name' => $q->orderBy('name_en'),
            default => $q->latest(),
        };

        return ProductResource::collection($q->paginate($perPage)->withQueryString());
    }

    public function show(Product $product): ProductResource
    {
        $product->load(['category', 'brand', 'variants.media', 'media']);

        return ProductResource::make($product);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->service->create($request->validated(), $request->user());

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
