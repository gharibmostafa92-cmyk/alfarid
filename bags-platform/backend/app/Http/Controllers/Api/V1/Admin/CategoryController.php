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

/**
 * Admin controller for hierarchical product categories, tree visualization, and reordering.
 */
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

    /**
     * Lists categories as either a paginated list or a hierarchical tree.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Category resources
     */
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
            // Expose trashed-inclusive count so clients can distinguish active vs soft-deleted product dependencies.
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

    /**
     * Retrieves details of a specific category including its child subcategories.
     *
     * @param Category $category Target category
     * @return CategoryResource Category resource
     */
    public function show(Category $category): CategoryResource
    {
        return $this->categoryResource($category, includeChildren: true);
    }

    /**
     * Creates a new category with an optional image.
     *
     * @param StoreCategoryRequest $request Validated creation request
     * @return JsonResponse Created category response
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated(), $request->file('image'));

        return $this->categoryResource($category)->response()->setStatusCode(201);
    }

    /**
     * Updates category attributes, parent nesting, or image.
     *
     * @param UpdateCategoryRequest $request Validated update request
     * @param Category $category Target category
     * @return CategoryResource Updated category resource
     */
    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        // Multipart file updates require POST with _method=PUT because PHP does not parse multipart on PUT.
        $category = $this->service->update($category, $request->validated(), $request->file('image'));

        return $this->categoryResource($category);
    }

    /**
     * Deletes a category if it has no associated products or subcategories.
     *
     * @param Category $category Target category
     * @return JsonResponse Deletion confirmation
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->service->delete($category);

        return response()->json(['message' => 'تم الحذف']);
    }

    /**
     * Reorders category display priority.
     *
     * @param ReorderCategoriesRequest $request Request with ordered items
     * @return JsonResponse Reorder confirmation
     */
    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        $this->service->reorder($request->validated()['items']);

        return response()->json(['message' => 'تم الترتيب']);
    }

    private function categoryResource(Category $category, bool $includeChildren = false): CategoryResource
    {
        $relations = $includeChildren ? ['parent.media', 'children.media', 'media'] : ['parent.media', 'media'];
        $category->loadMissing($relations)->loadCount(['products', 'products as all_products_count' => fn ($q) => $q->withTrashed()]);

        return CategoryResource::make($category);
    }
}
