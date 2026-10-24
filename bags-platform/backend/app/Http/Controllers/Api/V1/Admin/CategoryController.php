<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Category\ReorderCategoriesRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\Admin\Category\UpdateCategoryRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Models\Category;
use App\Services\Catalog\CategoryService;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CategoryController extends Controller implements HasMiddleware
{
    public function __construct(private CategoryService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:categories.view', only: ['index', 'show']),
            new Middleware('can:categories.create', only: ['store']),
            new Middleware('can:categories.update', only: ['update', 'reorder']),
            new Middleware('can:categories.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'tree' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'string', 'regex:/^(root|[0-9]{1,18})$/'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($request->boolean('tree')) {
            return CategoryResource::collection($this->service->tree());
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $categories = Category::query()
            ->with(['parent.media', 'media'])
            // F16: products_count excludes soft-deleted rows while delete()
            // checks withTrashed() — also expose the trashed-inclusive count
            // so the UI can explain "0 live, N deleted" instead of confusing 409s.
            ->withCount(['products', 'products as all_products_count' => fn ($q) => $q->withTrashed()])
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $pattern = SearchTerm::pattern($filters['search']);
                $query->where(fn ($q) => $q->whereLike('name_ar', $pattern, caseSensitive: false)
                    ->orWhereLike('name_en', $pattern, caseSensitive: false));
            })
            ->when(isset($filters['parent_id']), function ($query) use ($filters): void {
                if ($filters['parent_id'] === 'root') {
                    $query->whereNull('parent_id');
                } else {
                    $query->where('parent_id', (int) $filters['parent_id']);
                }
            })
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return CategoryResource::collection($categories);
    }

    public function show(Category $category): CategoryResource
    {
        $category->load('parent.media', 'children.media', 'media')->loadCount(['products', 'products as all_products_count' => fn ($q) => $q->withTrashed()]);

        return CategoryResource::make($category);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated(), $request->file('image'));
        $category->load('parent.media', 'media')->loadCount(['products', 'products as all_products_count' => fn ($q) => $q->withTrashed()]);

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }

    // ملحوظة: تحديث قسم مع صورة بيتبعت POST مع _method=PUT، لأن PHP لا يقرأ ملفات multipart مع PUT.
    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category = $this->service->update($category, $request->validated(), $request->file('image'));
        $category->load('parent.media', 'media')->loadCount(['products', 'products as all_products_count' => fn ($q) => $q->withTrashed()]);

        return CategoryResource::make($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->service->delete($category);

        return response()->json(['message' => 'تم الحذف']);
    }

    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        $this->service->reorder($request->validated()['items']);

        return response()->json(['message' => 'تم الترتيب']);
    }
}
