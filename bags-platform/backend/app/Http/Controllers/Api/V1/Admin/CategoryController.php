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
        if ($request->boolean('tree')) {
            return CategoryResource::collection($this->service->tree());
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $categories = Category::query()
            ->with('parent', 'media')
            ->withCount('products')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->input('search');
                $query->where(function ($q) use ($search): void {
                    $q->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%");
                });
            })
            ->when($request->has('parent_id'), function ($query) use ($request): void {
                if ($request->input('parent_id') === 'root') {
                    $query->whereNull('parent_id');
                } else {
                    $query->where('parent_id', $request->input('parent_id'));
                }
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return CategoryResource::collection($categories);
    }

    public function show(Category $category): CategoryResource
    {
        $category->load('parent', 'children', 'media')->loadCount('products');

        return CategoryResource::make($category);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated(), $request->file('image'));
        $category->load('parent', 'media')->loadCount('products');

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }

    // ملحوظة: تحديث قسم مع صورة بيتبعت POST مع _method=PUT، لأن PHP لا يقرأ ملفات multipart مع PUT.
    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category = $this->service->update($category, $request->validated(), $request->file('image'));
        $category->load('parent', 'media')->loadCount('products');

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
