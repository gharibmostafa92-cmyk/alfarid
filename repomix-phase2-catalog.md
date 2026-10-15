This file is a merged representation of a subset of the codebase, containing specifically included files, combined into a single document by Repomix.
The content has been processed where line numbers have been added, content has been formatted for parsing in markdown style.

# File Summary

## Purpose
This file contains a packed representation of a subset of the repository's contents that is considered the most important context.
It is designed to be easily consumable by AI systems for analysis, code review,
or other automated processes.

## File Format
The content is organized as follows:
1. This summary section
2. Repository information
3. Directory structure
4. Repository files (if enabled)
5. Multiple file entries, each consisting of:
  a. A header with the file path (## File: path/to/file)
  b. The full contents of the file in a code block

## Usage Guidelines
- This file should be treated as read-only. Any changes should be made to the
  original repository files, not this packed version.
- When processing this file, use the file path to distinguish
  between different files in the repository.
- Be aware that this file may contain sensitive information. Handle it with
  the same level of security as you would the original repository.
- Pay special attention to the Repository Description. These contain important context and guidelines specific to this project.
- Pay special attention to the Repository Instruction. These contain important context and guidelines specific to this project.

## Notes
- Some files may have been excluded based on .gitignore rules and Repomix's configuration
- Binary files are not included in this packed representation. Please refer to the Repository Structure section for a complete list of file paths, including binary files
- Only files matching these patterns are included: bags-platform/backend/app/Enums/*.php, bags-platform/backend/app/Exceptions/BusinessException.php, bags-platform/backend/app/Services/Catalog/**/*.php, bags-platform/backend/app/Services/SlugService.php, bags-platform/backend/app/Services/BarcodeGenerator.php, bags-platform/backend/app/Services/SettingService.php, bags-platform/backend/app/Models/*.php, bags-platform/backend/app/Http/Requests/Admin/**/*.php, bags-platform/backend/app/Http/Resources/Admin/*.php, bags-platform/backend/app/Http/Resources/MediaResource.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/BrandController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/CategoryController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductVariantController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductMediaController.php, bags-platform/backend/app/Http/Controllers/Controller.php, bags-platform/backend/app/Http/Middleware/*.php, bags-platform/backend/app/Providers/AppServiceProvider.php, bags-platform/backend/app/Modules/Catalog/**/*.php, bags-platform/backend/app/Modules/Products/**/*.php, bags-platform/backend/database/migrations/*.php, bags-platform/backend/database/factories/*.php, bags-platform/backend/database/seeders/*.php, bags-platform/backend/routes/api/admin.php, bags-platform/backend/bootstrap/app.php, bags-platform/backend/bootstrap/providers.php, bags-platform/backend/config/app.php, bags-platform/backend/config/auth.php, bags-platform/backend/config/permission.php, bags-platform/backend/config/sanctum.php, bags-platform/backend/config/media-library.php, bags-platform/backend/config/filesystems.php, bags-platform/backend/config/database.php, bags-platform/backend/config/cache.php, bags-platform/backend/config/queue.php, bags-platform/backend/composer.json, bags-platform/backend/composer.lock, bags-platform/backend/.env.example, bags-platform/backend/phpunit.xml, bags-platform/backend/tests/TestCase.php, bags-platform/backend/tests/Feature/Admin/Catalog/*.php, bags-platform/backend/tests/Feature/DatabaseSeederTest.php, bags-platform/backend/tests/Feature/DatabaseIndexTest.php, bags-platform/backend/tests/Feature/AdminRouteAggregationTest.php, bags-platform/docs/api.md, bags-platform/docs/phase2-audit.md
- Files matching patterns in .gitignore are excluded
- Line numbers have been added to the beginning of each line
- Content has been formatted for parsing in markdown style

# User Provided Header
Phase 2 Catalog bundle: current implementation after fixes, complete dependency manifests including composer.lock, tests, supporting database/authentication configuration, API documentation and audit report. The original phase 2 plan is embedded in the Instructions section. Source code is full and uncompressed, with line numbers. Framework remains the existing Laravel 13. Actual .env files, vendor, node_modules, storage and previous bundles are excluded.

# Directory Structure
```
bags-platform/
  backend/
    app/
      Enums/
        BranchType.php
        CustomerStatus.php
        CustomerType.php
        LocationType.php
        ProductStatus.php
        SalesChannel.php
        StaffRole.php
      Exceptions/
        BusinessException.php
      Http/
        Controllers/
          Api/
            V1/
              Admin/
                BrandController.php
                CategoryController.php
                ProductController.php
                ProductMediaController.php
                ProductVariantController.php
          Controller.php
        Middleware/
          EnsureCustomerAuthenticated.php
          EnsureStaffAuthenticated.php
          EnsureSupplierAuthenticated.php
          EnsureWholesaleApproved.php
        Requests/
          Admin/
            Brand/
              StoreBrandRequest.php
              UpdateBrandRequest.php
            Category/
              ReorderCategoriesRequest.php
              StoreCategoryRequest.php
              UpdateCategoryRequest.php
            Product/
              ReorderProductMediaRequest.php
              StoreProductRequest.php
              UpdateProductRequest.php
              UploadProductMediaRequest.php
            Variant/
              StoreVariantRequest.php
              UpdateVariantRequest.php
              VariantRules.php
        Resources/
          Admin/
            BrandResource.php
            CategoryResource.php
            ProductResource.php
            VariantResource.php
          MediaResource.php
      Models/
        Branch.php
        Brand.php
        Category.php
        Customer.php
        Location.php
        Product.php
        ProductVariant.php
        Setting.php
        SupplierUser.php
        User.php
      Modules/
        Catalog/
          Routes/
            api.php
            web.php
          CatalogServiceProvider.php
        Products/
          Routes/
            api.php
            web.php
          ProductsServiceProvider.php
      Providers/
        AppServiceProvider.php
      Services/
        Catalog/
          BrandService.php
          CategoryService.php
          ProductMediaService.php
          ProductService.php
          VariantService.php
        BarcodeGenerator.php
        SettingService.php
        SlugService.php
    bootstrap/
      app.php
      providers.php
    config/
      app.php
      auth.php
      cache.php
      database.php
      filesystems.php
      media-library.php
      permission.php
      queue.php
      sanctum.php
    database/
      factories/
        BranchFactory.php
        BrandFactory.php
        CategoryFactory.php
        LocationFactory.php
        ProductFactory.php
        ProductVariantFactory.php
        UserFactory.php
      migrations/
        0001_01_01_000000_create_users_table.php
        0001_01_01_000001_create_cache_table.php
        0001_01_01_000002_create_jobs_table.php
        2026_10_14_224514_create_permission_tables.php
        2026_10_14_224514_create_personal_access_tokens_table.php
        2026_10_14_225722_create_branches_table.php
        2026_10_14_230814_add_storage_details_to_branches_and_create_locations_table.php
        2026_10_15_014600_add_staff_authentication_fields_to_users_table.php
        2026_10_15_014601_create_customers_and_supplier_users_tables.php
        2026_10_15_021000_create_settings_table.php
        2026_10_15_023000_add_foreign_key_to_users_branch_id.php
        2026_10_15_032900_add_remember_tokens_to_customer_auth_tables.php
        2026_10_15_034200_add_lookup_indexes_to_customer_and_inventory_tables.php
        2026_10_15_110021_create_media_table.php
        2026_10_15_140044_create_brands_table.php
        2026_10_15_140045_create_categories_table.php
        2026_10_15_140046_create_products_table.php
        2026_10_15_140047_create_product_variants_table.php
      seeders/
        BranchSeeder.php
        CatalogPermissionSeeder.php
        CatalogSeeder.php
        DatabaseSeeder.php
        LocationSeeder.php
        RolePermissionSeeder.php
        SettingSeeder.php
    routes/
      api/
        admin.php
    tests/
      Feature/
        Admin/
          Catalog/
            CatalogAuditTest.php
            CategoryCatalogTest.php
            ProductCatalogTest.php
            ProductMediaCatalogTest.php
            VariantCatalogTest.php
        AdminRouteAggregationTest.php
        DatabaseIndexTest.php
        DatabaseSeederTest.php
      TestCase.php
    .env.example
    composer.json
    composer.lock
    phpunit.xml
  docs/
    api.md
    phase2-audit.md
```

# Files

## File: bags-platform/backend/app/Enums/BranchType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum BranchType: string
 8: {
 9:     case Branch = 'branch';
10:     case Warehouse = 'warehouse';
11: }
```

## File: bags-platform/backend/app/Enums/CustomerStatus.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum CustomerStatus: string
 8: {
 9:     case Pending = 'pending';
10:     case Approved = 'approved';
11:     case Rejected = 'rejected';
12:     case Blocked = 'blocked';
13: }
```

## File: bags-platform/backend/app/Enums/CustomerType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum CustomerType: string
 8: {
 9:     case Retail = 'retail';
10:     case Wholesale = 'wholesale';
11:     case Distributor = 'distributor';
12: }
```

## File: bags-platform/backend/app/Enums/LocationType.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum LocationType: string
 8: {
 9:     case Shelf = 'shelf';
10:     case Zone = 'zone';
11:     case Bin = 'bin';
12:     case Damaged = 'damaged';
13:     case Receiving = 'receiving';
14: }
```

## File: bags-platform/backend/app/Enums/ProductStatus.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum ProductStatus: string
 8: {
 9:     case Draft = 'draft';
10:     case Published = 'published';
11:     case Archived = 'archived';
12: }
```

## File: bags-platform/backend/app/Enums/SalesChannel.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum SalesChannel: string
 8: {
 9:     case Retail = 'retail';
10:     case Wholesale = 'wholesale';
11:     case Both = 'both';
12: 
13:     public function allowsRetail(): bool
14:     {
15:         return $this !== self::Wholesale;
16:     }
17: 
18:     public function allowsWholesale(): bool
19:     {
20:         return $this !== self::Retail;
21:     }
22: }
```

## File: bags-platform/backend/app/Enums/StaffRole.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Enums;
 6: 
 7: enum StaffRole: string
 8: {
 9:     case SuperAdmin = 'Super Admin';
10: }
```

## File: bags-platform/backend/app/Exceptions/BusinessException.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Exceptions;
 6: 
 7: use Exception;
 8: use Illuminate\Http\JsonResponse;
 9: 
10: // الاستثناء اللي بنرميه لما قاعدة من قواعد البيزنس تتكسر، وبيرجّع 422.
11: class BusinessException extends Exception
12: {
13:     public function __construct(string $message, public array $errors = [], public int $status = 422)
14:     {
15:         parent::__construct($message);
16:     }
17: 
18:     public function render(): JsonResponse
19:     {
20:         return response()->json(['message' => $this->getMessage(), 'errors' => $this->errors], $this->status);
21:     }
22: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/BrandController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Admin\Brand\StoreBrandRequest;
 9: use App\Http\Requests\Admin\Brand\UpdateBrandRequest;
10: use App\Http\Resources\Admin\BrandResource;
11: use App\Models\Brand;
12: use App\Services\Catalog\BrandService;
13: use Illuminate\Http\JsonResponse;
14: use Illuminate\Http\Request;
15: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
16: use Illuminate\Routing\Controllers\HasMiddleware;
17: use Illuminate\Routing\Controllers\Middleware;
18: 
19: class BrandController extends Controller implements HasMiddleware
20: {
21:     public function __construct(private BrandService $service) {}
22: 
23:     public static function middleware(): array
24:     {
25:         return [
26:             new Middleware('can:brands.view', only: ['index', 'show']),
27:             new Middleware('can:brands.create', only: ['store']),
28:             new Middleware('can:brands.update', only: ['update']),
29:             new Middleware('can:brands.delete', only: ['destroy']),
30:         ];
31:     }
32: 
33:     public function index(Request $request): AnonymousResourceCollection
34:     {
35:         $perPage = max(1, min((int) $request->input('per_page', 15), 100));
36: 
37:         $brands = Brand::query()
38:             ->with('media')
39:             ->withCount('products')
40:             ->when($request->filled('search'), function ($query) use ($request): void {
41:                 $search = $request->input('search');
42:                 $query->where(function ($q) use ($search): void {
43:                     $q->where('name_ar', 'like', "%{$search}%")
44:                         ->orWhere('name_en', 'like', "%{$search}%");
45:                 });
46:             })
47:             ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
48:             ->orderBy('sort_order')
49:             ->orderBy('id')
50:             ->paginate($perPage)
51:             ->withQueryString();
52: 
53:         return BrandResource::collection($brands);
54:     }
55: 
56:     public function show(Brand $brand): BrandResource
57:     {
58:         $brand->load('media')->loadCount('products');
59: 
60:         return BrandResource::make($brand);
61:     }
62: 
63:     public function store(StoreBrandRequest $request): JsonResponse
64:     {
65:         $brand = $this->service->create($request->validated(), $request->file('logo'));
66:         $brand->load('media')->loadCount('products');
67: 
68:         return BrandResource::make($brand)->response()->setStatusCode(201);
69:     }
70: 
71:     // ملحوظة: تحديث براند مع لوجو بيتبعت POST مع _method=PUT، لأن PHP لا يقرأ ملفات multipart مع PUT.
72:     public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
73:     {
74:         $brand = $this->service->update($brand, $request->validated(), $request->file('logo'));
75:         $brand->load('media')->loadCount('products');
76: 
77:         return BrandResource::make($brand);
78:     }
79: 
80:     public function destroy(Brand $brand): JsonResponse
81:     {
82:         $this->service->delete($brand);
83: 
84:         return response()->json(['message' => 'تم الحذف']);
85:     }
86: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/CategoryController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Admin;
  6: 
  7: use App\Http\Controllers\Controller;
  8: use App\Http\Requests\Admin\Category\ReorderCategoriesRequest;
  9: use App\Http\Requests\Admin\Category\StoreCategoryRequest;
 10: use App\Http\Requests\Admin\Category\UpdateCategoryRequest;
 11: use App\Http\Resources\Admin\CategoryResource;
 12: use App\Models\Category;
 13: use App\Services\Catalog\CategoryService;
 14: use Illuminate\Http\JsonResponse;
 15: use Illuminate\Http\Request;
 16: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 17: use Illuminate\Routing\Controllers\HasMiddleware;
 18: use Illuminate\Routing\Controllers\Middleware;
 19: 
 20: class CategoryController extends Controller implements HasMiddleware
 21: {
 22:     public function __construct(private CategoryService $service) {}
 23: 
 24:     public static function middleware(): array
 25:     {
 26:         return [
 27:             new Middleware('can:categories.view', only: ['index', 'show']),
 28:             new Middleware('can:categories.create', only: ['store']),
 29:             new Middleware('can:categories.update', only: ['update', 'reorder']),
 30:             new Middleware('can:categories.delete', only: ['destroy']),
 31:         ];
 32:     }
 33: 
 34:     public function index(Request $request): AnonymousResourceCollection
 35:     {
 36:         if ($request->boolean('tree')) {
 37:             return CategoryResource::collection($this->service->tree());
 38:         }
 39: 
 40:         $perPage = max(1, min((int) $request->input('per_page', 15), 100));
 41: 
 42:         $categories = Category::query()
 43:             ->with('parent', 'media')
 44:             ->withCount('products')
 45:             ->when($request->filled('search'), function ($query) use ($request): void {
 46:                 $search = $request->input('search');
 47:                 $query->where(function ($q) use ($search): void {
 48:                     $q->where('name_ar', 'like', "%{$search}%")
 49:                         ->orWhere('name_en', 'like', "%{$search}%");
 50:                 });
 51:             })
 52:             ->when($request->has('parent_id'), function ($query) use ($request): void {
 53:                 if ($request->input('parent_id') === 'root') {
 54:                     $query->whereNull('parent_id');
 55:                 } else {
 56:                     $query->where('parent_id', $request->input('parent_id'));
 57:                 }
 58:             })
 59:             ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
 60:             ->orderBy('sort_order')
 61:             ->orderBy('id')
 62:             ->paginate($perPage)
 63:             ->withQueryString();
 64: 
 65:         return CategoryResource::collection($categories);
 66:     }
 67: 
 68:     public function show(Category $category): CategoryResource
 69:     {
 70:         $category->load('parent', 'children', 'media')->loadCount('products');
 71: 
 72:         return CategoryResource::make($category);
 73:     }
 74: 
 75:     public function store(StoreCategoryRequest $request): JsonResponse
 76:     {
 77:         $category = $this->service->create($request->validated(), $request->file('image'));
 78:         $category->load('parent', 'media')->loadCount('products');
 79: 
 80:         return CategoryResource::make($category)->response()->setStatusCode(201);
 81:     }
 82: 
 83:     // ملحوظة: تحديث قسم مع صورة بيتبعت POST مع _method=PUT، لأن PHP لا يقرأ ملفات multipart مع PUT.
 84:     public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
 85:     {
 86:         $category = $this->service->update($category, $request->validated(), $request->file('image'));
 87:         $category->load('parent', 'media')->loadCount('products');
 88: 
 89:         return CategoryResource::make($category);
 90:     }
 91: 
 92:     public function destroy(Category $category): JsonResponse
 93:     {
 94:         $this->service->delete($category);
 95: 
 96:         return response()->json(['message' => 'تم الحذف']);
 97:     }
 98: 
 99:     public function reorder(ReorderCategoriesRequest $request): JsonResponse
100:     {
101:         $this->service->reorder($request->validated()['items']);
102: 
103:         return response()->json(['message' => 'تم الترتيب']);
104:     }
105: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductController.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Http\Controllers\Api\V1\Admin;
  6: 
  7: use App\Http\Controllers\Controller;
  8: use App\Http\Requests\Admin\Product\StoreProductRequest;
  9: use App\Http\Requests\Admin\Product\UpdateProductRequest;
 10: use App\Http\Resources\Admin\ProductResource;
 11: use App\Models\Category;
 12: use App\Models\Product;
 13: use App\Services\Catalog\ProductService;
 14: use Illuminate\Http\JsonResponse;
 15: use Illuminate\Http\Request;
 16: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
 17: use Illuminate\Routing\Controllers\HasMiddleware;
 18: use Illuminate\Routing\Controllers\Middleware;
 19: 
 20: class ProductController extends Controller implements HasMiddleware
 21: {
 22:     public function __construct(private ProductService $service) {}
 23: 
 24:     public static function middleware(): array
 25:     {
 26:         return [
 27:             new Middleware('can:products.view', only: ['index', 'show']),
 28:             new Middleware('can:products.create', only: ['store']),
 29:             new Middleware('can:products.update', only: ['update']),
 30:             new Middleware('can:products.delete', only: ['destroy']),
 31:         ];
 32:     }
 33: 
 34:     public function index(Request $request): AnonymousResourceCollection
 35:     {
 36:         $perPage = max(1, min((int) $request->input('per_page', 15), 100));
 37: 
 38:         $q = Product::query()
 39:             ->with(['category:id,name_ar,name_en', 'brand:id,name_ar,name_en', 'media'])
 40:             ->withCount('variants')
 41:             ->withMin('variants as min_retail_price', 'retail_price')
 42:             ->withMax('variants as max_retail_price', 'retail_price');
 43: 
 44:         if ($s = $request->search) {
 45:             $q->where(fn ($w) => $w->where('name_ar', 'like', "%$s%")->orWhere('name_en', 'like', "%$s%")
 46:                 ->orWhere('code', $s)
 47:                 ->orWhereHas('variants', fn ($v) => $v->where('sku', $s)->orWhere('barcode', $s)));
 48:         }
 49:         if ($request->filled('category_id')) {
 50:             $cat = Category::findOrFail($request->category_id);
 51:             $ids = $request->boolean('include_children') ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
 52:             $q->whereIn('category_id', $ids);
 53:         }
 54:         $q->when($request->brand_id, fn ($x, $v) => $x->where('brand_id', $v))
 55:             ->when($request->status, fn ($x, $v) => $x->where('status', $v))
 56:             ->when($request->sales_channel, fn ($x, $v) => $x->where('sales_channel', $v))
 57:             ->when($request->has('is_featured'), fn ($x) => $x->where('is_featured', $request->boolean('is_featured')));
 58: 
 59:         match ($request->input('sort', 'latest')) {
 60:             'oldest' => $q->oldest(),
 61:             'name' => $q->orderBy('name_en'),
 62:             default => $q->latest(),
 63:         };
 64: 
 65:         return ProductResource::collection($q->paginate($perPage)->withQueryString());
 66:     }
 67: 
 68:     public function show(Product $product): ProductResource
 69:     {
 70:         $product->load(['category', 'brand', 'variants.media', 'media']);
 71: 
 72:         return ProductResource::make($product);
 73:     }
 74: 
 75:     public function store(StoreProductRequest $request): JsonResponse
 76:     {
 77:         $data = $request->validated();
 78:         if (! $request->user()->can('products.view_cost')) {
 79:             foreach ($data['variants'] ?? [] as $index => $variant) {
 80:                 unset($data['variants'][$index]['cost_price']);
 81:             }
 82:         }
 83:         $product = $this->service->create($data, $request->user());
 84: 
 85:         return ProductResource::make($product)->response()->setStatusCode(201);
 86:     }
 87: 
 88:     public function update(UpdateProductRequest $request, Product $product): ProductResource
 89:     {
 90:         $product = $this->service->update($product, $request->validated(), $request->user());
 91: 
 92:         return ProductResource::make($product);
 93:     }
 94: 
 95:     public function destroy(Product $product): JsonResponse
 96:     {
 97:         $this->service->delete($product);
 98: 
 99:         return response()->json(['message' => 'تم الحذف']);
100:     }
101: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductMediaController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Admin\Product\ReorderProductMediaRequest;
 9: use App\Http\Requests\Admin\Product\UploadProductMediaRequest;
10: use App\Http\Resources\MediaResource;
11: use App\Models\Product;
12: use App\Services\Catalog\ProductMediaService;
13: use Illuminate\Http\JsonResponse;
14: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
15: use Illuminate\Routing\Controllers\HasMiddleware;
16: use Illuminate\Routing\Controllers\Middleware;
17: use Spatie\MediaLibrary\MediaCollections\Models\Media;
18: 
19: class ProductMediaController extends Controller implements HasMiddleware
20: {
21:     public function __construct(private ProductMediaService $service) {}
22: 
23:     public static function middleware(): array
24:     {
25:         return [
26:             new Middleware('can:products.view', only: ['index']),
27:             new Middleware('can:products.update', only: ['store', 'reorder', 'destroy']),
28:         ];
29:     }
30: 
31:     public function index(Product $product): AnonymousResourceCollection
32:     {
33:         return MediaResource::collection($this->service->index($product));
34:     }
35: 
36:     public function store(UploadProductMediaRequest $request, Product $product): JsonResponse
37:     {
38:         $media = $this->service->upload($product, $request->validated(), $request->file('files', []), $request->file('file'));
39: 
40:         return MediaResource::collection($media)->response()->setStatusCode(201);
41:     }
42: 
43:     public function reorder(ReorderProductMediaRequest $request, Product $product): JsonResponse
44:     {
45:         $this->service->reorder($product, $request->validated('ids'));
46: 
47:         return response()->json(['message' => 'تم الترتيب']);
48:     }
49: 
50:     public function destroy(Product $product, Media $media): JsonResponse
51:     {
52:         $this->service->delete($product, $media);
53: 
54:         return response()->json(['message' => 'تم الحذف']);
55:     }
56: }
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductVariantController.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Controllers\Api\V1\Admin;
 6: 
 7: use App\Http\Controllers\Controller;
 8: use App\Http\Requests\Admin\Variant\StoreVariantRequest;
 9: use App\Http\Requests\Admin\Variant\UpdateVariantRequest;
10: use App\Http\Resources\Admin\VariantResource;
11: use App\Models\Product;
12: use App\Models\ProductVariant;
13: use App\Services\Catalog\VariantService;
14: use Illuminate\Http\JsonResponse;
15: use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
16: use Illuminate\Routing\Controllers\HasMiddleware;
17: use Illuminate\Routing\Controllers\Middleware;
18: 
19: class ProductVariantController extends Controller implements HasMiddleware
20: {
21:     public function __construct(private VariantService $service) {}
22: 
23:     public static function middleware(): array
24:     {
25:         return [
26:             new Middleware('can:products.view', only: ['index', 'show']),
27:             // تعديل المتغيرات معناه تعديل المنتج نفسه
28:             new Middleware('can:products.update', only: ['store', 'update', 'destroy']),
29:         ];
30:     }
31: 
32:     public function index(Product $product): AnonymousResourceCollection
33:     {
34:         return VariantResource::collection($product->variants()->with('media')->get());
35:     }
36: 
37:     public function store(StoreVariantRequest $request, Product $product): JsonResponse
38:     {
39:         $data = $request->validated();
40:         if (! $request->user()->can('products.view_cost')) {
41:             unset($data['cost_price']);
42:         }
43: 
44:         $variant = $this->service->create($product, $data);
45:         $variant->load('media');
46: 
47:         return VariantResource::make($variant)->response()->setStatusCode(201);
48:     }
49: 
50:     public function show(Product $product, ProductVariant $variant): VariantResource
51:     {
52:         $variant->load('media');
53: 
54:         return VariantResource::make($variant);
55:     }
56: 
57:     public function update(UpdateVariantRequest $request, Product $product, ProductVariant $variant): VariantResource
58:     {
59:         $data = $request->validated();
60:         if (! $request->user()->can('products.view_cost')) {
61:             unset($data['cost_price']);
62:         }
63: 
64:         $variant = $this->service->update($variant, $data);
65: 
66:         return VariantResource::make($variant);
67:     }
68: 
69:     public function destroy(Product $product, ProductVariant $variant): JsonResponse
70:     {
71:         $this->service->delete($variant);
72: 
73:         return response()->json(['message' => 'تم الحذف']);
74:     }
75: }
```

## File: bags-platform/backend/app/Http/Controllers/Controller.php
```php
1: <?php
2: 
3: namespace App\Http\Controllers;
4: 
5: abstract class Controller
6: {
7:     //
8: }
```

## File: bags-platform/backend/app/Http/Middleware/EnsureCustomerAuthenticated.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Models\Customer;
 9: use Closure;
10: use Illuminate\Http\Request;
11: use Illuminate\Support\Facades\Auth;
12: use Symfony\Component\HttpFoundation\Response;
13: 
14: class EnsureCustomerAuthenticated
15: {
16:     public function handle(Request $request, Closure $next): Response
17:     {
18:         Auth::shouldUse('customer');
19:         $user = $request->user('customer');
20: 
21:         if ($request->user('sanctum') === null) {
22:             return response()->json([
23:                 'message' => 'يرجى تسجيل الدخول أولًا.',
24:             ], 401);
25:         }
26: 
27:         if (! $user instanceof Customer || in_array($user->status, [CustomerStatus::Blocked, CustomerStatus::Rejected], true)) {
28:             return response()->json([
29:                 'message' => 'غير مصرح لك بالدخول كعميل.',
30:             ], 403);
31:         }
32: 
33:         return $next($request);
34:     }
35: }
```

## File: bags-platform/backend/app/Http/Middleware/EnsureStaffAuthenticated.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Models\User;
 8: use Closure;
 9: use Illuminate\Http\Request;
10: use Illuminate\Support\Facades\Auth;
11: use Symfony\Component\HttpFoundation\Response;
12: 
13: class EnsureStaffAuthenticated
14: {
15:     public function handle(Request $request, Closure $next): Response
16:     {
17:         Auth::shouldUse('staff');
18:         $user = $request->user('staff');
19: 
20:         if ($request->user('sanctum') === null) {
21:             return response()->json([
22:                 'message' => 'يرجى تسجيل الدخول أولًا.',
23:             ], 401);
24:         }
25: 
26:         if (! $user instanceof User || ! $user->is_active) {
27:             return response()->json([
28:                 'message' => 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.',
29:             ], 403);
30:         }
31: 
32:         return $next($request);
33:     }
34: }
```

## File: bags-platform/backend/app/Http/Middleware/EnsureSupplierAuthenticated.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Models\SupplierUser;
 8: use Closure;
 9: use Illuminate\Http\Request;
10: use Illuminate\Support\Facades\Auth;
11: use Symfony\Component\HttpFoundation\Response;
12: 
13: class EnsureSupplierAuthenticated
14: {
15:     public function handle(Request $request, Closure $next): Response
16:     {
17:         Auth::shouldUse('supplier');
18:         $user = $request->user('supplier');
19: 
20:         if ($request->user('sanctum') === null) {
21:             return response()->json([
22:                 'message' => 'يرجى تسجيل الدخول أولًا.',
23:             ], 401);
24:         }
25: 
26:         if (! $user instanceof SupplierUser || ! $user->is_active || $user->supplier_id === null) {
27:             return response()->json([
28:                 'message' => 'غير مصرح لك بالدخول كمستخدم مورد أو الحساب غير نشط.',
29:             ], 403);
30:         }
31: 
32:         return $next($request);
33:     }
34: }
```

## File: bags-platform/backend/app/Http/Middleware/EnsureWholesaleApproved.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Middleware;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Enums\CustomerType;
 9: use App\Models\Customer;
10: use Closure;
11: use Illuminate\Http\Request;
12: use Illuminate\Support\Facades\Auth;
13: use Symfony\Component\HttpFoundation\Response;
14: 
15: class EnsureWholesaleApproved
16: {
17:     public function handle(Request $request, Closure $next): Response
18:     {
19:         Auth::shouldUse('customer');
20:         $user = $request->user('customer');
21: 
22:         if ($request->user('sanctum') === null) {
23:             return response()->json([
24:                 'message' => 'يرجى تسجيل الدخول أولًا.',
25:             ], 401);
26:         }
27: 
28:         if (
29:             ! $user instanceof Customer
30:             || ! in_array($user->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
31:             || $user->status !== CustomerStatus::Approved
32:         ) {
33:             return response()->json([
34:                 'message' => 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.',
35:             ], 403);
36:         }
37: 
38:         return $next($request);
39:     }
40: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Brand/StoreBrandRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Brand;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Rule;
10: 
11: class StoreBrandRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'name_ar' => ['required', 'string', 'max:150'],
25:             'name_en' => ['required', 'string', 'max:150'],
26:             'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', Rule::unique('brands', 'slug')],
27:             'description_ar' => ['nullable', 'string'],
28:             'description_en' => ['nullable', 'string'],
29:             'sort_order' => ['nullable', 'integer', 'min:0'],
30:             'is_active' => ['nullable', 'boolean'],
31:             'logo' => ['nullable', 'image:allow_svg', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
32:         ];
33:     }
34: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Brand/UpdateBrandRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Brand;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Rule;
10: 
11: class UpdateBrandRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
25:             'name_en' => ['sometimes', 'required', 'string', 'max:150'],
26:             'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'alpha_dash', Rule::unique('brands', 'slug')->ignore($this->route('brand'))],
27:             'description_ar' => ['sometimes', 'nullable', 'string'],
28:             'description_en' => ['sometimes', 'nullable', 'string'],
29:             'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
30:             'is_active' => ['sometimes', 'nullable', 'boolean'],
31:             'logo' => ['sometimes', 'nullable', 'image:allow_svg', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
32:             'remove_logo' => ['sometimes', 'nullable', 'boolean'],
33:         ];
34:     }
35: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Category/ReorderCategoriesRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Category;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Rule;
10: 
11: class ReorderCategoriesRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'items' => ['required', 'array', 'min:1'],
25:             'items.*.id' => ['required', 'integer', 'distinct', Rule::exists('categories', 'id')->whereNull('deleted_at')],
26:             'items.*.parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
27:             'items.*.sort_order' => ['required', 'integer', 'min:0'],
28:         ];
29:     }
30: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Category/StoreCategoryRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Category;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Rule;
10: 
11: class StoreCategoryRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
25:             'name_ar' => ['required', 'string', 'max:150'],
26:             'name_en' => ['required', 'string', 'max:150'],
27:             'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', Rule::unique('categories', 'slug')],
28:             'description_ar' => ['nullable', 'string'],
29:             'description_en' => ['nullable', 'string'],
30:             'sort_order' => ['nullable', 'integer', 'min:0'],
31:             'is_active' => ['nullable', 'boolean'],
32:             'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
33:         ];
34:     }
35: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Category/UpdateCategoryRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Category;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Rule;
10: 
11: class UpdateCategoryRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
25:             'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
26:             'name_en' => ['sometimes', 'required', 'string', 'max:150'],
27:             'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($this->route('category'))],
28:             'description_ar' => ['sometimes', 'nullable', 'string'],
29:             'description_en' => ['sometimes', 'nullable', 'string'],
30:             'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
31:             'is_active' => ['sometimes', 'nullable', 'boolean'],
32:             'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
33:             'remove_image' => ['sometimes', 'nullable', 'boolean'],
34:         ];
35:     }
36: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Product/ReorderProductMediaRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Product;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: 
10: class ReorderProductMediaRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<mixed>|string|ValidationRule>
19:      */
20:     public function rules(): array
21:     {
22:         return [
23:             'ids' => ['required', 'array', 'min:1'],
24:             'ids.*' => ['integer'],
25:         ];
26:     }
27: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Product/StoreProductRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Product;
 6: 
 7: use App\Enums\ProductStatus;
 8: use App\Enums\SalesChannel;
 9: use App\Http\Requests\Admin\Variant\VariantRules;
10: use Illuminate\Contracts\Validation\ValidationRule;
11: use Illuminate\Foundation\Http\FormRequest;
12: use Illuminate\Validation\Rule;
13: 
14: class StoreProductRequest extends FormRequest
15: {
16:     public function authorize(): bool
17:     {
18:         return $this->user() !== null;
19:     }
20: 
21:     /**
22:      * @return array<string, array<mixed>|string|ValidationRule>
23:      */
24:     public function rules(): array
25:     {
26:         $rules = [
27:             'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
28:             'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
29:             'supplier_id' => ['nullable', 'integer'],
30:             'name_ar' => ['required', 'string', 'max:255'],
31:             'name_en' => ['required', 'string', 'max:255'],
32:             'slug' => ['nullable', 'alpha_dash', 'max:280', Rule::unique('products', 'slug')],
33:             'description_ar' => ['nullable', 'string'],
34:             'description_en' => ['nullable', 'string'],
35:             'material_ar' => ['nullable', 'string', 'max:150'],
36:             'material_en' => ['nullable', 'string', 'max:150'],
37:             'length_cm' => ['nullable', 'numeric', 'min:0'],
38:             'width_cm' => ['nullable', 'numeric', 'min:0'],
39:             'height_cm' => ['nullable', 'numeric', 'min:0'],
40:             'weight_kg' => ['nullable', 'numeric', 'min:0'],
41:             'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
42:             'warranty_details' => ['nullable', 'string', 'max:255'],
43:             'country_of_origin' => ['nullable', 'string', 'max:100'],
44:             'status' => ['nullable', Rule::enum(ProductStatus::class)],
45:             'sales_channel' => ['required', Rule::enum(SalesChannel::class)],
46:             'is_featured' => ['nullable', 'boolean'],
47:             // اختياري: متغيرات مع الإنشاء
48:             'variants' => ['nullable', 'array'],
49:             'variants.*' => ['array:'.implode(',', array_keys(VariantRules::base()))],
50:         ];
51: 
52:         foreach (VariantRules::base() as $field => $rule) {
53:             if (in_array($field, ['sku', 'barcode'], true)) {
54:                 $rule[] = 'distinct';
55:             }
56:             $rules["variants.*.$field"] = $rule;
57:         }
58: 
59:         return $rules;
60:     }
61: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Product/UpdateProductRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Product;
 6: 
 7: use App\Enums\ProductStatus;
 8: use App\Enums\SalesChannel;
 9: use Illuminate\Contracts\Validation\ValidationRule;
10: use Illuminate\Foundation\Http\FormRequest;
11: use Illuminate\Validation\Rule;
12: 
13: class UpdateProductRequest extends FormRequest
14: {
15:     public function authorize(): bool
16:     {
17:         return $this->user() !== null;
18:     }
19: 
20:     /**
21:      * @return array<string, array<mixed>|string|ValidationRule>
22:      */
23:     public function rules(): array
24:     {
25:         return [
26:             'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
27:             'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
28:             'supplier_id' => ['sometimes', 'nullable', 'integer'],
29:             'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
30:             'name_en' => ['sometimes', 'required', 'string', 'max:255'],
31:             'slug' => ['sometimes', 'nullable', 'alpha_dash', 'max:280', Rule::unique('products', 'slug')->ignore($this->route('product'))],
32:             'description_ar' => ['sometimes', 'nullable', 'string'],
33:             'description_en' => ['sometimes', 'nullable', 'string'],
34:             'material_ar' => ['sometimes', 'nullable', 'string', 'max:150'],
35:             'material_en' => ['sometimes', 'nullable', 'string', 'max:150'],
36:             'length_cm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
37:             'width_cm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
38:             'height_cm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
39:             'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
40:             'warranty_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
41:             'warranty_details' => ['sometimes', 'nullable', 'string', 'max:255'],
42:             'country_of_origin' => ['sometimes', 'nullable', 'string', 'max:100'],
43:             'status' => ['sometimes', 'nullable', Rule::enum(ProductStatus::class)],
44:             'sales_channel' => ['sometimes', 'required', Rule::enum(SalesChannel::class)],
45:             'is_featured' => ['sometimes', 'nullable', 'boolean'],
46:         ];
47:     }
48: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Product/UploadProductMediaRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Product;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: use Illuminate\Validation\Rule;
10: 
11: class UploadProductMediaRequest extends FormRequest
12: {
13:     public function authorize(): bool
14:     {
15:         return $this->user() !== null;
16:     }
17: 
18:     /**
19:      * @return array<string, array<mixed>|string|ValidationRule>
20:      */
21:     public function rules(): array
22:     {
23:         return [
24:             'collection' => ['required', Rule::in(['images', 'video'])],
25:             'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $this->route('product')->id)->whereNull('deleted_at')],
26:             'files' => ['required_if:collection,images', 'array', 'max:10'],
27:             'files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
28:             'file' => ['required_if:collection,video', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
29:         ];
30:     }
31: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Variant/StoreVariantRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Variant;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: 
10: class StoreVariantRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<mixed>|string|ValidationRule>
19:      */
20:     public function rules(): array
21:     {
22:         return VariantRules::base();
23:     }
24: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Variant/UpdateVariantRequest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Variant;
 6: 
 7: use Illuminate\Contracts\Validation\ValidationRule;
 8: use Illuminate\Foundation\Http\FormRequest;
 9: 
10: class UpdateVariantRequest extends FormRequest
11: {
12:     public function authorize(): bool
13:     {
14:         return $this->user() !== null;
15:     }
16: 
17:     /**
18:      * @return array<string, array<mixed>|string|ValidationRule>
19:      */
20:     public function rules(): array
21:     {
22:         $rules = VariantRules::base($this->route('variant')->id);
23: 
24:         foreach ($rules as $field => $rule) {
25:             if ($field === 'sku') {
26:                 $rule = array_values(array_filter($rule, fn ($value) => $value !== 'nullable'));
27:                 array_unshift($rule, 'required');
28:             }
29:             array_unshift($rule, 'sometimes');
30:             $rules[$field] = $rule;
31:         }
32: 
33:         return $rules;
34:     }
35: }
```

## File: bags-platform/backend/app/Http/Requests/Admin/Variant/VariantRules.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Requests\Admin\Variant;
 6: 
 7: use Illuminate\Validation\Rule;
 8: 
 9: // قواعد الـ validation المشتركة لمتغيرات المنتج — تُستخدم في إنشاء/تعديل المتغير وفي variants.* عند إنشاء منتج.
10: class VariantRules
11: {
12:     /**
13:      * @return array<string, array<mixed>>
14:      */
15:     public static function base(?int $ignoreId = null): array
16:     {
17:         return [
18:             'sku' => ['nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('product_variants', 'sku')->ignore($ignoreId)],
19:             'barcode' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z\-]+$/', Rule::unique('product_variants', 'barcode')->ignore($ignoreId)],
20:             'color_name_ar' => ['nullable', 'string', 'max:50'],
21:             'color_name_en' => ['nullable', 'string', 'max:50'],
22:             'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
23:             'size' => ['nullable', 'string', 'max:50'],
24:             'capacity' => ['nullable', 'string', 'max:50'],
25:             'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
26:             'retail_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
27:             'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
28:             'reorder_level' => ['nullable', 'integer', 'min:0'],
29:             'weight_kg' => ['nullable', 'numeric', 'min:0'],
30:             'sort_order' => ['nullable', 'integer', 'min:0'],
31:             'is_active' => ['nullable', 'boolean'],
32:         ];
33:     }
34: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/BrandResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class BrandResource extends JsonResource
11: {
12:     /**
13:      * Transform the resource into an array.
14:      *
15:      * @return array<string, mixed>
16:      */
17:     public function toArray(Request $request): array
18:     {
19:         return [
20:             'id' => $this->id,
21:             'name_ar' => $this->name_ar,
22:             'name_en' => $this->name_en,
23:             'slug' => $this->slug,
24:             'description_ar' => $this->description_ar,
25:             'description_en' => $this->description_en,
26:             'sort_order' => $this->sort_order,
27:             'is_active' => $this->is_active,
28:             'logo_url' => $this->getFirstMediaUrl('logo') ?: null,
29:             'products_count' => $this->whenCounted('products'),
30:             'created_at' => $this->created_at,
31:         ];
32:     }
33: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/CategoryResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Admin;
 6: 
 7: use Illuminate\Http\Request;
 8: use Illuminate\Http\Resources\Json\JsonResource;
 9: 
10: class CategoryResource extends JsonResource
11: {
12:     /**
13:      * Transform the resource into an array.
14:      *
15:      * @return array<string, mixed>
16:      */
17:     public function toArray(Request $request): array
18:     {
19:         return [
20:             'id' => $this->id,
21:             'parent_id' => $this->parent_id,
22:             'name_ar' => $this->name_ar,
23:             'name_en' => $this->name_en,
24:             'slug' => $this->slug,
25:             'description_ar' => $this->description_ar,
26:             'description_en' => $this->description_en,
27:             'sort_order' => $this->sort_order,
28:             'is_active' => $this->is_active,
29:             'image_url' => $this->getFirstMediaUrl('image') ?: null,
30:             'products_count' => $this->whenCounted('products'),
31:             'parent' => new CategoryResource($this->whenLoaded('parent')),
32:             'children' => CategoryResource::collection($this->whenLoaded('children')),
33:             'created_at' => $this->created_at,
34:         ];
35:     }
36: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/ProductResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Admin;
 6: 
 7: use App\Http\Resources\MediaResource;
 8: use Illuminate\Http\Request;
 9: use Illuminate\Http\Resources\Json\JsonResource;
10: 
11: class ProductResource extends JsonResource
12: {
13:     /**
14:      * Transform the resource into an array.
15:      *
16:      * @return array<string, mixed>
17:      */
18:     public function toArray(Request $request): array
19:     {
20:         return [
21:             'id' => $this->id,
22:             'code' => $this->code,
23:             'name_ar' => $this->name_ar, 'name_en' => $this->name_en, 'slug' => $this->slug,
24:             'description_ar' => $this->description_ar, 'description_en' => $this->description_en,
25:             'material_ar' => $this->material_ar, 'material_en' => $this->material_en,
26:             'dimensions' => ['length_cm' => $this->length_cm, 'width_cm' => $this->width_cm, 'height_cm' => $this->height_cm],
27:             'weight_kg' => $this->weight_kg,
28:             'warranty_months' => $this->warranty_months, 'warranty_details' => $this->warranty_details,
29:             'country_of_origin' => $this->country_of_origin,
30:             'status' => $this->status->value,
31:             'sales_channel' => $this->sales_channel->value,
32:             'is_featured' => $this->is_featured,
33:             'published_at' => $this->published_at,
34:             'category' => new CategoryResource($this->whenLoaded('category')),
35:             'brand' => new BrandResource($this->whenLoaded('brand')),
36:             'supplier_id' => $this->supplier_id,
37:             'main_image' => $this->getFirstMediaUrl('images', 'thumb') ?: null,
38:             'images' => MediaResource::collection($this->whenLoaded('media', fn () => $this->getMedia('images'))),
39:             'video' => $this->whenLoaded('media', fn () => ($v = $this->getFirstMedia('video')) ? new MediaResource($v) : null),
40:             'variants_count' => $this->whenCounted('variants'),
41:             'min_retail_price' => $this->when(isset($this->min_retail_price), $this->min_retail_price),
42:             'max_retail_price' => $this->when(isset($this->max_retail_price), $this->max_retail_price),
43:             'variants' => VariantResource::collection($this->whenLoaded('variants')),
44:             'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
45:         ];
46:     }
47: }
```

## File: bags-platform/backend/app/Http/Resources/Admin/VariantResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources\Admin;
 6: 
 7: use App\Http\Resources\MediaResource;
 8: use Illuminate\Http\Request;
 9: use Illuminate\Http\Resources\Json\JsonResource;
10: 
11: class VariantResource extends JsonResource
12: {
13:     /**
14:      * Transform the resource into an array.
15:      *
16:      * @return array<string, mixed>
17:      */
18:     public function toArray(Request $request): array
19:     {
20:         return [
21:             'id' => $this->id,
22:             'product_id' => $this->product_id,
23:             'sku' => $this->sku,
24:             'barcode' => $this->barcode,
25:             'color' => ['name_ar' => $this->color_name_ar, 'name_en' => $this->color_name_en, 'hex' => $this->color_hex],
26:             'size' => $this->size,
27:             'capacity' => $this->capacity,
28:             'cost_price' => $this->when($request->user()?->can('products.view_cost'), $this->cost_price),
29:             'retail_price' => $this->retail_price,
30:             'wholesale_price' => $this->wholesale_price,
31:             'reorder_level' => $this->reorder_level,
32:             'weight_kg' => $this->weight_kg ?? $this->product?->weight_kg,
33:             'sort_order' => $this->sort_order,
34:             'is_active' => $this->is_active,
35:             'images' => MediaResource::collection($this->whenLoaded('media', fn () => $this->getMedia('images'))),
36:         ];
37:     }
38: }
```

## File: bags-platform/backend/app/Http/Resources/MediaResource.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Http\Resources;
 6: 
 7: use App\Models\ProductVariant;
 8: use Illuminate\Http\Request;
 9: use Illuminate\Http\Resources\Json\JsonResource;
10: 
11: class MediaResource extends JsonResource
12: {
13:     /**
14:      * Transform the resource into an array.
15:      *
16:      * @return array<string, mixed>
17:      */
18:     public function toArray(Request $request): array
19:     {
20:         return [
21:             'id' => $this->id,
22:             'collection' => $this->collection_name,
23:             'url' => $this->getUrl(),
24:             'thumb_url' => $this->hasGeneratedConversion('thumb') ? $this->getUrl('thumb') : null,
25:             'medium_url' => $this->hasGeneratedConversion('medium') ? $this->getUrl('medium') : null,
26:             'mime_type' => $this->mime_type,
27:             'size' => $this->size,
28:             'order' => $this->order_column,
29:             'variant_id' => $this->model_type === ProductVariant::class ? $this->model_id : null,
30:         ];
31:     }
32: }
```

## File: bags-platform/backend/app/Models/Branch.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\BranchType;
 8: use Database\Factories\BranchFactory;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Database\Eloquent\Model;
12: use Illuminate\Database\Eloquent\Relations\HasMany;
13: 
14: #[Fillable(['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'is_active'])]
15: class Branch extends Model
16: {
17:     /** @use HasFactory<BranchFactory> */
18:     use HasFactory;
19: 
20:     public function locations(): HasMany
21:     {
22:         return $this->hasMany(Location::class);
23:     }
24: 
25:     public function users(): HasMany
26:     {
27:         return $this->hasMany(User::class);
28:     }
29: 
30:     /**
31:      * @return array<string, string>
32:      */
33:     protected function casts(): array
34:     {
35:         return [
36:             'type' => BranchType::class,
37:             'is_active' => 'boolean',
38:         ];
39:     }
40: }
```

## File: bags-platform/backend/app/Models/Brand.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Database\Factories\BrandFactory;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\HasMany;
11: use Illuminate\Database\Eloquent\SoftDeletes;
12: use Spatie\MediaLibrary\HasMedia;
13: use Spatie\MediaLibrary\InteractsWithMedia;
14: 
15: class Brand extends Model implements HasMedia
16: {
17:     /** @use HasFactory<BrandFactory> */
18:     use HasFactory, InteractsWithMedia, SoftDeletes;
19: 
20:     protected $fillable = ['name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order', 'is_active'];
21: 
22:     protected $casts = ['is_active' => 'boolean'];
23: 
24:     public function products(): HasMany
25:     {
26:         return $this->hasMany(Product::class);
27:     }
28: 
29:     public function registerMediaCollections(): void
30:     {
31:         $this->addMediaCollection('logo')->singleFile()
32:             ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']);
33:     }
34: }
```

## File: bags-platform/backend/app/Models/Category.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Database\Factories\CategoryFactory;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: use Illuminate\Database\Eloquent\Relations\HasMany;
12: use Illuminate\Database\Eloquent\SoftDeletes;
13: use Spatie\MediaLibrary\HasMedia;
14: use Spatie\MediaLibrary\InteractsWithMedia;
15: 
16: class Category extends Model implements HasMedia
17: {
18:     /** @use HasFactory<CategoryFactory> */
19:     use HasFactory, InteractsWithMedia, SoftDeletes;
20: 
21:     protected $fillable = ['parent_id', 'name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order', 'is_active'];
22: 
23:     protected $casts = ['is_active' => 'boolean'];
24: 
25:     public function parent(): BelongsTo
26:     {
27:         return $this->belongsTo(Category::class, 'parent_id');
28:     }
29: 
30:     public function children(): HasMany
31:     {
32:         return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
33:     }
34: 
35:     public function products(): HasMany
36:     {
37:         return $this->hasMany(Product::class);
38:     }
39: 
40:     public function registerMediaCollections(): void
41:     {
42:         $this->addMediaCollection('image')->singleFile()
43:             ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
44:     }
45: 
46:     // بيرجّع IDs كل الأقسام اللي تحته على أي مستوى
47:     public function descendantIds(): array
48:     {
49:         $ids = [];
50:         $queue = [$this->id];
51:         while ($queue) {
52:             $children = Category::whereIn('parent_id', $queue)->pluck('id')->all();
53:             $ids = array_merge($ids, $children);
54:             $queue = $children;
55:         }
56: 
57:         return $ids;
58:     }
59: 
60:     public function depth(): int
61:     {
62:         $depth = 1;
63:         $p = $this->parent;
64:         while ($p) {
65:             $depth++;
66:             $p = $p->parent;
67:         }
68: 
69:         return $depth;
70:     }
71: }
```

## File: bags-platform/backend/app/Models/Customer.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\CustomerStatus;
 8: use App\Enums\CustomerType;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Attributes\Hidden;
11: use Illuminate\Foundation\Auth\User as Authenticatable;
12: use Illuminate\Notifications\Notifiable;
13: use Laravel\Sanctum\HasApiTokens;
14: 
15: #[Fillable([
16:     'name',
17:     'email',
18:     'phone',
19:     'password',
20:     'type',
21:     'status',
22:     'tax_number',
23:     'commercial_register',
24: ])]
25: #[Hidden(['password', 'remember_token'])]
26: class Customer extends Authenticatable
27: {
28:     use HasApiTokens, Notifiable;
29: 
30:     protected function casts(): array
31:     {
32:         return [
33:             'password' => 'hashed',
34:             'type' => CustomerType::class,
35:             'status' => CustomerStatus::class,
36:         ];
37:     }
38: }
```

## File: bags-platform/backend/app/Models/Location.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\LocationType;
 8: use Database\Factories\LocationFactory;
 9: use Illuminate\Database\Eloquent\Attributes\Fillable;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Database\Eloquent\Model;
12: use Illuminate\Database\Eloquent\Relations\BelongsTo;
13: 
14: #[Fillable(['branch_id', 'name', 'code', 'type', 'is_active'])]
15: class Location extends Model
16: {
17:     /** @use HasFactory<LocationFactory> */
18:     use HasFactory;
19: 
20:     public function branch(): BelongsTo
21:     {
22:         return $this->belongsTo(Branch::class);
23:     }
24: 
25:     /**
26:      * @return array<string, string>
27:      */
28:     protected function casts(): array
29:     {
30:         return [
31:             'type' => LocationType::class,
32:             'is_active' => 'boolean',
33:         ];
34:     }
35: }
```

## File: bags-platform/backend/app/Models/Product.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use App\Enums\ProductStatus;
 8: use App\Enums\SalesChannel;
 9: use Database\Factories\ProductFactory;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Database\Eloquent\Model;
12: use Illuminate\Database\Eloquent\Relations\BelongsTo;
13: use Illuminate\Database\Eloquent\Relations\HasMany;
14: use Illuminate\Database\Eloquent\SoftDeletes;
15: use Spatie\Image\Enums\Fit;
16: use Spatie\MediaLibrary\HasMedia;
17: use Spatie\MediaLibrary\InteractsWithMedia;
18: use Spatie\MediaLibrary\MediaCollections\Models\Media;
19: 
20: class Product extends Model implements HasMedia
21: {
22:     /** @use HasFactory<ProductFactory> */
23:     use HasFactory, InteractsWithMedia, SoftDeletes;
24: 
25:     protected $fillable = [
26:         'code', 'category_id', 'brand_id', 'supplier_id', 'name_ar', 'name_en', 'slug',
27:         'description_ar', 'description_en', 'material_ar', 'material_en',
28:         'length_cm', 'width_cm', 'height_cm', 'weight_kg', 'warranty_months', 'warranty_details',
29:         'country_of_origin', 'status', 'sales_channel', 'is_featured', 'published_at', 'created_by',
30:     ];
31: 
32:     protected $casts = [
33:         'status' => ProductStatus::class,
34:         'sales_channel' => SalesChannel::class,
35:         'is_featured' => 'boolean',
36:         'published_at' => 'datetime',
37:         'length_cm' => 'decimal:2', 'width_cm' => 'decimal:2', 'height_cm' => 'decimal:2',
38:         'weight_kg' => 'decimal:3',
39:     ];
40: 
41:     public function category(): BelongsTo
42:     {
43:         return $this->belongsTo(Category::class);
44:     }
45: 
46:     public function brand(): BelongsTo
47:     {
48:         return $this->belongsTo(Brand::class);
49:     }
50: 
51:     public function variants(): HasMany
52:     {
53:         return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
54:     }
55: 
56:     public function registerMediaCollections(): void
57:     {
58:         $this->addMediaCollection('images')
59:             ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
60:         $this->addMediaCollection('video')->singleFile()
61:             ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
62:     }
63: 
64:     public function registerMediaConversions(?Media $media = null): void
65:     {
66:         $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
67:             ->performOnCollections('images')->nonQueued();
68:         $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
69:             ->performOnCollections('images')->nonQueued();
70:     }
71: }
```

## File: bags-platform/backend/app/Models/ProductVariant.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Database\Factories\ProductVariantFactory;
 8: use Illuminate\Database\Eloquent\Factories\HasFactory;
 9: use Illuminate\Database\Eloquent\Model;
10: use Illuminate\Database\Eloquent\Relations\BelongsTo;
11: use Illuminate\Database\Eloquent\SoftDeletes;
12: use Spatie\Image\Enums\Fit;
13: use Spatie\MediaLibrary\HasMedia;
14: use Spatie\MediaLibrary\InteractsWithMedia;
15: use Spatie\MediaLibrary\MediaCollections\Models\Media;
16: 
17: class ProductVariant extends Model implements HasMedia
18: {
19:     /** @use HasFactory<ProductVariantFactory> */
20:     use HasFactory, InteractsWithMedia, SoftDeletes;
21: 
22:     protected $fillable = [
23:         'product_id', 'sku', 'barcode', 'color_name_ar', 'color_name_en', 'color_hex', 'size', 'capacity',
24:         'cost_price', 'retail_price', 'wholesale_price', 'reorder_level', 'weight_kg', 'sort_order', 'is_active',
25:     ];
26: 
27:     protected $casts = [
28:         'cost_price' => 'decimal:2', 'retail_price' => 'decimal:2', 'wholesale_price' => 'decimal:2',
29:         'weight_kg' => 'decimal:3', 'is_active' => 'boolean',
30:     ];
31: 
32:     public function product(): BelongsTo
33:     {
34:         return $this->belongsTo(Product::class);
35:     }
36: 
37:     // صور خاصة باللون ده
38:     public function registerMediaCollections(): void
39:     {
40:         $this->addMediaCollection('images')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
41:     }
42: 
43:     public function registerMediaConversions(?Media $media = null): void
44:     {
45:         $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')->nonQueued();
46:         $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')->nonQueued();
47:     }
48: }
```

## File: bags-platform/backend/app/Models/Setting.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Model;
 8: 
 9: class Setting extends Model
10: {
11:     /** @var list<string> */
12:     protected $fillable = [
13:         'group',
14:         'key',
15:         'value',
16:         'type',
17:     ];
18: }
```

## File: bags-platform/backend/app/Models/SupplierUser.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Models;
 6: 
 7: use Illuminate\Database\Eloquent\Attributes\Fillable;
 8: use Illuminate\Database\Eloquent\Attributes\Hidden;
 9: use Illuminate\Foundation\Auth\User as Authenticatable;
10: use Illuminate\Notifications\Notifiable;
11: use Laravel\Sanctum\HasApiTokens;
12: 
13: #[Fillable(['supplier_id', 'name', 'email', 'phone', 'password', 'is_active'])]
14: #[Hidden(['password', 'remember_token'])]
15: class SupplierUser extends Authenticatable
16: {
17:     use HasApiTokens, Notifiable;
18: 
19:     protected function casts(): array
20:     {
21:         return [
22:             'password' => 'hashed',
23:             'is_active' => 'boolean',
24:         ];
25:     }
26: }
```

## File: bags-platform/backend/app/Models/User.php
```php
 1: <?php
 2: 
 3: namespace App\Models;
 4: 
 5: use Illuminate\Database\Eloquent\Relations\BelongsTo;
 6: // use Illuminate\Contracts\Auth\MustVerifyEmail;
 7: use Database\Factories\UserFactory;
 8: use Illuminate\Database\Eloquent\Attributes\Fillable;
 9: use Illuminate\Database\Eloquent\Attributes\Hidden;
10: use Illuminate\Database\Eloquent\Factories\HasFactory;
11: use Illuminate\Foundation\Auth\User as Authenticatable;
12: use Illuminate\Notifications\Notifiable;
13: use Laravel\Sanctum\HasApiTokens;
14: use Spatie\Permission\Traits\HasRoles;
15: 
16: #[Fillable(['name', 'email', 'phone', 'password', 'branch_id', 'is_active'])]
17: #[Hidden(['password', 'remember_token'])]
18: class User extends Authenticatable
19: {
20:     /** @use HasFactory<UserFactory> */
21:     use HasApiTokens, HasFactory, HasRoles, Notifiable;
22: 
23:     protected string $guard_name = 'staff';
24: 
25:     public function branch(): BelongsTo
26:     {
27:         return $this->belongsTo(Branch::class);
28:     }
29: 
30:     /**
31:      * Get the attributes that should be cast.
32:      *
33:      * @return array<string, string>
34:      */
35:     protected function casts(): array
36:     {
37:         return [
38:             'email_verified_at' => 'datetime',
39:             'password' => 'hashed',
40:             'is_active' => 'boolean',
41:         ];
42:     }
43: }
```

## File: bags-platform/backend/app/Modules/Catalog/Routes/api.php
```php
1: <?php
2: 
3: declare(strict_types=1);
```

## File: bags-platform/backend/app/Modules/Catalog/Routes/web.php
```php
1: <?php
2: 
3: declare(strict_types=1);
```

## File: bags-platform/backend/app/Modules/Catalog/CatalogServiceProvider.php
```php
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace App\Modules\Catalog;
6: 
7: use Illuminate\Support\ServiceProvider;
8: 
9: class CatalogServiceProvider extends ServiceProvider {}
```

## File: bags-platform/backend/app/Modules/Products/Routes/api.php
```php
1: <?php
2: 
3: declare(strict_types=1);
```

## File: bags-platform/backend/app/Modules/Products/Routes/web.php
```php
1: <?php
2: 
3: declare(strict_types=1);
```

## File: bags-platform/backend/app/Modules/Products/ProductsServiceProvider.php
```php
1: <?php
2: 
3: declare(strict_types=1);
4: 
5: namespace App\Modules\Products;
6: 
7: use Illuminate\Support\ServiceProvider;
8: 
9: class ProductsServiceProvider extends ServiceProvider {}
```

## File: bags-platform/backend/app/Providers/AppServiceProvider.php
```php
 1: <?php
 2: 
 3: namespace App\Providers;
 4: 
 5: use Illuminate\Cache\RateLimiting\Limit;
 6: use Illuminate\Http\Request;
 7: use Illuminate\Support\Facades\RateLimiter;
 8: use Illuminate\Support\ServiceProvider;
 9: use Illuminate\Support\Str;
10: 
11: class AppServiceProvider extends ServiceProvider
12: {
13:     /**
14:      * Register any application services.
15:      */
16:     public function register(): void
17:     {
18:         //
19:     }
20: 
21:     /**
22:      * Bootstrap any application services.
23:      */
24:     public function boot(): void
25:     {
26:         RateLimiter::for('admin-login', function (Request $request): array {
27:             $email = $request->input('email');
28:             $normalizedEmail = is_string($email)
29:                 ? Str::transliterate(Str::lower(trim($email)))
30:                 : '';
31:             $emailKey = hash('sha256', $normalizedEmail);
32: 
33:             return [
34:                 Limit::perMinute(5)->by('admin-login-email:'.$emailKey),
35:                 Limit::perHour(30)->by('admin-login-email-hour:'.$emailKey),
36:                 Limit::perMinute(120)->by('admin-login-ip:'.($request->ip() ?? 'unknown')),
37:             ];
38:         });
39:     }
40: }
```

## File: bags-platform/backend/app/Services/Catalog/BrandService.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services\Catalog;
 6: 
 7: use App\Exceptions\BusinessException;
 8: use App\Models\Brand;
 9: use App\Services\SlugService;
10: use Illuminate\Http\UploadedFile;
11: use Illuminate\Support\Facades\DB;
12: 
13: class BrandService
14: {
15:     public function create(array $data, ?UploadedFile $logo): Brand
16:     {
17:         return DB::transaction(function () use ($data, $logo) {
18:             unset($data['logo']);
19:             $data = array_filter($data, fn ($value) => $value !== null);
20: 
21:             if (empty($data['slug'])) {
22:                 $data['slug'] = SlugService::make(Brand::class, $data['name_en']);
23:             }
24: 
25:             $brand = Brand::create($data);
26: 
27:             if ($logo) {
28:                 $brand->addMedia($logo)->toMediaCollection('logo');
29:             }
30: 
31:             return $brand;
32:         });
33:     }
34: 
35:     public function update(Brand $brand, array $data, ?UploadedFile $logo): Brand
36:     {
37:         return DB::transaction(function () use ($brand, $data, $logo) {
38:             $removeLogo = (bool) ($data['remove_logo'] ?? false);
39:             unset($data['logo'], $data['remove_logo']);
40: 
41:             if (array_key_exists('slug', $data) && empty($data['slug'])) {
42:                 $data['slug'] = SlugService::make(Brand::class, $data['name_en'] ?? $brand->name_en, $brand->id);
43:             }
44: 
45:             foreach (['sort_order', 'is_active'] as $field) {
46:                 if (array_key_exists($field, $data) && $data[$field] === null) {
47:                     unset($data[$field]);
48:                 }
49:             }
50: 
51:             $brand->update($data);
52: 
53:             if ($removeLogo) {
54:                 $brand->clearMediaCollection('logo');
55:             }
56: 
57:             if ($logo) {
58:                 $brand->addMedia($logo)->toMediaCollection('logo');
59:             }
60: 
61:             return $brand;
62:         });
63:     }
64: 
65:     public function delete(Brand $brand): void
66:     {
67:         if ($brand->products()->exists()) {
68:             throw new BusinessException('لا يمكن حذف براند مرتبط بمنتجات');
69:         }
70: 
71:         $brand->delete();
72:     }
73: }
```

## File: bags-platform/backend/app/Services/Catalog/CategoryService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services\Catalog;
  6: 
  7: use App\Exceptions\BusinessException;
  8: use App\Models\Category;
  9: use App\Services\SlugService;
 10: use Illuminate\Http\UploadedFile;
 11: use Illuminate\Support\Collection;
 12: use Illuminate\Support\Facades\DB;
 13: 
 14: class CategoryService
 15: {
 16:     public function create(array $data, ?UploadedFile $image): Category
 17:     {
 18:         return DB::transaction(function () use ($data, $image) {
 19:             unset($data['image']);
 20:             $data = array_filter($data, fn ($value) => $value !== null);
 21: 
 22:             if (empty($data['slug'])) {
 23:                 $data['slug'] = SlugService::make(Category::class, $data['name_en']);
 24:             }
 25: 
 26:             if (! empty($data['parent_id'])) {
 27:                 $parent = Category::findOrFail($data['parent_id']);
 28:                 if ($parent->depth() >= 3) {
 29:                     throw new BusinessException('أقصى عمق للأقسام 3 مستويات');
 30:                 }
 31:             }
 32: 
 33:             $category = Category::create($data);
 34: 
 35:             if ($image) {
 36:                 $category->addMedia($image)->toMediaCollection('image');
 37:             }
 38: 
 39:             return $category;
 40:         });
 41:     }
 42: 
 43:     public function update(Category $category, array $data, ?UploadedFile $image): Category
 44:     {
 45:         return DB::transaction(function () use ($category, $data, $image) {
 46:             $removeImage = (bool) ($data['remove_image'] ?? false);
 47:             unset($data['image'], $data['remove_image']);
 48: 
 49:             if (array_key_exists('parent_id', $data)) {
 50:                 $newParentId = $data['parent_id'] === null ? null : (int) $data['parent_id'];
 51:                 if ($newParentId !== $category->parent_id) {
 52:                     $this->assertValidParent($category, $newParentId);
 53:                 }
 54:             }
 55: 
 56:             if (array_key_exists('slug', $data) && empty($data['slug'])) {
 57:                 $data['slug'] = SlugService::make(Category::class, $data['name_en'] ?? $category->name_en, $category->id);
 58:             }
 59: 
 60:             foreach (['sort_order', 'is_active'] as $field) {
 61:                 if (array_key_exists($field, $data) && $data[$field] === null) {
 62:                     unset($data[$field]);
 63:                 }
 64:             }
 65: 
 66:             $category->update($data);
 67: 
 68:             if ($removeImage) {
 69:                 $category->clearMediaCollection('image');
 70:             }
 71: 
 72:             if ($image) {
 73:                 $category->addMedia($image)->toMediaCollection('image');
 74:             }
 75: 
 76:             return $category;
 77:         });
 78:     }
 79: 
 80:     public function delete(Category $category): void
 81:     {
 82:         if ($category->children()->exists()) {
 83:             throw new BusinessException('لا يمكن حذف قسم يحتوي على أقسام فرعية');
 84:         }
 85: 
 86:         if ($category->products()->exists()) {
 87:             throw new BusinessException('لا يمكن حذف قسم مرتبط بمنتجات');
 88:         }
 89: 
 90:         $category->delete();
 91:     }
 92: 
 93:     public function reorder(array $items): void
 94:     {
 95:         DB::transaction(function () use ($items): void {
 96:             foreach ($items as $item) {
 97:                 $category = Category::findOrFail($item['id']);
 98:                 $parentId = isset($item['parent_id']) ? (int) $item['parent_id'] : null;
 99:                 $this->assertValidParent($category, $parentId);
100:                 $category->update(['parent_id' => $parentId, 'sort_order' => $item['sort_order']]);
101:             }
102:         });
103:     }
104: 
105:     public function tree(bool $onlyActive = false): Collection
106:     {
107:         $all = Category::query()->with('media')->withCount('products')
108:             ->when($onlyActive, fn ($q) => $q->where('is_active', true))
109:             ->orderBy('sort_order')->get();
110:         $grouped = $all->groupBy('parent_id');
111:         $build = function ($parentId) use (&$build, $grouped) {
112:             return ($grouped[$parentId ?? ''] ?? collect())->map(function ($cat) use ($build) {
113:                 $cat->setRelation('children', $build($cat->id));
114: 
115:                 return $cat;
116:             })->values();
117:         };
118: 
119:         return $build(null);
120:     }
121: 
122:     public function assertValidParent(Category $category, ?int $parentId): void
123:     {
124:         if ($parentId === null) {
125:             return;
126:         }
127: 
128:         if ($parentId === $category->id) {
129:             throw new BusinessException('لا يمكن أن يكون القسم أبًا لنفسه');
130:         }
131: 
132:         if (in_array($parentId, $category->descendantIds(), true)) {
133:             throw new BusinessException('لا يمكن نقل القسم إلى أحد أقسامه الفرعية');
134:         }
135: 
136:         $parent = Category::findOrFail($parentId);
137: 
138:         if ($parent->depth() + $this->subtreeHeight($category) > 3) {
139:             throw new BusinessException('أقصى عمق للأقسام 3 مستويات');
140:         }
141:     }
142: 
143:     private function subtreeHeight(Category $category): int
144:     {
145:         $height = 1;
146:         foreach ($category->children as $child) {
147:             $height = max($height, 1 + $this->subtreeHeight($child));
148:         }
149: 
150:         return $height;
151:     }
152: }
```

## File: bags-platform/backend/app/Services/Catalog/ProductMediaService.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services\Catalog;
 6: 
 7: use App\Enums\ProductStatus;
 8: use App\Exceptions\BusinessException;
 9: use App\Models\Product;
10: use App\Models\ProductVariant;
11: use Illuminate\Http\UploadedFile;
12: use Illuminate\Support\Collection;
13: use Illuminate\Support\Facades\DB;
14: use Spatie\MediaLibrary\MediaCollections\Models\Media;
15: 
16: class ProductMediaService
17: {
18:     public function index(Product $product): Collection
19:     {
20:         $variantIds = $product->variants()->pluck('id');
21:         $media = Media::query()
22:             ->where(fn ($q) => $q->where(['model_type' => Product::class, 'model_id' => $product->id])
23:                 ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
24:             ->orderBy('order_column')->get();
25: 
26:         return $media;
27:     }
28: 
29:     public function upload(Product $product, array $data, array $files = [], ?UploadedFile $file = null): Collection
30:     {
31:         return DB::transaction(function () use ($product, $data, $files, $file) {
32:             $owner = ! empty($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : $product;
33:             if ($data['collection'] === 'video') {
34:                 if ($owner instanceof ProductVariant) {
35:                     throw new BusinessException('الفيديو للمنتج فقط');
36:                 }
37: 
38:                 return collect([$product->addMedia($file)->toMediaCollection('video')]);
39:             }
40: 
41:             return collect($files)->map(fn ($image) => $owner->addMedia($image)->toMediaCollection('images'));
42:         });
43:     }
44: 
45:     public function reorder(Product $product, array $ids): void
46:     {
47:         $this->assertMediaBelongs($product, $ids);
48:         DB::transaction(fn () => Media::setNewOrder($ids));
49:     }
50: 
51:     public function delete(Product $product, Media $media): void
52:     {
53:         $this->assertMediaBelongs($product, [$media->id]);
54:         if ($product->status === ProductStatus::Published && $media->collection_name === 'images'
55:             && $media->model_type === Product::class && $product->getMedia('images')->count() <= 1) {
56:             throw new BusinessException('لا يمكن حذف آخر صورة لمنتج منشور');
57:         }
58:         $media->delete();
59:     }
60: 
61:     private function assertMediaBelongs(Product $product, array $ids): void
62:     {
63:         $variantIds = $product->variants()->withTrashed()->pluck('id')->all();
64:         $count = Media::whereIn('id', $ids)->where(fn ($q) => $q
65:             ->where(fn ($w) => $w->where('model_type', Product::class)->where('model_id', $product->id))
66:             ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds))
67:         )->count();
68:         if ($count !== count(array_unique($ids))) {
69:             abort(404, 'ملف غير تابع لهذا المنتج');
70:         }
71:     }
72: }
```

## File: bags-platform/backend/app/Services/Catalog/ProductService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services\Catalog;
  6: 
  7: use App\Enums\ProductStatus;
  8: use App\Exceptions\BusinessException;
  9: use App\Models\Product;
 10: use App\Models\User;
 11: use App\Services\SlugService;
 12: use Illuminate\Support\Facades\DB;
 13: 
 14: class ProductService
 15: {
 16:     public function create(array $data, User $user): Product
 17:     {
 18:         return DB::transaction(function () use ($data, $user) {
 19:             $variants = $data['variants'] ?? [];
 20:             unset($data['variants']);
 21:             $data = array_filter($data, fn ($value) => $value !== null);
 22: 
 23:             $data['slug'] = $data['slug'] ?? SlugService::make(Product::class, $data['name_en']);
 24:             $data['created_by'] = $user->id;
 25:             $wantsPublish = ($data['status'] ?? 'draft') === ProductStatus::Published->value;
 26:             $data['status'] = ProductStatus::Draft->value; // دايمًا يتعمل draft الأول
 27: 
 28:             $product = Product::create($data);
 29:             $product->update(['code' => 'P'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)]);
 30: 
 31:             foreach ($variants as $v) {
 32:                 app(VariantService::class)->create($product, $v);
 33:             }
 34: 
 35:             if ($wantsPublish) {
 36:                 $this->changeStatus($product, ProductStatus::Published, $user);
 37:             }
 38: 
 39:             return $product->fresh(['category', 'brand', 'variants', 'media']);
 40:         });
 41:     }
 42: 
 43:     public function update(Product $product, array $data, User $user): Product
 44:     {
 45:         return DB::transaction(function () use ($product, $data, $user) {
 46:             $newStatus = isset($data['status']) ? ProductStatus::from($data['status']) : null;
 47:             unset($data['status']);
 48: 
 49:             if (array_key_exists('slug', $data) && empty($data['slug'])) {
 50:                 $data['slug'] = SlugService::make(Product::class, $data['name_en'] ?? $product->name_en, $product->id);
 51:             }
 52: 
 53:             if (array_key_exists('is_featured', $data) && $data['is_featured'] === null) {
 54:                 unset($data['is_featured']);
 55:             }
 56: 
 57:             $product->update($data);
 58: 
 59:             if ($newStatus && $newStatus !== $product->status) {
 60:                 $this->changeStatus($product, $newStatus, $user);
 61:             }
 62: 
 63:             return $product->fresh(['category', 'brand', 'variants', 'media']);
 64:         });
 65:     }
 66: 
 67:     public function changeStatus(Product $product, ProductStatus $status, User $user): void
 68:     {
 69:         if ($status === ProductStatus::Published) {
 70:             if (! $user->can('products.publish')) {
 71:                 throw new BusinessException('ليس لديك صلاحية نشر المنتجات', [], 403);
 72:             }
 73:             $this->assertPublishable($product);
 74:             $product->published_at ??= now();
 75:         }
 76:         $product->status = $status;
 77:         $product->save();
 78:     }
 79: 
 80:     public function assertPublishable(Product $product): void
 81:     {
 82:         $errors = [];
 83:         $active = $product->variants()->where('is_active', true)->get();
 84: 
 85:         if ($active->isEmpty()) {
 86:             $errors[] = 'يجب وجود متغير واحد نشط على الأقل';
 87:         }
 88:         if ($product->sales_channel->allowsRetail() && $active->contains(fn ($v) => ! $v->retail_price || $v->retail_price <= 0)) {
 89:             $errors[] = 'كل المتغيرات النشطة لازم يكون لها سعر قطاعي';
 90:         }
 91:         if ($product->sales_channel->allowsWholesale() && $active->contains(fn ($v) => ! $v->wholesale_price || $v->wholesale_price <= 0)) {
 92:             $errors[] = 'كل المتغيرات النشطة لازم يكون لها سعر جملة';
 93:         }
 94:         if ($product->getMedia('images')->isEmpty()) {
 95:             $errors[] = 'يجب رفع صورة واحدة على الأقل';
 96:         }
 97:         if (! $product->category?->is_active) {
 98:             $errors[] = 'القسم غير نشط';
 99:         }
100: 
101:         if ($errors) {
102:             throw new BusinessException('لا يمكن نشر المنتج', ['status' => $errors]);
103:         }
104:     }
105: 
106:     public function delete(Product $product): void
107:     {
108:         DB::transaction(function () use ($product): void {
109:             $product->variants()->delete();  // soft
110:             $product->delete();              // soft
111:         });
112:         // TODO مرحلة الطلبات: منع الحذف لو المنتج عليه طلبات، وبدل كده يتعمل archived
113:     }
114: }
```

## File: bags-platform/backend/app/Services/Catalog/VariantService.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services\Catalog;
 6: 
 7: use App\Enums\ProductStatus;
 8: use App\Exceptions\BusinessException;
 9: use App\Models\Product;
10: use App\Models\ProductVariant;
11: use App\Services\BarcodeGenerator;
12: use Illuminate\Support\Facades\DB;
13: 
14: class VariantService
15: {
16:     public function create(Product $product, array $data): ProductVariant
17:     {
18:         $data = array_filter($data, fn ($value) => $value !== null);
19: 
20:         return DB::transaction(function () use ($product, $data) {
21:             Product::whereKey($product->id)->lockForUpdate()->first(); // منع تكرار الترقيم
22:             $this->assertUniqueCombination($product, $data);
23: 
24:             if (empty($data['sku'])) {
25:                 $seq = $product->variants()->withTrashed()->count() + 1;
26:                 do {
27:                     $sku = $product->code.'-'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
28:                     $seq++;
29:                 } while (ProductVariant::withTrashed()->where('sku', $sku)->exists());
30:                 $data['sku'] = $sku;
31:             }
32: 
33:             $variant = $product->variants()->create($data);
34: 
35:             if (empty($variant->barcode)) {
36:                 $variant->update(['barcode' => BarcodeGenerator::fromId($variant->id)]);
37:             }
38: 
39:             return $variant;
40:         });
41:     }
42: 
43:     public function update(ProductVariant $variant, array $data): ProductVariant
44:     {
45:         foreach (['cost_price', 'reorder_level', 'sort_order', 'is_active'] as $field) {
46:             if (array_key_exists($field, $data) && $data[$field] === null) {
47:                 unset($data[$field]);
48:             }
49:         }
50: 
51:         return DB::transaction(function () use ($variant, $data) {
52:             $this->assertUniqueCombination($variant->product, array_merge($variant->only(['color_name_en', 'size', 'capacity']), $data), $variant->id);
53: 
54:             if (array_key_exists('is_active', $data) && ! $data['is_active']) {
55:                 $this->assertNotLastActiveOfPublished($variant);
56:             }
57:             $variant->update($data);
58: 
59:             return $variant->fresh('media');
60:         });
61:     }
62: 
63:     public function delete(ProductVariant $variant): void
64:     {
65:         $this->assertNotLastActiveOfPublished($variant);
66:         $variant->delete();
67:         // TODO مرحلة المخزون: منع الحذف لو عليه رصيد
68:     }
69: 
70:     private function assertUniqueCombination(Product $product, array $d, ?int $ignoreId = null): void
71:     {
72:         $exists = $product->variants()
73:             ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
74:             ->where(fn ($q) => isset($d['color_name_en']) ? $q->where('color_name_en', $d['color_name_en']) : $q->whereNull('color_name_en'))
75:             ->where(fn ($q) => isset($d['size']) ? $q->where('size', $d['size']) : $q->whereNull('size'))
76:             ->where(fn ($q) => isset($d['capacity']) ? $q->where('capacity', $d['capacity']) : $q->whereNull('capacity'))
77:             ->exists();
78:         if ($exists) {
79:             throw new BusinessException('يوجد متغير بنفس اللون والمقاس والسعة لهذا المنتج');
80:         }
81:     }
82: 
83:     private function assertNotLastActiveOfPublished(ProductVariant $variant): void
84:     {
85:         $product = $variant->product;
86:         if ($product->status === ProductStatus::Published
87:             && $product->variants()->where('is_active', true)->where('id', '!=', $variant->id)->doesntExist()) {
88:             throw new BusinessException('لا يمكن حذف/تعطيل آخر متغير نشط في منتج منشور');
89:         }
90:     }
91: }
```

## File: bags-platform/backend/app/Services/BarcodeGenerator.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services;
 6: 
 7: // بيولّد باركود EAN-13 داخلي. الباركود بيبدأ بـ 2، ودي البادئة المخصصة للاستخدام الداخلي.
 8: class BarcodeGenerator
 9: {
10:     public static function fromId(int $id): string
11:     {
12:         $base = '2'.str_pad((string) $id, 11, '0', STR_PAD_LEFT); // 12 رقم
13:         $sum = 0;
14:         for ($i = 0; $i < 12; $i++) {
15:             $d = (int) $base[$i];
16:             $sum += ($i % 2 === 0) ? $d : $d * 3;
17:         }
18: 
19:         return $base.((10 - ($sum % 10)) % 10);
20:     }
21: }
```

## File: bags-platform/backend/app/Services/SettingService.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace App\Services;
  6: 
  7: use App\Models\Setting;
  8: use Illuminate\Support\Facades\Cache;
  9: use Illuminate\Support\Facades\DB;
 10: use InvalidArgumentException;
 11: use JsonException;
 12: 
 13: class SettingService
 14: {
 15:     private const CACHE_KEY = 'app_settings_static';
 16: 
 17:     private const CACHE_TTL_SECONDS = 3600;
 18: 
 19:     /** @var list<string> */
 20:     private const NUMBERING_COUNTER_KEYS = [
 21:         'invoice_next_number',
 22:         'order_next_number',
 23:         'purchase_order_next_number',
 24:         'quotation_next_number',
 25:     ];
 26: 
 27:     /**
 28:      * Return all settings grouped by their group name, with values cast to their declared types.
 29:      * Numbering counters are read fresh because they change for every generated document number.
 30:      *
 31:      * @return array<string, array<string, mixed>>
 32:      */
 33:     public function getAllGrouped(): array
 34:     {
 35:         $grouped = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
 36:             return Setting::query()
 37:                 ->whereNotIn('key', self::NUMBERING_COUNTER_KEYS)
 38:                 ->orderBy('group')
 39:                 ->orderBy('key')
 40:                 ->get()
 41:                 ->groupBy('group')
 42:                 ->map(fn ($settings): array => $settings
 43:                     ->mapWithKeys(fn (Setting $setting): array => [
 44:                         $setting->key => $this->castValue($setting->value, $setting->type),
 45:                     ])
 46:                     ->all())
 47:                 ->all();
 48:         });
 49: 
 50:         $counters = Setting::query()
 51:             ->whereIn('key', self::NUMBERING_COUNTER_KEYS)
 52:             ->orderBy('key')
 53:             ->get()
 54:             ->mapWithKeys(fn (Setting $setting): array => [
 55:                 $setting->key => $this->castValue($setting->value, $setting->type),
 56:             ])
 57:             ->all();
 58: 
 59:         if ($counters !== []) {
 60:             $grouped['numbering'] = array_merge($grouped['numbering'] ?? [], $counters);
 61:             ksort($grouped['numbering']);
 62:         }
 63: 
 64:         return $grouped;
 65:     }
 66: 
 67:     public function get(string $key, mixed $default = null): mixed
 68:     {
 69:         foreach ($this->getAllGrouped() as $settings) {
 70:             if (array_key_exists($key, $settings)) {
 71:                 return $settings[$key];
 72:             }
 73:         }
 74: 
 75:         return $default;
 76:     }
 77: 
 78:     /**
 79:      * Update a flat key/value map atomically and invalidate the cached settings after commit.
 80:      *
 81:      * @param  array<string, mixed>  $settings
 82:      *
 83:      * @throws JsonException
 84:      */
 85:     public function updateBatch(array $settings): void
 86:     {
 87:         DB::transaction(function () use ($settings): void {
 88:             $lockedSettings = Setting::query()
 89:                 ->whereIn('key', array_keys($settings))
 90:                 ->orderBy('key')
 91:                 ->lockForUpdate()
 92:                 ->get()
 93:                 ->keyBy('key');
 94: 
 95:             $missingKeys = array_diff(array_keys($settings), $lockedSettings->keys()->all());
 96: 
 97:             if ($missingKeys !== []) {
 98:                 throw new InvalidArgumentException('Settings are not initialized: '.implode(', ', $missingKeys));
 99:             }
100: 
101:             foreach ($settings as $key => $value) {
102:                 /** @var Setting $setting */
103:                 $setting = $lockedSettings->get($key);
104: 
105:                 if (in_array($key, self::NUMBERING_COUNTER_KEYS, true) && is_numeric($value)) {
106:                     $value = max((int) $setting->value, (int) $value);
107:                 }
108: 
109:                 $setting->value = $value === null && str_ends_with($key, '_prefix')
110:                     ? ''
111:                     : $this->serializeValue($value, $setting->type);
112:                 $setting->save();
113:             }
114: 
115:             DB::afterCommit(fn () => $this->clearCache());
116:         }, attempts: 3);
117:     }
118: 
119:     /**
120:      * Atomically return and increment the next document number for the requested type.
121:      *
122:      * @throws JsonException
123:      */
124:     public function generateNextNumber(string $type): string
125:     {
126:         $definitions = [
127:             'invoice' => ['prefix' => 'invoice_prefix', 'next' => 'invoice_next_number', 'padding' => 'invoice_padding'],
128:             'order' => ['prefix' => 'order_prefix', 'next' => 'order_next_number', 'padding' => 'order_padding'],
129:             'purchase_order' => ['prefix' => 'purchase_order_prefix', 'next' => 'purchase_order_next_number', 'padding' => 'purchase_order_padding'],
130:             'quotation' => ['prefix' => 'quotation_prefix', 'next' => 'quotation_next_number', 'padding' => 'quotation_padding'],
131:         ];
132: 
133:         if (! isset($definitions[$type])) {
134:             throw new InvalidArgumentException("Unsupported numbering type [{$type}].");
135:         }
136: 
137:         $definition = $definitions[$type];
138:         $keys = array_values(array_filter([
139:             $definition['prefix'],
140:             $definition['next'],
141:             $definition['padding'],
142:         ]));
143: 
144:         return DB::transaction(function () use ($definition, $keys): string {
145:             $settings = Setting::query()
146:                 ->whereIn('key', $keys)
147:                 ->orderBy('key')
148:                 ->lockForUpdate()
149:                 ->get()
150:                 ->keyBy('key');
151: 
152:             foreach ($keys as $key) {
153:                 if (! $settings->has($key)) {
154:                     throw new InvalidArgumentException("Required numbering setting [{$key}] is missing.");
155:                 }
156:             }
157: 
158:             $prefix = (string) $settings->get($definition['prefix'])->value;
159:             $nextSetting = $settings->get($definition['next']);
160:             $nextValue = (int) $nextSetting->value;
161:             $padding = max(0, (int) $settings->get($definition['padding'])->value);
162: 
163:             $formatted = $prefix.str_pad((string) $nextValue, $padding, '0', STR_PAD_LEFT);
164:             $nextSetting->value = (string) ($nextValue + 1);
165:             $nextSetting->save();
166: 
167:             return $formatted;
168:         }, attempts: 3);
169:     }
170: 
171:     public function clearCache(): void
172:     {
173:         Cache::forget(self::CACHE_KEY);
174:     }
175: 
176:     private function castValue(?string $value, string $type): mixed
177:     {
178:         if ($value === null) {
179:             return null;
180:         }
181: 
182:         return match ($type) {
183:             'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
184:             'integer' => (int) $value,
185:             'float' => (float) $value,
186:             'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
187:             default => $value,
188:         };
189:     }
190: 
191:     /** @throws JsonException */
192:     private function serializeValue(mixed $value, string $type): ?string
193:     {
194:         if ($value === null) {
195:             return null;
196:         }
197: 
198:         if ($type === 'json') {
199:             return json_encode($value, JSON_THROW_ON_ERROR);
200:         }
201: 
202:         if ($type === 'boolean') {
203:             return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
204:         }
205: 
206:         return (string) $value;
207:     }
208: }
```

## File: bags-platform/backend/app/Services/SlugService.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace App\Services;
 6: 
 7: use Illuminate\Support\Str;
 8: 
 9: class SlugService
10: {
11:     public static function make(string $modelClass, string $source, ?int $ignoreId = null): string
12:     {
13:         $base = Str::slug($source) ?: Str::random(8);
14:         $slug = $base;
15:         $i = 2;
16:         while ($modelClass::withTrashed()->where('slug', $slug)
17:             ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
18:             $slug = "{$base}-{$i}";
19:             $i++;
20:         }
21: 
22:         return $slug;
23:     }
24: }
```

## File: bags-platform/backend/bootstrap/app.php
```php
 1: <?php
 2: 
 3: use App\Http\Middleware\EnsureCustomerAuthenticated;
 4: use App\Http\Middleware\EnsureStaffAuthenticated;
 5: use App\Http\Middleware\EnsureSupplierAuthenticated;
 6: use App\Http\Middleware\EnsureWholesaleApproved;
 7: use Illuminate\Foundation\Application;
 8: use Illuminate\Foundation\Configuration\Exceptions;
 9: use Illuminate\Foundation\Configuration\Middleware;
10: use Illuminate\Http\Request;
11: use Illuminate\Support\Facades\Route;
12: use Spatie\Permission\Middleware\PermissionMiddleware;
13: use Spatie\Permission\Middleware\RoleMiddleware;
14: 
15: return Application::configure(basePath: dirname(__DIR__))
16:     ->withRouting(
17:         web: __DIR__.'/../routes/web.php',
18:         commands: __DIR__.'/../routes/console.php',
19:         health: '/up',
20:         then: function (): void {
21:             Route::middleware('api')
22:                 ->prefix('api/v1/admin')
23:                 ->group(base_path('routes/api/admin.php'));
24:         },
25:     )
26:     ->withMiddleware(function (Middleware $middleware): void {
27:         $trustedProxies = env('TRUSTED_PROXIES');
28:         $middleware->trustProxies(
29:             at: is_string($trustedProxies) && trim($trustedProxies) !== ''
30:                 ? array_map('trim', explode(',', $trustedProxies))
31:                 : null,
32:         );
33: 
34:         $middleware->alias([
35:             'auth.staff' => EnsureStaffAuthenticated::class,
36:             'auth.customer' => EnsureCustomerAuthenticated::class,
37:             'auth.supplier' => EnsureSupplierAuthenticated::class,
38:             'wholesale.approved' => EnsureWholesaleApproved::class,
39:             'role' => RoleMiddleware::class,
40:             'permission' => PermissionMiddleware::class,
41:         ]);
42:     })
43:     ->withExceptions(function (Exceptions $exceptions): void {
44:         $exceptions->shouldRenderJsonWhen(
45:             fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
46:         );
47:     })->create();
```

## File: bags-platform/backend/bootstrap/providers.php
```php
1: <?php
2: 
3: use App\Providers\AppServiceProvider;
4: 
5: return [
6:     AppServiceProvider::class,
7: ];
```

## File: bags-platform/backend/config/app.php
```php
  1: <?php
  2: 
  3: return [
  4: 
  5:     /*
  6:     |--------------------------------------------------------------------------
  7:     | Application Name
  8:     |--------------------------------------------------------------------------
  9:     |
 10:     | This value is the name of your application, which will be used when the
 11:     | framework needs to place the application's name in a notification or
 12:     | other UI elements where an application name needs to be displayed.
 13:     |
 14:     */
 15: 
 16:     'name' => env('APP_NAME', 'Laravel'),
 17: 
 18:     /*
 19:     |--------------------------------------------------------------------------
 20:     | Application Environment
 21:     |--------------------------------------------------------------------------
 22:     |
 23:     | This value determines the "environment" your application is currently
 24:     | running in. This may determine how you prefer to configure various
 25:     | services the application utilizes. Set this in your ".env" file.
 26:     |
 27:     */
 28: 
 29:     'env' => env('APP_ENV', 'production'),
 30: 
 31:     /*
 32:     |--------------------------------------------------------------------------
 33:     | Application Debug Mode
 34:     |--------------------------------------------------------------------------
 35:     |
 36:     | When your application is in debug mode, detailed error messages with
 37:     | stack traces will be shown on every error that occurs within your
 38:     | application. If disabled, a simple generic error page is shown.
 39:     |
 40:     */
 41: 
 42:     'debug' => (bool) env('APP_DEBUG', false),
 43: 
 44:     /*
 45:     |--------------------------------------------------------------------------
 46:     | Application URL
 47:     |--------------------------------------------------------------------------
 48:     |
 49:     | This URL is used by the console to properly generate URLs when using
 50:     | the Artisan command line tool. You should set this to the root of
 51:     | the application so that it's available within Artisan commands.
 52:     |
 53:     */
 54: 
 55:     'url' => env('APP_URL', 'http://localhost'),
 56: 
 57:     /*
 58:     |--------------------------------------------------------------------------
 59:     | Application Timezone
 60:     |--------------------------------------------------------------------------
 61:     |
 62:     | Here you may specify the default timezone for your application, which
 63:     | will be used by the PHP date and date-time functions. The timezone
 64:     | is set to "UTC" by default as it is suitable for most use cases.
 65:     |
 66:     */
 67: 
 68:     'timezone' => 'UTC',
 69: 
 70:     /*
 71:     |--------------------------------------------------------------------------
 72:     | Application Locale Configuration
 73:     |--------------------------------------------------------------------------
 74:     |
 75:     | The application locale determines the default locale that will be used
 76:     | by Laravel's translation / localization methods. This option can be
 77:     | set to any locale for which you plan to have translation strings.
 78:     |
 79:     */
 80: 
 81:     'locale' => env('APP_LOCALE', 'en'),
 82: 
 83:     'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
 84: 
 85:     'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
 86: 
 87:     /*
 88:     |--------------------------------------------------------------------------
 89:     | Encryption Key
 90:     |--------------------------------------------------------------------------
 91:     |
 92:     | This key is utilized by Laravel's encryption services and should be set
 93:     | to a random, 32 character string to ensure that all encrypted values
 94:     | are secure. You should do this prior to deploying the application.
 95:     |
 96:     */
 97: 
 98:     'cipher' => 'AES-256-CBC',
 99: 
100:     'key' => env('APP_KEY'),
101: 
102:     'previous_keys' => [
103:         ...array_filter(
104:             explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
105:         ),
106:     ],
107: 
108:     /*
109:     |--------------------------------------------------------------------------
110:     | Maintenance Mode Driver
111:     |--------------------------------------------------------------------------
112:     |
113:     | These configuration options determine the driver used to determine and
114:     | manage Laravel's "maintenance mode" status. The "cache" driver will
115:     | allow maintenance mode to be controlled across multiple machines.
116:     |
117:     | Supported drivers: "file", "cache", "array"
118:     |
119:     */
120: 
121:     'maintenance' => [
122:         'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
123:         'store' => env('APP_MAINTENANCE_STORE', 'database'),
124:     ],
125: 
126: ];
```

## File: bags-platform/backend/config/auth.php
```php
  1: <?php
  2: 
  3: use App\Models\Customer;
  4: use App\Models\SupplierUser;
  5: use App\Models\User;
  6: 
  7: return [
  8: 
  9:     /*
 10:     |--------------------------------------------------------------------------
 11:     | Authentication Defaults
 12:     |--------------------------------------------------------------------------
 13:     |
 14:     | This option defines the default authentication "guard" and password
 15:     | reset "broker" for your application. You may change these values
 16:     | as required, but they're a perfect start for most applications.
 17:     |
 18:     */
 19: 
 20:     'defaults' => [
 21:         'guard' => env('AUTH_GUARD', 'web'),
 22:         'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
 23:     ],
 24: 
 25:     /*
 26:     |--------------------------------------------------------------------------
 27:     | Authentication Guards
 28:     |--------------------------------------------------------------------------
 29:     |
 30:     | Next, you may define every authentication guard for your application.
 31:     | Of course, a great default configuration has been defined for you
 32:     | which utilizes session storage plus the Eloquent user provider.
 33:     |
 34:     | All authentication guards have a user provider, which defines how the
 35:     | users are actually retrieved out of your database or other storage
 36:     | system used by the application. Typically, Eloquent is utilized.
 37:     |
 38:     | Supported: "session"
 39:     |
 40:     */
 41: 
 42:     'guards' => [
 43:         'web' => [
 44:             'driver' => 'session',
 45:             'provider' => 'users',
 46:         ],
 47:         'staff' => [
 48:             'driver' => 'sanctum',
 49:             'provider' => 'users',
 50:         ],
 51:         'customer' => [
 52:             'driver' => 'sanctum',
 53:             'provider' => 'customers',
 54:         ],
 55:         'supplier' => [
 56:             'driver' => 'sanctum',
 57:             'provider' => 'supplier_users',
 58:         ],
 59:     ],
 60: 
 61:     /*
 62:     |--------------------------------------------------------------------------
 63:     | User Providers
 64:     |--------------------------------------------------------------------------
 65:     |
 66:     | All authentication guards have a user provider, which defines how the
 67:     | users are actually retrieved out of your database or other storage
 68:     | system used by the application. Typically, Eloquent is utilized.
 69:     |
 70:     | If you have multiple user tables or models you may configure multiple
 71:     | providers to represent the model / table. These providers may then
 72:     | be assigned to any extra authentication guards you have defined.
 73:     |
 74:     | Supported: "database", "eloquent"
 75:     |
 76:     */
 77: 
 78:     'providers' => [
 79:         'users' => [
 80:             'driver' => 'eloquent',
 81:             'model' => env('AUTH_MODEL', User::class),
 82:         ],
 83: 
 84:         'customers' => [
 85:             'driver' => 'eloquent',
 86:             'model' => Customer::class,
 87:         ],
 88: 
 89:         'supplier_users' => [
 90:             'driver' => 'eloquent',
 91:             'model' => SupplierUser::class,
 92:         ],
 93: 
 94:         // 'users' => [
 95:         //     'driver' => 'database',
 96:         //     'table' => 'users',
 97:         // ],
 98:     ],
 99: 
100:     /*
101:     |--------------------------------------------------------------------------
102:     | Resetting Passwords
103:     |--------------------------------------------------------------------------
104:     |
105:     | These configuration options specify the behavior of Laravel's password
106:     | reset functionality, including the table utilized for token storage
107:     | and the user provider that is invoked to actually retrieve users.
108:     |
109:     | The expiry time is the number of minutes that each reset token will be
110:     | considered valid. This security feature keeps tokens short-lived so
111:     | they have less time to be guessed. You may change this as needed.
112:     |
113:     | The throttle setting is the number of seconds a user must wait before
114:     | generating more password reset tokens. This prevents the user from
115:     | quickly generating a very large amount of password reset tokens.
116:     |
117:     */
118: 
119:     'passwords' => [
120:         'users' => [
121:             'provider' => 'users',
122:             'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
123:             'expire' => 60,
124:             'throttle' => 60,
125:         ],
126:     ],
127: 
128:     /*
129:     |--------------------------------------------------------------------------
130:     | Password Confirmation Timeout
131:     |--------------------------------------------------------------------------
132:     |
133:     | Here you may define the number of seconds before a password confirmation
134:     | window expires and users are asked to re-enter their password via the
135:     | confirmation screen. By default, the timeout lasts for three hours.
136:     |
137:     */
138: 
139:     'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
140: 
141: ];
```

## File: bags-platform/backend/config/cache.php
```php
  1: <?php
  2: 
  3: use Illuminate\Support\Str;
  4: 
  5: return [
  6: 
  7:     /*
  8:     |--------------------------------------------------------------------------
  9:     | Default Cache Store
 10:     |--------------------------------------------------------------------------
 11:     |
 12:     | This option controls the default cache store that will be used by the
 13:     | framework. This connection is utilized if another isn't explicitly
 14:     | specified when running a cache operation inside the application.
 15:     |
 16:     */
 17: 
 18:     'default' => env('CACHE_STORE', 'database'),
 19: 
 20:     /*
 21:     |--------------------------------------------------------------------------
 22:     | Cache Stores
 23:     |--------------------------------------------------------------------------
 24:     |
 25:     | Here you may define all of the cache "stores" for your application as
 26:     | well as their drivers. You may even define multiple stores for the
 27:     | same cache driver to group types of items stored in your caches.
 28:     |
 29:     | Supported drivers: "array", "database", "file", "memcached",
 30:     |                    "redis", "dynamodb", "storage", "octane",
 31:     |                    "session", "failover", "null"
 32:     |
 33:     */
 34: 
 35:     'stores' => [
 36: 
 37:         'array' => [
 38:             'driver' => 'array',
 39:             'serialize' => false,
 40:         ],
 41: 
 42:         'database' => [
 43:             'driver' => 'database',
 44:             'connection' => env('DB_CACHE_CONNECTION'),
 45:             'table' => env('DB_CACHE_TABLE', 'cache'),
 46:             'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
 47:             'lock_table' => env('DB_CACHE_LOCK_TABLE'),
 48:         ],
 49: 
 50:         'file' => [
 51:             'driver' => 'file',
 52:             'path' => storage_path('framework/cache/data'),
 53:             'lock_path' => storage_path('framework/cache/locks'),
 54:         ],
 55: 
 56:         'storage' => [
 57:             'driver' => 'storage',
 58:             'disk' => env('CACHE_STORAGE_DISK'),
 59:             'path' => env('CACHE_STORAGE_PATH', 'framework/cache/data'),
 60:         ],
 61: 
 62:         'memcached' => [
 63:             'driver' => 'memcached',
 64:             'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
 65:             'sasl' => [
 66:                 env('MEMCACHED_USERNAME'),
 67:                 env('MEMCACHED_PASSWORD'),
 68:             ],
 69:             'options' => [
 70:                 // Memcached::OPT_CONNECT_TIMEOUT => 2000,
 71:             ],
 72:             'servers' => [
 73:                 [
 74:                     'host' => env('MEMCACHED_HOST', '127.0.0.1'),
 75:                     'port' => env('MEMCACHED_PORT', 11211),
 76:                     'weight' => 100,
 77:                 ],
 78:             ],
 79:         ],
 80: 
 81:         'redis' => [
 82:             'driver' => 'redis',
 83:             'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
 84:             'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
 85:         ],
 86: 
 87:         'dynamodb' => [
 88:             'driver' => 'dynamodb',
 89:             'key' => env('AWS_ACCESS_KEY_ID'),
 90:             'secret' => env('AWS_SECRET_ACCESS_KEY'),
 91:             'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
 92:             'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
 93:             'endpoint' => env('DYNAMODB_ENDPOINT'),
 94:         ],
 95: 
 96:         'octane' => [
 97:             'driver' => 'octane',
 98:         ],
 99: 
100:         'failover' => [
101:             'driver' => 'failover',
102:             'stores' => [
103:                 'database',
104:                 'array',
105:             ],
106:         ],
107: 
108:     ],
109: 
110:     /*
111:     |--------------------------------------------------------------------------
112:     | Cache Key Prefix
113:     |--------------------------------------------------------------------------
114:     |
115:     | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
116:     | stores, there might be other applications using the same cache. For
117:     | that reason, you may prefix every cache key to avoid collisions.
118:     |
119:     */
120: 
121:     'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),
122: 
123:     /*
124:     |--------------------------------------------------------------------------
125:     | Serializable Classes
126:     |--------------------------------------------------------------------------
127:     |
128:     | This value determines the classes that can be unserialized from cache
129:     | storage. By default, no PHP classes will be unserialized from your
130:     | cache to prevent gadget chain attacks if your APP_KEY is leaked.
131:     |
132:     */
133: 
134:     'serializable_classes' => false,
135: 
136: ];
```

## File: bags-platform/backend/config/database.php
```php
  1: <?php
  2: 
  3: use Illuminate\Support\Str;
  4: use Pdo\Mysql;
  5: 
  6: return [
  7: 
  8:     /*
  9:     |--------------------------------------------------------------------------
 10:     | Default Database Connection Name
 11:     |--------------------------------------------------------------------------
 12:     |
 13:     | Here you may specify which of the database connections below you wish
 14:     | to use as your default connection for database operations. This is
 15:     | the connection which will be utilized unless another connection
 16:     | is explicitly specified when you execute a query / statement.
 17:     |
 18:     */
 19: 
 20:     'default' => env('DB_CONNECTION', 'pgsql'),
 21: 
 22:     /*
 23:     |--------------------------------------------------------------------------
 24:     | Database Connections
 25:     |--------------------------------------------------------------------------
 26:     |
 27:     | Below are all of the database connections defined for your application.
 28:     | An example configuration is provided for each database system which
 29:     | is supported by Laravel. You're free to add / remove connections.
 30:     |
 31:     */
 32: 
 33:     'connections' => [
 34: 
 35:         'sqlite' => [
 36:             'driver' => 'sqlite',
 37:             'url' => env('DB_URL'),
 38:             'database' => env('DB_DATABASE', database_path('database.sqlite')),
 39:             'prefix' => '',
 40:             'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
 41:             'busy_timeout' => null,
 42:             'journal_mode' => null,
 43:             'synchronous' => null,
 44:             'transaction_mode' => 'DEFERRED',
 45:         ],
 46: 
 47:         'mysql' => [
 48:             'driver' => 'mysql',
 49:             'url' => env('DB_URL'),
 50:             'host' => env('DB_HOST', '127.0.0.1'),
 51:             'port' => env('DB_PORT', '3306'),
 52:             'database' => env('DB_DATABASE', 'laravel'),
 53:             'username' => env('DB_USERNAME', 'root'),
 54:             'password' => env('DB_PASSWORD', ''),
 55:             'unix_socket' => env('DB_SOCKET', ''),
 56:             'charset' => env('DB_CHARSET', 'utf8mb4'),
 57:             'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
 58:             'prefix' => '',
 59:             'prefix_indexes' => true,
 60:             'strict' => true,
 61:             'engine' => null,
 62:             'options' => extension_loaded('pdo_mysql') ? array_filter([
 63:                 Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
 64:             ]) : [],
 65:         ],
 66: 
 67:         'mariadb' => [
 68:             'driver' => 'mariadb',
 69:             'url' => env('DB_URL'),
 70:             'host' => env('DB_HOST', '127.0.0.1'),
 71:             'port' => env('DB_PORT', '3306'),
 72:             'database' => env('DB_DATABASE', 'laravel'),
 73:             'username' => env('DB_USERNAME', 'root'),
 74:             'password' => env('DB_PASSWORD', ''),
 75:             'unix_socket' => env('DB_SOCKET', ''),
 76:             'charset' => env('DB_CHARSET', 'utf8mb4'),
 77:             'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
 78:             'prefix' => '',
 79:             'prefix_indexes' => true,
 80:             'strict' => true,
 81:             'engine' => null,
 82:             'options' => extension_loaded('pdo_mysql') ? array_filter([
 83:                 Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
 84:             ]) : [],
 85:         ],
 86: 
 87:         'pgsql' => [
 88:             'driver' => 'pgsql',
 89:             'url' => env('DB_URL'),
 90:             'host' => env('DB_HOST', '127.0.0.1'),
 91:             'port' => env('DB_PORT', '5432'),
 92:             'database' => env('DB_DATABASE', 'alfarid'),
 93:             'username' => env('DB_USERNAME', 'postgres'),
 94:             'password' => env('DB_PASSWORD', ''),
 95:             'charset' => env('DB_CHARSET', 'utf8'),
 96:             'prefix' => '',
 97:             'prefix_indexes' => true,
 98:             'search_path' => 'public',
 99:             'sslmode' => env('DB_SSLMODE', 'prefer'),
100:         ],
101: 
102:         'sqlsrv' => [
103:             'driver' => 'sqlsrv',
104:             'url' => env('DB_URL'),
105:             'host' => env('DB_HOST', 'localhost'),
106:             'port' => env('DB_PORT', '1433'),
107:             'database' => env('DB_DATABASE', 'laravel'),
108:             'username' => env('DB_USERNAME', 'root'),
109:             'password' => env('DB_PASSWORD', ''),
110:             'charset' => env('DB_CHARSET', 'utf8'),
111:             'prefix' => '',
112:             'prefix_indexes' => true,
113:             // 'encrypt' => env('DB_ENCRYPT', 'yes'),
114:             // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
115:         ],
116: 
117:     ],
118: 
119:     /*
120:     |--------------------------------------------------------------------------
121:     | Migration Repository Table
122:     |--------------------------------------------------------------------------
123:     |
124:     | This table keeps track of all the migrations that have already run for
125:     | your application. Using this information, we can determine which of
126:     | the migrations on disk haven't actually been run on the database.
127:     |
128:     */
129: 
130:     'migrations' => [
131:         'table' => 'migrations',
132:         'update_date_on_publish' => true,
133:     ],
134: 
135:     /*
136:     |--------------------------------------------------------------------------
137:     | Redis Databases
138:     |--------------------------------------------------------------------------
139:     |
140:     | Redis is an open source, fast, and advanced key-value store that also
141:     | provides a richer body of commands than a typical key-value system
142:     | such as Memcached. You may define your connection settings here.
143:     |
144:     */
145: 
146:     'redis' => [
147: 
148:         'client' => env('REDIS_CLIENT', 'phpredis'),
149: 
150:         'options' => [
151:             'cluster' => env('REDIS_CLUSTER', 'redis'),
152:             'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
153:             'persistent' => env('REDIS_PERSISTENT', false),
154:         ],
155: 
156:         'default' => [
157:             'url' => env('REDIS_URL'),
158:             'host' => env('REDIS_HOST', '127.0.0.1'),
159:             'username' => env('REDIS_USERNAME'),
160:             'password' => env('REDIS_PASSWORD'),
161:             'port' => env('REDIS_PORT', '6379'),
162:             'database' => env('REDIS_DB', '0'),
163:             'max_retries' => env('REDIS_MAX_RETRIES', 3),
164:             'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
165:             'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
166:             'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
167:         ],
168: 
169:         'cache' => [
170:             'url' => env('REDIS_URL'),
171:             'host' => env('REDIS_HOST', '127.0.0.1'),
172:             'username' => env('REDIS_USERNAME'),
173:             'password' => env('REDIS_PASSWORD'),
174:             'port' => env('REDIS_PORT', '6379'),
175:             'database' => env('REDIS_CACHE_DB', '1'),
176:             'max_retries' => env('REDIS_MAX_RETRIES', 3),
177:             'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
178:             'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
179:             'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
180:         ],
181: 
182:     ],
183: 
184: ];
```

## File: bags-platform/backend/config/filesystems.php
```php
 1: <?php
 2: 
 3: return [
 4: 
 5:     /*
 6:     |--------------------------------------------------------------------------
 7:     | Default Filesystem Disk
 8:     |--------------------------------------------------------------------------
 9:     |
10:     | Here you may specify the default filesystem disk that should be used
11:     | by the framework. The "local" disk, as well as a variety of cloud
12:     | based disks are available to your application for file storage.
13:     |
14:     */
15: 
16:     'default' => env('FILESYSTEM_DISK', 'local'),
17: 
18:     /*
19:     |--------------------------------------------------------------------------
20:     | Filesystem Disks
21:     |--------------------------------------------------------------------------
22:     |
23:     | Below you may configure as many filesystem disks as necessary, and you
24:     | may even configure multiple disks for the same driver. Examples for
25:     | most supported storage drivers are configured here for reference.
26:     |
27:     | Supported drivers: "local", "ftp", "sftp", "s3"
28:     |
29:     */
30: 
31:     'disks' => [
32: 
33:         'local' => [
34:             'driver' => 'local',
35:             'root' => storage_path('app/private'),
36:             'serve' => true,
37:             'throw' => false,
38:             'report' => false,
39:         ],
40: 
41:         'public' => [
42:             'driver' => 'local',
43:             'root' => storage_path('app/public'),
44:             'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
45:             'visibility' => 'public',
46:             'throw' => false,
47:             'report' => false,
48:         ],
49: 
50:         's3' => [
51:             'driver' => 's3',
52:             'key' => env('AWS_ACCESS_KEY_ID'),
53:             'secret' => env('AWS_SECRET_ACCESS_KEY'),
54:             'region' => env('AWS_DEFAULT_REGION'),
55:             'bucket' => env('AWS_BUCKET'),
56:             'url' => env('AWS_URL'),
57:             'endpoint' => env('AWS_ENDPOINT'),
58:             'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
59:             'throw' => false,
60:             'report' => false,
61:         ],
62: 
63:     ],
64: 
65:     /*
66:     |--------------------------------------------------------------------------
67:     | Symbolic Links
68:     |--------------------------------------------------------------------------
69:     |
70:     | Here you may configure the symbolic links that will be created when the
71:     | `storage:link` Artisan command is executed. The array keys should be
72:     | the locations of the links and the values should be their targets.
73:     |
74:     */
75: 
76:     'links' => [
77:         public_path('storage') => storage_path('app/public'),
78:     ],
79: 
80: ];
```

## File: bags-platform/backend/config/media-library.php
```php
  1: <?php
  2: 
  3: use Spatie\ImageOptimizer\Optimizers\Avifenc;
  4: use Spatie\ImageOptimizer\Optimizers\Cwebp;
  5: use Spatie\ImageOptimizer\Optimizers\Gifsicle;
  6: use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
  7: use Spatie\ImageOptimizer\Optimizers\Optipng;
  8: use Spatie\ImageOptimizer\Optimizers\Pngquant;
  9: use Spatie\ImageOptimizer\Optimizers\Svgo;
 10: use Spatie\MediaLibrary\Conversions\ImageGenerators\Avif;
 11: use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
 12: use Spatie\MediaLibrary\Conversions\ImageGenerators\Pdf;
 13: use Spatie\MediaLibrary\Conversions\ImageGenerators\Svg;
 14: use Spatie\MediaLibrary\Conversions\ImageGenerators\Video;
 15: use Spatie\MediaLibrary\Conversions\ImageGenerators\Webp;
 16: use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
 17: use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
 18: use Spatie\MediaLibrary\MediaCollections\FileAdder;
 19: use Spatie\MediaLibrary\MediaCollections\Models\Media;
 20: use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
 21: use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;
 22: use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred;
 23: use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
 24: use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
 25: use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
 26: use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
 27: use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
 28: use Spatie\MediaLibraryPro\Models\TemporaryUpload;
 29: 
 30: return [
 31: 
 32:     /*
 33:      * The disk on which to store added files and derived images by default. Choose
 34:      * one or more of the disks you've configured in config/filesystems.php.
 35:      */
 36:     'disk_name' => env('MEDIA_DISK', 'public'),
 37: 
 38:     /*
 39:      * The disk on which to store conversions (thumbnails, etc.) and responsive images
 40:      * when no disk is specified explicitly on the media collection or via
 41:      * `storingConversionsOnDisk()`. When left null, conversions are stored on the
 42:      * same disk as the original media — preserving previous behavior.
 43:      *
 44:      * This is useful when the originals live on a remote disk (e.g. S3) but the
 45:      * generated derivatives should stay local for faster access and lower egress.
 46:      */
 47:     'conversions_disk_name' => env('MEDIA_CONVERSIONS_DISK', null),
 48: 
 49:     /*
 50:      * The maximum file size of an item in bytes.
 51:      * Adding a larger file will result in an exception.
 52:      */
 53:     'max_file_size' => 1024 * 1024 * 50, // 50MB
 54: 
 55:     /*
 56:      * Uploads whose file name contains any of these extensions will be rejected.
 57:      * The check looks at every extension in the file name, so a file named
 58:      * `malicious.php.jpg` is blocked as well. Matching is case-insensitive
 59:      * and a leading dot is optional.
 60:      *
 61:      * The default list lives on the `FileAdder` class so the shipped config
 62:      * and the in-code fallback (used when the config is cached without the
 63:      * key) cannot drift. Override here to extend or shrink it.
 64:      */
 65:     'disallowed_extensions' => FileAdder::$defaultDisallowedExtensions,
 66: 
 67:     /*
 68:      * When this is set to an array of extensions, only uploads whose final
 69:      * extension is in the list will be accepted. Matching is case-insensitive
 70:      * and a leading dot is optional. The `disallowed_extensions` list above
 71:      * is still enforced, so an interior dangerous segment (such as the `php`
 72:      * in `shell.php.jpg`) is rejected even if the final extension is allowed.
 73:      * Leave `null` to disable allowlisting.
 74:      */
 75:     'allowed_extensions' => null,
 76: 
 77:     /*
 78:      * This queue connection will be used to generate derived and responsive images.
 79:      * Leave empty to use the default queue connection.
 80:      */
 81:     'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),
 82: 
 83:     /*
 84:      * This queue will be used to generate derived and responsive images.
 85:      * Leave empty to use the default queue.
 86:      */
 87:     'queue_name' => env('MEDIA_QUEUE', ''),
 88: 
 89:     /*
 90:      * By default all conversions will be performed on a queue.
 91:      */
 92:     'queue_conversions_by_default' => env('QUEUE_CONVERSIONS_BY_DEFAULT', true),
 93: 
 94:     /*
 95:      * Should database transactions be run after database commits?
 96:      */
 97:     'queue_conversions_after_database_commit' => env('QUEUE_CONVERSIONS_AFTER_DB_COMMIT', true),
 98: 
 99:     /*
100:      * The fully qualified class name of the media model.
101:      */
102:     'media_model' => Media::class,
103: 
104:     /*
105:      * The fully qualified class name of the media observer.
106:      */
107:     'media_observer' => MediaObserver::class,
108: 
109:     /*
110:      * When enabled, media collections will be serialised using the default
111:      * laravel model serialization behaviour.
112:      *
113:      * Keep this option disabled if using Media Library Pro components (https://medialibrary.pro)
114:      */
115:     'use_default_collection_serialization' => false,
116: 
117:     /*
118:      * The fully qualified class name of the model used for temporary uploads.
119:      *
120:      * This model is only used in Media Library Pro (https://medialibrary.pro)
121:      */
122:     'temporary_upload_model' => TemporaryUpload::class,
123: 
124:     /*
125:      * When enabled, Media Library Pro will only process temporary uploads that were uploaded
126:      * in the same session. You can opt to disable this for stateless usage of
127:      * the pro components.
128:      */
129:     'enable_temporary_uploads_session_affinity' => true,
130: 
131:     /*
132:      * When enabled, Media Library pro will generate thumbnails for uploaded file.
133:      */
134:     'generate_thumbnails_for_temporary_uploads' => true,
135: 
136:     /*
137:      * This is the class that is responsible for naming generated files.
138:      */
139:     'file_namer' => DefaultFileNamer::class,
140: 
141:     /*
142:      * The class that contains the strategy for determining a media file's path.
143:      */
144:     'path_generator' => DefaultPathGenerator::class,
145: 
146:     /*
147:      * The class that contains the strategy for determining how to remove files.
148:      */
149:     'file_remover_class' => DefaultFileRemover::class,
150: 
151:     /*
152:      * Here you can specify which path generator should be used for the given class.
153:      */
154:     'custom_path_generators' => [
155:         // Model::class => PathGenerator::class
156:         // or
157:         // 'model_morph_alias' => PathGenerator::class
158:     ],
159: 
160:     /*
161:      * When urls to files get generated, this class will be called. Use the default
162:      * if your files are stored locally above the site root or on s3.
163:      */
164:     'url_generator' => DefaultUrlGenerator::class,
165: 
166:     /*
167:      * Moves media on updating to keep path consistent. Enable it only with a custom
168:      * PathGenerator that uses, for example, the media UUID.
169:      */
170:     'moves_media_on_update' => false,
171: 
172:     /*
173:      * Whether to activate versioning when urls to files get generated.
174:      * When activated, this attaches a ?v=xx query string to the URL.
175:      */
176:     'version_urls' => false,
177: 
178:     /*
179:      * The media library will try to optimize all converted images by removing
180:      * metadata and applying a little bit of compression. These are
181:      * the optimizers that will be used by default.
182:      */
183:     'image_optimizers' => [
184:         Jpegoptim::class => [
185:             '-m85', // set maximum quality to 85%
186:             '--force', // ensure that progressive generation is always done also if a little bigger
187:             '--strip-all', // this strips out all text information such as comments and EXIF data
188:             '--all-progressive', // this will make sure the resulting image is a progressive one
189:         ],
190:         Pngquant::class => [
191:             '--force', // required parameter for this package
192:         ],
193:         Optipng::class => [
194:             '-i0', // this will result in a non-interlaced, progressive scanned image
195:             '-o2', // this set the optimization level to two (multiple IDAT compression trials)
196:             '-quiet', // required parameter for this package
197:         ],
198:         Svgo::class => [
199:             '--disable=cleanupIDs', // disabling because it is known to cause troubles
200:         ],
201:         Gifsicle::class => [
202:             '-b', // required parameter for this package
203:             '-O3', // this produces the slowest but best results
204:         ],
205:         Cwebp::class => [
206:             '-m 6', // for the slowest compression method in order to get the best compression.
207:             '-pass 10', // for maximizing the amount of analysis pass.
208:             '-mt', // multithreading for some speed improvements.
209:             '-q 90', // quality factor that brings the least noticeable changes.
210:         ],
211:         Avifenc::class => [
212:             '-a cq-level=23', // constant quality level, lower values mean better quality and greater file size (0-63).
213:             '-j all', // number of jobs (worker threads, "all" uses all available cores).
214:             '--min 0', // min quantizer for color (0-63).
215:             '--max 63', // max quantizer for color (0-63).
216:             '--minalpha 0', // min quantizer for alpha (0-63).
217:             '--maxalpha 63', // max quantizer for alpha (0-63).
218:             '-a end-usage=q', // rate control mode set to Constant Quality mode.
219:             '-a tune=ssim', // SSIM as tune the encoder for distortion metric.
220:         ],
221:     ],
222: 
223:     /*
224:      * These generators will be used to create an image of media files.
225:      */
226:     'image_generators' => [
227:         Image::class,
228:         Webp::class,
229:         Avif::class,
230:         Pdf::class,
231:         Svg::class,
232:         Video::class,
233:     ],
234: 
235:     /*
236:      * The path where to store temporary files while performing image conversions.
237:      * If set to null, storage_path('media-library/temp') will be used.
238:      */
239:     'temporary_directory_path' => null,
240: 
241:     /*
242:      * The engine that should perform the image conversions.
243:      * Should be either `gd`, `imagick` or `vips`.
244:      */
245:     'image_driver' => env('IMAGE_DRIVER', 'gd'),
246: 
247:     /*
248:      * FFMPEG & FFProbe binaries paths, only used if you try to generate video
249:      * thumbnails and have installed the php-ffmpeg/php-ffmpeg composer
250:      * dependency.
251:      */
252:     'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
253:     'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
254: 
255:     /*
256:      * The timeout (in seconds) that will be used when generating video
257:      * thumbnails via FFMPEG.
258:      */
259:     'ffmpeg_timeout' => env('FFMPEG_TIMEOUT', 900),
260: 
261:     /*
262:      * The number of threads that FFMPEG should use. 0 means that FFMPEG
263:      * may decide itself.
264:      */
265:     'ffmpeg_threads' => env('FFMPEG_THREADS', 0),
266: 
267:     /*
268:      * Here you can override the class names of the jobs used by this package. Make sure
269:      * your custom jobs extend the ones provided by the package.
270:      */
271:     'jobs' => [
272:         'perform_conversions' => PerformConversionsJob::class,
273:         'generate_responsive_images' => GenerateResponsiveImagesJob::class,
274:     ],
275: 
276:     /*
277:      * When using the addMediaFromUrl method you may want to replace the default downloader.
278:      * This is particularly useful when the url of the image is behind a firewall and
279:      * need to add additional flags, possibly using curl.
280:      */
281:     'media_downloader' => DefaultDownloader::class,
282: 
283:     /*
284:      * When using the addMediaFromUrl method the SSL is verified by default.
285:      * This is option disables SSL verification when downloading remote media.
286:      * Please note that this is a security risk and should only be false in a local environment.
287:      */
288:     'media_downloader_ssl' => env('MEDIA_DOWNLOADER_SSL', true),
289: 
290:     /*
291:      * The default lifetime in minutes for temporary urls.
292:      * This is used when you call the `getLastTemporaryUrl` or `getLastTemporaryUrl` method on a media item.
293:      */
294:     'temporary_url_default_lifetime' => env('MEDIA_TEMPORARY_URL_DEFAULT_LIFETIME', 5),
295: 
296:     'remote' => [
297:         /*
298:          * Any extra headers that should be included when uploading media to
299:          * a remote disk. Even though supported headers may vary between
300:          * different drivers, a sensible default has been provided.
301:          *
302:          * Supported by S3: CacheControl, Expires, StorageClass,
303:          * ServerSideEncryption, Metadata, ACL, ContentEncoding
304:          */
305:         'extra_headers' => [
306:             'CacheControl' => 'max-age=604800',
307:         ],
308:     ],
309: 
310:     'responsive_images' => [
311:         /*
312:          * This class is responsible for calculating the target widths of the responsive
313:          * images. By default we optimize for filesize and create variations that each are 30%
314:          * smaller than the previous one. More info in the documentation.
315:          *
316:          * https://docs.spatie.be/laravel-medialibrary/v9/advanced-usage/generating-responsive-images
317:          */
318:         'width_calculator' => FileSizeOptimizedWidthCalculator::class,
319: 
320:         /*
321:          * By default rendering media to a responsive image will add some javascript and a tiny placeholder.
322:          * This ensures that the browser can already determine the correct layout.
323:          * When disabled, no tiny placeholder is generated.
324:          */
325:         'use_tiny_placeholders' => true,
326: 
327:         /*
328:          * This class will generate the tiny placeholder used for progressive image loading. By default
329:          * the media library will use a tiny blurred jpg image.
330:          */
331:         'tiny_placeholder_generator' => Blurred::class,
332:     ],
333: 
334:     /*
335:      * When enabling this option, a route will be registered that will enable
336:      * the Media Library Pro Vue and React components to move uploaded files
337:      * in a S3 bucket to their right place.
338:      */
339:     'enable_vapor_uploads' => env('ENABLE_MEDIA_LIBRARY_VAPOR_UPLOADS', false),
340: 
341:     /*
342:      * When converting Media instances to response the media library will add
343:      * a `loading` attribute to the `img` tag. Here you can set the default
344:      * value of that attribute.
345:      *
346:      * Possible values: 'lazy', 'eager', 'auto' or null if you don't want to set any loading instruction.
347:      *
348:      * More info: https://css-tricks.com/native-lazy-loading/
349:      */
350:     'default_loading_attribute_value' => null,
351: 
352:     /*
353:      * You can specify a prefix for that is used for storing all media.
354:      * If you set this to `/my-subdir`, all your media will be stored in a `/my-subdir` directory.
355:      */
356:     'prefix' => env('MEDIA_PREFIX', ''),
357: 
358:     /*
359:      * When forcing lazy loading, media will be loaded even if you don't eager load media and you have
360:      * disabled lazy loading globally in the service provider.
361:      */
362:     'force_lazy_loading' => env('FORCE_MEDIA_LIBRARY_LAZY_LOADING', true),
363: ];
```

## File: bags-platform/backend/config/permission.php
```php
  1: <?php
  2: 
  3: use Spatie\Permission\DefaultTeamResolver;
  4: use Spatie\Permission\Models\Permission;
  5: use Spatie\Permission\Models\Role;
  6: 
  7: return [
  8: 
  9:     'models' => [
 10: 
 11:         /*
 12:          * When using the "HasPermissions" trait from this package, we need to know which
 13:          * Eloquent model should be used to retrieve your permissions. Of course, it
 14:          * is often just the "Permission" model but you may use whatever you like.
 15:          *
 16:          * The model you want to use as a Permission model needs to implement the
 17:          * `Spatie\Permission\Contracts\Permission` contract.
 18:          */
 19: 
 20:         'permission' => Permission::class,
 21: 
 22:         /*
 23:          * When using the "HasRoles" trait from this package, we need to know which
 24:          * Eloquent model should be used to retrieve your roles. Of course, it
 25:          * is often just the "Role" model but you may use whatever you like.
 26:          *
 27:          * The model you want to use as a Role model needs to implement the
 28:          * `Spatie\Permission\Contracts\Role` contract.
 29:          */
 30: 
 31:         'role' => Role::class,
 32: 
 33:         /*
 34:          * When using the "Teams" feature from this package, we need to know which
 35:          * Eloquent model should be used to retrieve your teams. Of course, it
 36:          * is often just the "Team" model but you may use whatever you like.
 37:          */
 38:         'team' => null,
 39: 
 40:         /*
 41:          * When using the "HasModels" trait and passing raw IDs to syncModels,
 42:          * attachModels, or detachModels, this model class will be used to
 43:          * resolve those IDs. If null, defaults to the guard's model.
 44:          */
 45:         'default_model' => null,
 46:     ],
 47: 
 48:     'table_names' => [
 49: 
 50:         /*
 51:          * When using the "HasRoles" trait from this package, we need to know which
 52:          * table should be used to retrieve your roles. We have chosen a basic
 53:          * default value but you may easily change it to any table you like.
 54:          */
 55: 
 56:         'roles' => 'roles',
 57: 
 58:         /*
 59:          * When using the "HasPermissions" trait from this package, we need to know which
 60:          * table should be used to retrieve your permissions. We have chosen a basic
 61:          * default value but you may easily change it to any table you like.
 62:          */
 63: 
 64:         'permissions' => 'permissions',
 65: 
 66:         /*
 67:          * When using the "HasPermissions" trait from this package, we need to know which
 68:          * table should be used to retrieve your models permissions. We have chosen a
 69:          * basic default value but you may easily change it to any table you like.
 70:          */
 71: 
 72:         'model_has_permissions' => 'model_has_permissions',
 73: 
 74:         /*
 75:          * When using the "HasRoles" trait from this package, we need to know which
 76:          * table should be used to retrieve your models roles. We have chosen a
 77:          * basic default value but you may easily change it to any table you like.
 78:          */
 79: 
 80:         'model_has_roles' => 'model_has_roles',
 81: 
 82:         /*
 83:          * When using the "HasRoles" trait from this package, we need to know which
 84:          * table should be used to retrieve your roles permissions. We have chosen a
 85:          * basic default value but you may easily change it to any table you like.
 86:          */
 87: 
 88:         'role_has_permissions' => 'role_has_permissions',
 89:     ],
 90: 
 91:     'column_names' => [
 92:         /*
 93:          * Change this if you want to name the related pivots other than defaults
 94:          */
 95:         'role_pivot_key' => null, // default 'role_id',
 96:         'permission_pivot_key' => null, // default 'permission_id',
 97: 
 98:         /*
 99:          * Change this if you want to name the related model primary key other than
100:          * `model_id`.
101:          *
102:          * For example, this would be nice if your primary keys are all UUIDs. In
103:          * that case, name this `model_uuid`.
104:          */
105: 
106:         'model_morph_key' => 'model_id',
107: 
108:         /*
109:          * Change this if you want to use the teams feature and your related model's
110:          * foreign key is other than `team_id`.
111:          */
112: 
113:         'team_foreign_key' => 'team_id',
114:     ],
115: 
116:     /*
117:      * When set to true, the method for checking permissions will be registered on the gate.
118:      * Set this to false if you want to implement custom logic for checking permissions.
119:      */
120: 
121:     'register_permission_check_method' => true,
122: 
123:     /*
124:      * When set to true, Laravel\Octane\Events\OperationTerminated event listener will be registered
125:      * this will refresh permissions on every TickTerminated, TaskTerminated and RequestTerminated
126:      * NOTE: This should not be needed in most cases, but an Octane/Vapor combination benefited from it.
127:      */
128:     'register_octane_reset_listener' => false,
129: 
130:     /*
131:      * Events will fire when a role or permission is assigned/unassigned:
132:      * \Spatie\Permission\Events\RoleAttachedEvent
133:      * \Spatie\Permission\Events\RoleDetachedEvent
134:      * \Spatie\Permission\Events\PermissionAttachedEvent
135:      * \Spatie\Permission\Events\PermissionDetachedEvent
136:      *
137:      * To enable, set to true, and then create listeners to watch these events.
138:      */
139:     'events_enabled' => false,
140: 
141:     /*
142:      * Teams Feature.
143:      * When set to true the package implements teams using the 'team_foreign_key'.
144:      * If you want the migrations to register the 'team_foreign_key', you must
145:      * set this to true before doing the migration.
146:      * If you already did the migration then you must make a new migration to also
147:      * add 'team_foreign_key' to 'roles', 'model_has_roles', and 'model_has_permissions'
148:      * (view the latest version of this package's migration file)
149:      */
150: 
151:     'teams' => false,
152: 
153:     /*
154:      * The class to use to resolve the permissions team id
155:      */
156:     'team_resolver' => DefaultTeamResolver::class,
157: 
158:     /*
159:      * Passport Client Credentials Grant
160:      * When set to true the package will use Passports Client to check permissions
161:      */
162: 
163:     'use_passport_client_credentials' => false,
164: 
165:     /*
166:      * When set to true, the required permission names are added to exception messages.
167:      * This could be considered an information leak in some contexts, so the default
168:      * setting is false here for optimum safety.
169:      */
170: 
171:     'display_permission_in_exception' => false,
172: 
173:     /*
174:      * When set to true, the required role names are added to exception messages.
175:      * This could be considered an information leak in some contexts, so the default
176:      * setting is false here for optimum safety.
177:      */
178: 
179:     'display_role_in_exception' => false,
180: 
181:     /*
182:      * By default wildcard permission lookups are disabled.
183:      * See documentation to understand supported syntax.
184:      */
185: 
186:     'enable_wildcard_permission' => false,
187: 
188:     /*
189:      * The class to use for interpreting wildcard permissions.
190:      * If you need to modify delimiters, override the class and specify its name here.
191:      */
192:     // 'wildcard_permission' => Spatie\Permission\WildcardPermission::class,
193: 
194:     /* Cache-specific settings */
195: 
196:     'cache' => [
197: 
198:         /*
199:          * By default all permissions are cached for 24 hours to speed up performance.
200:          * When permissions or roles are updated the cache is flushed automatically.
201:          */
202: 
203:         'expiration_time' => DateInterval::createFromDateString('24 hours'),
204: 
205:         /*
206:          * The cache key used to store all permissions.
207:          */
208: 
209:         'key' => 'spatie.permission.cache',
210: 
211:         /*
212:          * You may optionally indicate a specific cache driver to use for permission and
213:          * role caching using any of the `store` drivers listed in the cache.php config
214:          * file. Using 'default' here means to use the `default` set in cache.php.
215:          */
216: 
217:         'store' => 'default',
218:     ],
219: ];
```

## File: bags-platform/backend/config/queue.php
```php
  1: <?php
  2: 
  3: return [
  4: 
  5:     /*
  6:     |--------------------------------------------------------------------------
  7:     | Default Queue Connection Name
  8:     |--------------------------------------------------------------------------
  9:     |
 10:     | Laravel's queue supports a variety of backends via a single, unified
 11:     | API, giving you convenient access to each backend using identical
 12:     | syntax for each. The default queue connection is defined below.
 13:     |
 14:     */
 15: 
 16:     'default' => env('QUEUE_CONNECTION', 'database'),
 17: 
 18:     /*
 19:     |--------------------------------------------------------------------------
 20:     | Queue Connections
 21:     |--------------------------------------------------------------------------
 22:     |
 23:     | Here you may configure the connection options for every queue backend
 24:     | used by your application. An example configuration is provided for
 25:     | each backend supported by Laravel. You're also free to add more.
 26:     |
 27:     | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
 28:     |          "deferred", "background", "failover", "null"
 29:     |
 30:     */
 31: 
 32:     'connections' => [
 33: 
 34:         'sync' => [
 35:             'driver' => 'sync',
 36:         ],
 37: 
 38:         'database' => [
 39:             'driver' => 'database',
 40:             'connection' => env('DB_QUEUE_CONNECTION'),
 41:             'table' => env('DB_QUEUE_TABLE', 'jobs'),
 42:             'queue' => env('DB_QUEUE', 'default'),
 43:             'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
 44:             'after_commit' => false,
 45:         ],
 46: 
 47:         'beanstalkd' => [
 48:             'driver' => 'beanstalkd',
 49:             'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
 50:             'queue' => env('BEANSTALKD_QUEUE', 'default'),
 51:             'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
 52:             'block_for' => 0,
 53:             'after_commit' => false,
 54:         ],
 55: 
 56:         'sqs' => [
 57:             'driver' => 'sqs',
 58:             'key' => env('AWS_ACCESS_KEY_ID'),
 59:             'secret' => env('AWS_SECRET_ACCESS_KEY'),
 60:             'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
 61:             'queue' => env('SQS_QUEUE', 'default'),
 62:             'suffix' => env('SQS_SUFFIX'),
 63:             'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
 64:             'after_commit' => false,
 65:         ],
 66: 
 67:         'redis' => [
 68:             'driver' => 'redis',
 69:             'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
 70:             'queue' => env('REDIS_QUEUE', 'default'),
 71:             'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
 72:             'block_for' => null,
 73:             'after_commit' => false,
 74:         ],
 75: 
 76:         'deferred' => [
 77:             'driver' => 'deferred',
 78:         ],
 79: 
 80:         'background' => [
 81:             'driver' => 'background',
 82:         ],
 83: 
 84:         'failover' => [
 85:             'driver' => 'failover',
 86:             'connections' => [
 87:                 'database',
 88:                 'deferred',
 89:             ],
 90:         ],
 91: 
 92:     ],
 93: 
 94:     /*
 95:     |--------------------------------------------------------------------------
 96:     | Job Batching
 97:     |--------------------------------------------------------------------------
 98:     |
 99:     | The following options configure the database and table that store job
100:     | batching information. These options can be updated to any database
101:     | connection and table which has been defined by your application.
102:     |
103:     */
104: 
105:     'batching' => [
106:         'database' => env('DB_CONNECTION', 'sqlite'),
107:         'table' => 'job_batches',
108:     ],
109: 
110:     /*
111:     |--------------------------------------------------------------------------
112:     | Failed Queue Jobs
113:     |--------------------------------------------------------------------------
114:     |
115:     | These options configure the behavior of failed queue job logging so you
116:     | can control how and where failed jobs are stored. Laravel ships with
117:     | support for storing failed jobs in a simple file or in a database.
118:     |
119:     | Supported drivers: "database-uuids", "dynamodb", "file", "null"
120:     |
121:     */
122: 
123:     'failed' => [
124:         'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
125:         'database' => env('DB_CONNECTION', 'sqlite'),
126:         'table' => 'failed_jobs',
127:     ],
128: 
129: ];
```

## File: bags-platform/backend/config/sanctum.php
```php
 1: <?php
 2: 
 3: use Illuminate\Cookie\Middleware\EncryptCookies;
 4: use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
 5: use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
 6: use Laravel\Sanctum\Sanctum;
 7: 
 8: return [
 9: 
10:     /*
11:     |--------------------------------------------------------------------------
12:     | Stateful Domains
13:     |--------------------------------------------------------------------------
14:     |
15:     | Requests from the following domains / hosts will receive stateful API
16:     | authentication cookies. Typically, these should include your local
17:     | and production domains which access your API via a frontend SPA.
18:     |
19:     */
20: 
21:     'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
22:         '%s%s',
23:         'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
24:         Sanctum::currentApplicationUrlWithPort(),
25:         // Sanctum::currentRequestHost(),
26:     ))),
27: 
28:     /*
29:     |--------------------------------------------------------------------------
30:     | Sanctum Guards
31:     |--------------------------------------------------------------------------
32:     |
33:     | This array contains the authentication guards that will be checked when
34:     | Sanctum is trying to authenticate a request. If none of these guards
35:     | are able to authenticate the request, Sanctum will use the bearer
36:     | token that's present on an incoming request for authentication.
37:     |
38:     */
39: 
40:     'guard' => ['web'],
41: 
42:     /*
43:     |--------------------------------------------------------------------------
44:     | Expiration Minutes
45:     |--------------------------------------------------------------------------
46:     |
47:     | This value controls the number of minutes until an issued token will be
48:     | considered expired. This will override any values set in the token's
49:     | "expires_at" attribute, but first-party sessions are not affected.
50:     |
51:     */
52: 
53:     'expiration' => env('SANCTUM_EXPIRATION', 1440),
54: 
55:     /*
56:     |--------------------------------------------------------------------------
57:     | Token Prefix
58:     |--------------------------------------------------------------------------
59:     |
60:     | Sanctum can prefix new tokens in order to take advantage of numerous
61:     | security scanning initiatives maintained by open source platforms
62:     | that notify developers if they commit tokens into repositories.
63:     |
64:     | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
65:     |
66:     */
67: 
68:     'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
69: 
70:     /*
71:     |--------------------------------------------------------------------------
72:     | Sanctum Middleware
73:     |--------------------------------------------------------------------------
74:     |
75:     | When authenticating your first-party SPA with Sanctum you may need to
76:     | customize some of the middleware Sanctum uses while processing the
77:     | request. You may change the middleware listed below as required.
78:     |
79:     */
80: 
81:     'middleware' => [
82:         'authenticate_session' => AuthenticateSession::class,
83:         'encrypt_cookies' => EncryptCookies::class,
84:         'validate_csrf_token' => ValidateCsrfToken::class,
85:     ],
86: 
87: ];
```

## File: bags-platform/backend/database/factories/BranchFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\BranchType;
 8: use App\Models\Branch;
 9: use Illuminate\Database\Eloquent\Factories\Factory;
10: 
11: /**
12:  * @extends Factory<Branch>
13:  */
14: class BranchFactory extends Factory
15: {
16:     public function definition(): array
17:     {
18:         return [
19:             'name' => fake()->company(),
20:             'code' => 'BR-'.fake()->unique()->numerify('#####'),
21:             'type' => BranchType::Branch,
22:             'phone' => fake()->phoneNumber(),
23:             'email' => fake()->safeEmail(),
24:             'address' => fake()->streetAddress(),
25:             'city' => fake()->city(),
26:             'is_active' => true,
27:         ];
28:     }
29: }
```

## File: bags-platform/backend/database/factories/BrandFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\Brand;
 8: use Illuminate\Database\Eloquent\Factories\Factory;
 9: use Illuminate\Support\Str;
10: 
11: /**
12:  * @extends Factory<Brand>
13:  */
14: class BrandFactory extends Factory
15: {
16:     public function definition(): array
17:     {
18:         $name = fake()->unique()->company();
19: 
20:         return [
21:             'name_ar' => 'براند '.$name,
22:             'name_en' => $name,
23:             'slug' => Str::slug($name),
24:             'description_ar' => fake()->sentence(),
25:             'description_en' => fake()->sentence(),
26:             'sort_order' => fake()->numberBetween(0, 50),
27:             'is_active' => true,
28:         ];
29:     }
30: }
```

## File: bags-platform/backend/database/factories/CategoryFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\Category;
 8: use Illuminate\Database\Eloquent\Factories\Factory;
 9: use Illuminate\Support\Str;
10: 
11: /**
12:  * @extends Factory<Category>
13:  */
14: class CategoryFactory extends Factory
15: {
16:     public function definition(): array
17:     {
18:         $name = fake()->unique()->words(2, true);
19: 
20:         return [
21:             'parent_id' => null,
22:             'name_ar' => 'قسم '.fake()->unique()->numerify('####'),
23:             'name_en' => Str::title($name),
24:             'slug' => Str::slug($name),
25:             'description_ar' => fake()->sentence(),
26:             'description_en' => fake()->sentence(),
27:             'sort_order' => fake()->numberBetween(0, 50),
28:             'is_active' => true,
29:         ];
30:     }
31: }
```

## File: bags-platform/backend/database/factories/LocationFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\LocationType;
 8: use App\Models\Branch;
 9: use App\Models\Location;
10: use Illuminate\Database\Eloquent\Factories\Factory;
11: 
12: /**
13:  * @extends Factory<Location>
14:  */
15: class LocationFactory extends Factory
16: {
17:     public function definition(): array
18:     {
19:         return [
20:             'branch_id' => Branch::factory(),
21:             'name' => fake()->words(2, true),
22:             'code' => 'A-'.fake()->unique()->numerify('##-##'),
23:             'type' => LocationType::Shelf,
24:             'is_active' => true,
25:         ];
26:     }
27: }
```

## File: bags-platform/backend/database/factories/ProductFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Enums\ProductStatus;
 8: use App\Enums\SalesChannel;
 9: use App\Models\Brand;
10: use App\Models\Category;
11: use App\Models\Product;
12: use Illuminate\Database\Eloquent\Factories\Factory;
13: use Illuminate\Support\Str;
14: 
15: /**
16:  * @extends Factory<Product>
17:  */
18: class ProductFactory extends Factory
19: {
20:     public function definition(): array
21:     {
22:         $name = fake()->unique()->words(3, true);
23: 
24:         return [
25:             'category_id' => Category::factory(),
26:             'brand_id' => Brand::factory(),
27:             'supplier_id' => null,
28:             'created_by' => null,
29:             'code' => 'P'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
30:             'name_ar' => 'منتج '.fake()->unique()->numerify('####'),
31:             'name_en' => Str::title($name),
32:             'slug' => Str::slug($name),
33:             'description_ar' => fake()->paragraph(),
34:             'description_en' => fake()->paragraph(),
35:             'material_ar' => fake()->word(),
36:             'material_en' => fake()->word(),
37:             'length_cm' => fake()->randomFloat(2, 10, 80),
38:             'width_cm' => fake()->randomFloat(2, 10, 60),
39:             'height_cm' => fake()->randomFloat(2, 5, 50),
40:             'weight_kg' => fake()->randomFloat(3, 0.1, 10),
41:             'warranty_months' => fake()->randomElement([null, 6, 12, 24]),
42:             'warranty_details' => null,
43:             'country_of_origin' => fake()->country(),
44:             'status' => ProductStatus::Draft,
45:             'sales_channel' => SalesChannel::Both,
46:             'is_featured' => false,
47:             'published_at' => null,
48:         ];
49:     }
50: 
51:     public function published(): static
52:     {
53:         return $this->state(fn (array $attributes) => [
54:             'status' => ProductStatus::Published,
55:             'published_at' => now(),
56:         ]);
57:     }
58: }
```

## File: bags-platform/backend/database/factories/ProductVariantFactory.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Factories;
 6: 
 7: use App\Models\Product;
 8: use App\Models\ProductVariant;
 9: use Illuminate\Database\Eloquent\Factories\Factory;
10: 
11: /**
12:  * @extends Factory<ProductVariant>
13:  */
14: class ProductVariantFactory extends Factory
15: {
16:     public function definition(): array
17:     {
18:         $color = fake()->randomElement([
19:             ['name_ar' => 'أسود', 'name_en' => 'Black', 'hex' => '#000000'],
20:             ['name_ar' => 'بني', 'name_en' => 'Brown', 'hex' => '#8B4513'],
21:             ['name_ar' => 'أزرق', 'name_en' => 'Navy', 'hex' => '#1F2A5A'],
22:             ['name_ar' => 'بيج', 'name_en' => 'Beige', 'hex' => '#D9C7A7'],
23:             ['name_ar' => 'أحمر', 'name_en' => 'Red', 'hex' => '#B91C1C'],
24:             ['name_ar' => 'رمادي', 'name_en' => 'Gray', 'hex' => '#6B7280'],
25:         ]);
26:         $cost = fake()->randomFloat(2, 50, 2000);
27: 
28:         return [
29:             'product_id' => Product::factory(),
30:             'sku' => 'SKU-'.fake()->unique()->numerify('######'),
31:             'barcode' => $this->ean13(),
32:             'color_name_ar' => $color['name_ar'],
33:             'color_name_en' => $color['name_en'],
34:             'color_hex' => $color['hex'],
35:             'size' => fake()->randomElement(['S', 'M', 'L', null]),
36:             'capacity' => null,
37:             'cost_price' => $cost,
38:             'retail_price' => round($cost * 1.6, 2),
39:             'wholesale_price' => round($cost * 1.35, 2),
40:             'reorder_level' => fake()->numberBetween(0, 10),
41:             'weight_kg' => null,
42:             'sort_order' => fake()->numberBetween(0, 20),
43:             'is_active' => true,
44:         ];
45:     }
46: 
47:     /** باركود EAN-13 داخلي سليم الرقم التأكيدي (يبدأ بـ 2). */
48:     private function ean13(): string
49:     {
50:         $base = '2'.str_pad((string) fake()->unique()->numberBetween(0, 99999999999), 11, '0', STR_PAD_LEFT);
51:         $sum = 0;
52:         foreach (str_split($base) as $i => $digit) {
53:             $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
54:         }
55: 
56:         return $base.((10 - ($sum % 10)) % 10);
57:     }
58: }
```

## File: bags-platform/backend/database/factories/UserFactory.php
```php
 1: <?php
 2: 
 3: namespace Database\Factories;
 4: 
 5: use App\Models\User;
 6: use Illuminate\Database\Eloquent\Factories\Factory;
 7: use Illuminate\Support\Facades\Hash;
 8: use Illuminate\Support\Str;
 9: 
10: /**
11:  * @extends Factory<User>
12:  */
13: class UserFactory extends Factory
14: {
15:     /**
16:      * The current password being used by the factory.
17:      */
18:     protected static ?string $password;
19: 
20:     /**
21:      * Define the model's default state.
22:      *
23:      * @return array<string, mixed>
24:      */
25:     public function definition(): array
26:     {
27:         return [
28:             'name' => fake()->name(),
29:             'email' => fake()->unique()->safeEmail(),
30:             'email_verified_at' => now(),
31:             'password' => static::$password ??= Hash::make('password'),
32:             'remember_token' => Str::random(10),
33:         ];
34:     }
35: 
36:     /**
37:      * Indicate that the model's email address should be unverified.
38:      */
39:     public function unverified(): static
40:     {
41:         return $this->state(fn (array $attributes) => [
42:             'email_verified_at' => null,
43:         ]);
44:     }
45: }
```

## File: bags-platform/backend/database/migrations/0001_01_01_000000_create_users_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('users', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name');
17:             $table->string('email')->unique();
18:             $table->timestamp('email_verified_at')->nullable();
19:             $table->string('password');
20:             $table->rememberToken();
21:             $table->timestamps();
22:         });
23: 
24:         Schema::create('password_reset_tokens', function (Blueprint $table) {
25:             $table->string('email')->primary();
26:             $table->string('token');
27:             $table->timestamp('created_at')->nullable();
28:         });
29: 
30:         Schema::create('sessions', function (Blueprint $table) {
31:             $table->string('id')->primary();
32:             $table->foreignId('user_id')->nullable()->index();
33:             $table->string('ip_address', 45)->nullable();
34:             $table->text('user_agent')->nullable();
35:             $table->longText('payload');
36:             $table->integer('last_activity')->index();
37:         });
38:     }
39: 
40:     /**
41:      * Reverse the migrations.
42:      */
43:     public function down(): void
44:     {
45:         Schema::dropIfExists('users');
46:         Schema::dropIfExists('password_reset_tokens');
47:         Schema::dropIfExists('sessions');
48:     }
49: };
```

## File: bags-platform/backend/database/migrations/0001_01_01_000001_create_cache_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('cache', function (Blueprint $table) {
15:             $table->string('key')->primary();
16:             $table->mediumText('value');
17:             $table->bigInteger('expiration')->index();
18:         });
19: 
20:         Schema::create('cache_locks', function (Blueprint $table) {
21:             $table->string('key')->primary();
22:             $table->string('owner');
23:             $table->bigInteger('expiration')->index();
24:         });
25:     }
26: 
27:     /**
28:      * Reverse the migrations.
29:      */
30:     public function down(): void
31:     {
32:         Schema::dropIfExists('cache');
33:         Schema::dropIfExists('cache_locks');
34:     }
35: };
```

## File: bags-platform/backend/database/migrations/0001_01_01_000002_create_jobs_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('jobs', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('queue')->index();
17:             $table->longText('payload');
18:             $table->unsignedSmallInteger('attempts');
19:             $table->unsignedInteger('reserved_at')->nullable();
20:             $table->unsignedInteger('available_at');
21:             $table->unsignedInteger('created_at');
22:         });
23: 
24:         Schema::create('job_batches', function (Blueprint $table) {
25:             $table->string('id')->primary();
26:             $table->string('name');
27:             $table->integer('total_jobs');
28:             $table->integer('pending_jobs');
29:             $table->integer('failed_jobs');
30:             $table->longText('failed_job_ids');
31:             $table->mediumText('options')->nullable();
32:             $table->integer('cancelled_at')->nullable();
33:             $table->integer('created_at');
34:             $table->integer('finished_at')->nullable();
35:         });
36: 
37:         Schema::create('failed_jobs', function (Blueprint $table) {
38:             $table->id();
39:             $table->string('uuid')->unique();
40:             $table->string('connection');
41:             $table->string('queue');
42:             $table->longText('payload');
43:             $table->longText('exception');
44:             $table->timestamp('failed_at')->useCurrent();
45: 
46:             $table->index(['connection', 'queue', 'failed_at']);
47:         });
48:     }
49: 
50:     /**
51:      * Reverse the migrations.
52:      */
53:     public function down(): void
54:     {
55:         Schema::dropIfExists('jobs');
56:         Schema::dropIfExists('job_batches');
57:         Schema::dropIfExists('failed_jobs');
58:     }
59: };
```

## File: bags-platform/backend/database/migrations/2026_10_14_224514_create_permission_tables.php
```php
  1: <?php
  2: 
  3: use Illuminate\Database\Migrations\Migration;
  4: use Illuminate\Database\Schema\Blueprint;
  5: use Illuminate\Support\Facades\Schema;
  6: 
  7: return new class extends Migration
  8: {
  9:     /**
 10:      * Run the migrations.
 11:      */
 12:     public function up(): void
 13:     {
 14:         $teams = config('permission.teams');
 15:         $tableNames = config('permission.table_names');
 16:         $columnNames = config('permission.column_names');
 17:         $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
 18:         $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
 19: 
 20:         throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
 21:         throw_if($teams && empty($columnNames['team_foreign_key'] ?? null), 'Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
 22: 
 23:         /**
 24:          * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
 25:          */
 26:         Schema::create($tableNames['permissions'], static function (Blueprint $table) {
 27:             $table->id(); // permission id
 28:             $table->string('name');
 29:             $table->string('guard_name');
 30:             $table->timestamps();
 31: 
 32:             $table->unique(['name', 'guard_name']);
 33:         });
 34: 
 35:         /**
 36:          * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
 37:          */
 38:         Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teams, $columnNames) {
 39:             $table->id(); // role id
 40:             if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
 41:                 $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
 42:                 $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
 43:             }
 44:             $table->string('name');
 45:             $table->string('guard_name');
 46:             $table->timestamps();
 47:             if ($teams || config('permission.testing')) {
 48:                 $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
 49:             } else {
 50:                 $table->unique(['name', 'guard_name']);
 51:             }
 52:         });
 53: 
 54:         Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
 55:             $table->unsignedBigInteger($pivotPermission);
 56: 
 57:             $table->string('model_type');
 58:             $table->unsignedBigInteger($columnNames['model_morph_key']);
 59:             $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');
 60: 
 61:             $table->foreign($pivotPermission)
 62:                 ->references('id') // permission id
 63:                 ->on($tableNames['permissions'])
 64:                 ->cascadeOnDelete();
 65:             if ($teams) {
 66:                 $table->unsignedBigInteger($columnNames['team_foreign_key']);
 67:                 $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');
 68: 
 69:                 $table->primary([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
 70:                     'model_has_permissions_permission_model_type_primary');
 71:             } else {
 72:                 $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
 73:                     'model_has_permissions_permission_model_type_primary');
 74:             }
 75:         });
 76: 
 77:         Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
 78:             $table->unsignedBigInteger($pivotRole);
 79: 
 80:             $table->string('model_type');
 81:             $table->unsignedBigInteger($columnNames['model_morph_key']);
 82:             $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');
 83: 
 84:             $table->foreign($pivotRole)
 85:                 ->references('id') // role id
 86:                 ->on($tableNames['roles'])
 87:                 ->cascadeOnDelete();
 88:             if ($teams) {
 89:                 $table->unsignedBigInteger($columnNames['team_foreign_key']);
 90:                 $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');
 91: 
 92:                 $table->primary([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
 93:                     'model_has_roles_role_model_type_primary');
 94:             } else {
 95:                 $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
 96:                     'model_has_roles_role_model_type_primary');
 97:             }
 98:         });
 99: 
100:         Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
101:             $table->unsignedBigInteger($pivotPermission);
102:             $table->unsignedBigInteger($pivotRole);
103: 
104:             $table->foreign($pivotPermission)
105:                 ->references('id') // permission id
106:                 ->on($tableNames['permissions'])
107:                 ->cascadeOnDelete();
108: 
109:             $table->foreign($pivotRole)
110:                 ->references('id') // role id
111:                 ->on($tableNames['roles'])
112:                 ->cascadeOnDelete();
113: 
114:             $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
115:         });
116: 
117:         app('cache')
118:             ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
119:             ->forget(config('permission.cache.key'));
120:     }
121: 
122:     /**
123:      * Reverse the migrations.
124:      */
125:     public function down(): void
126:     {
127:         $tableNames = config('permission.table_names');
128: 
129:         throw_if(empty($tableNames), 'Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
130: 
131:         Schema::dropIfExists($tableNames['role_has_permissions']);
132:         Schema::dropIfExists($tableNames['model_has_roles']);
133:         Schema::dropIfExists($tableNames['model_has_permissions']);
134:         Schema::dropIfExists($tableNames['roles']);
135:         Schema::dropIfExists($tableNames['permissions']);
136:     }
137: };
```

## File: bags-platform/backend/database/migrations/2026_10_14_224514_create_personal_access_tokens_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('personal_access_tokens', function (Blueprint $table) {
15:             $table->id();
16:             $table->morphs('tokenable');
17:             $table->text('name');
18:             $table->string('token', 64)->unique();
19:             $table->text('abilities')->nullable();
20:             $table->timestamp('last_used_at')->nullable();
21:             $table->timestamp('expires_at')->nullable()->index();
22:             $table->timestamps();
23:         });
24:     }
25: 
26:     /**
27:      * Reverse the migrations.
28:      */
29:     public function down(): void
30:     {
31:         Schema::dropIfExists('personal_access_tokens');
32:     }
33: };
```

## File: bags-platform/backend/database/migrations/2026_10_14_225722_create_branches_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('branches', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name');
17:             $table->timestamps();
18:         });
19:     }
20: 
21:     /**
22:      * Reverse the migrations.
23:      */
24:     public function down(): void
25:     {
26:         Schema::dropIfExists('branches');
27:     }
28: };
```

## File: bags-platform/backend/database/migrations/2026_10_14_230814_add_storage_details_to_branches_and_create_locations_table.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\DB;
 8: use Illuminate\Support\Facades\Schema;
 9: 
10: return new class extends Migration
11: {
12:     public function up(): void
13:     {
14:         Schema::table('branches', function (Blueprint $table): void {
15:             $table->string('code')->nullable();
16:             $table->enum('type', ['branch', 'warehouse'])->default('branch');
17:             $table->string('phone', 50)->nullable();
18:             $table->string('email')->nullable();
19:             $table->string('address')->nullable();
20:             $table->string('city', 120)->nullable();
21:             $table->boolean('is_active')->default(true);
22:         });
23: 
24:         DB::table('branches')->whereNull('code')->orderBy('id')->chunkById(100, function ($branches): void {
25:             foreach ($branches as $branch) {
26:                 DB::table('branches')
27:                     ->where('id', $branch->id)
28:                     ->update(['code' => 'BR-'.str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT)]);
29:             }
30:         });
31: 
32:         Schema::table('branches', function (Blueprint $table): void {
33:             $table->string('code')->nullable(false)->unique()->change();
34:         });
35: 
36:         Schema::create('locations', function (Blueprint $table): void {
37:             $table->id();
38:             $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
39:             $table->string('name');
40:             $table->string('code');
41:             $table->enum('type', ['shelf', 'zone', 'bin', 'damaged', 'receiving'])->default('shelf');
42:             $table->boolean('is_active')->default(true);
43:             $table->timestamps();
44:             $table->unique(['branch_id', 'code']);
45:         });
46:     }
47: 
48:     public function down(): void
49:     {
50:         Schema::dropIfExists('locations');
51: 
52:         Schema::table('branches', function (Blueprint $table): void {
53:             $table->dropUnique('branches_code_unique');
54:             $table->dropColumn(['code', 'type', 'phone', 'email', 'address', 'city', 'is_active']);
55:         });
56:     }
57: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_014600_add_staff_authentication_fields_to_users_table.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::table('users', function (Blueprint $table): void {
14:             $table->string('phone')->nullable()->after('email');
15:             $table->unsignedBigInteger('branch_id')->nullable()->after('password');
16:             $table->boolean('is_active')->default(true)->after('branch_id');
17:         });
18:     }
19: 
20:     public function down(): void
21:     {
22:         Schema::table('users', function (Blueprint $table): void {
23:             $table->dropColumn(['phone', 'branch_id', 'is_active']);
24:         });
25:     }
26: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_014601_create_customers_and_supplier_users_tables.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::create('customers', function (Blueprint $table): void {
14:             $table->id();
15:             $table->string('name');
16:             $table->string('email')->unique();
17:             $table->string('phone')->nullable();
18:             $table->string('password');
19:             $table->enum('type', ['retail', 'wholesale', 'distributor'])->default('retail');
20:             $table->enum('status', ['pending', 'approved', 'rejected', 'blocked'])->default('pending');
21:             $table->string('tax_number')->nullable();
22:             $table->string('commercial_register')->nullable();
23:             $table->timestamps();
24:         });
25: 
26:         Schema::create('supplier_users', function (Blueprint $table): void {
27:             $table->id();
28:             $table->unsignedBigInteger('supplier_id')->nullable();
29:             $table->string('name');
30:             $table->string('email')->unique();
31:             $table->string('phone')->nullable();
32:             $table->string('password');
33:             $table->boolean('is_active')->default(true);
34:             $table->timestamps();
35:         });
36:     }
37: 
38:     public function down(): void
39:     {
40:         Schema::dropIfExists('supplier_users');
41:         Schema::dropIfExists('customers');
42:     }
43: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_021000_create_settings_table.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::create('settings', function (Blueprint $table): void {
14:             $table->id();
15:             $table->string('group')->index();
16:             $table->string('key')->unique();
17:             $table->text('value')->nullable();
18:             $table->string('type', 20)->default('string');
19:             $table->timestamps();
20:         });
21:     }
22: 
23:     public function down(): void
24:     {
25:         Schema::dropIfExists('settings');
26:     }
27: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_023000_add_foreign_key_to_users_branch_id.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::table('users', function (Blueprint $table): void {
14:             $table->index('branch_id', 'users_branch_id_index');
15:             $table->foreign('branch_id', 'users_branch_id_foreign')
16:                 ->references('id')
17:                 ->on('branches')
18:                 ->restrictOnDelete();
19:         });
20:     }
21: 
22:     public function down(): void
23:     {
24:         Schema::table('users', function (Blueprint $table): void {
25:             $table->dropForeign('users_branch_id_foreign');
26:             $table->dropIndex('users_branch_id_index');
27:         });
28:     }
29: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_032900_add_remember_tokens_to_customer_auth_tables.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         foreach (['customers', 'supplier_users'] as $tableName) {
14:             if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'remember_token')) {
15:                 Schema::table($tableName, function (Blueprint $table): void {
16:                     $table->rememberToken();
17:                 });
18:             }
19:         }
20:     }
21: 
22:     public function down(): void
23:     {
24:         foreach (['customers', 'supplier_users'] as $tableName) {
25:             if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'remember_token')) {
26:                 Schema::table($tableName, function (Blueprint $table): void {
27:                     $table->dropColumn('remember_token');
28:                 });
29:             }
30:         }
31:     }
32: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_034200_add_lookup_indexes_to_customer_and_inventory_tables.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: use Illuminate\Database\Migrations\Migration;
 6: use Illuminate\Database\Schema\Blueprint;
 7: use Illuminate\Support\Facades\Schema;
 8: 
 9: return new class extends Migration
10: {
11:     public function up(): void
12:     {
13:         Schema::table('customers', function (Blueprint $table): void {
14:             $table->index(['type', 'status'], 'customers_type_status_index');
15:             $table->index('phone', 'customers_phone_index');
16:         });
17: 
18:         Schema::table('supplier_users', function (Blueprint $table): void {
19:             $table->index(['supplier_id', 'is_active'], 'supplier_users_supplier_active_index');
20:         });
21: 
22:         Schema::table('branches', function (Blueprint $table): void {
23:             $table->index(['type', 'is_active'], 'branches_type_active_index');
24:         });
25: 
26:         Schema::table('locations', function (Blueprint $table): void {
27:             $table->index(['branch_id', 'is_active', 'type'], 'locations_branch_active_type_index');
28:         });
29:     }
30: 
31:     public function down(): void
32:     {
33:         Schema::table('locations', function (Blueprint $table): void {
34:             $table->dropIndex('locations_branch_active_type_index');
35:         });
36: 
37:         Schema::table('branches', function (Blueprint $table): void {
38:             $table->dropIndex('branches_type_active_index');
39:         });
40: 
41:         Schema::table('supplier_users', function (Blueprint $table): void {
42:             $table->dropIndex('supplier_users_supplier_active_index');
43:         });
44: 
45:         Schema::table('customers', function (Blueprint $table): void {
46:             $table->dropIndex('customers_phone_index');
47:             $table->dropIndex('customers_type_status_index');
48:         });
49:     }
50: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_110021_create_media_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     public function up(): void
10:     {
11:         Schema::create('media', function (Blueprint $table) {
12:             $table->id();
13: 
14:             $table->morphs('model');
15:             $table->uuid()->nullable()->unique();
16:             $table->string('collection_name');
17:             $table->string('name');
18:             $table->string('file_name');
19:             $table->string('mime_type')->nullable();
20:             $table->string('disk');
21:             $table->string('conversions_disk')->nullable();
22:             $table->unsignedBigInteger('size');
23:             $table->json('manipulations');
24:             $table->json('custom_properties');
25:             $table->json('generated_conversions');
26:             $table->json('responsive_images');
27:             $table->unsignedInteger('order_column')->nullable()->index();
28: 
29:             $table->nullableTimestamps();
30:         });
31:     }
32: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_140044_create_brands_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('brands', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('name_ar');
17:             $table->string('name_en');
18:             $table->string('slug')->unique();
19:             $table->text('description_ar')->nullable();
20:             $table->text('description_en')->nullable();
21:             $table->unsignedInteger('sort_order')->default(0);
22:             $table->boolean('is_active')->default(true);
23:             $table->timestamps();
24:             $table->softDeletes();
25:         });
26:     }
27: 
28:     /**
29:      * Reverse the migrations.
30:      */
31:     public function down(): void
32:     {
33:         Schema::dropIfExists('brands');
34:     }
35: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_140045_create_categories_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('categories', function (Blueprint $table) {
15:             $table->id();
16:             $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
17:             $table->string('name_ar');
18:             $table->string('name_en');
19:             $table->string('slug')->unique();
20:             $table->text('description_ar')->nullable();
21:             $table->text('description_en')->nullable();
22:             $table->unsignedInteger('sort_order')->default(0);
23:             $table->boolean('is_active')->default(true);
24:             $table->timestamps();
25:             $table->softDeletes();
26:             $table->index(['parent_id', 'sort_order']);
27:         });
28:     }
29: 
30:     /**
31:      * Reverse the migrations.
32:      */
33:     public function down(): void
34:     {
35:         Schema::dropIfExists('categories');
36:     }
37: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_140046_create_products_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('products', function (Blueprint $table) {
15:             $table->id();
16:             $table->string('code')->nullable()->unique();          // P00001 بيتولد تلقائي
17:             $table->foreignId('category_id')->constrained()->restrictOnDelete();
18:             $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
19:             $table->unsignedBigInteger('supplier_id')->nullable()->index(); // الـ FK هيتضاف في مرحلة الموردين
20:             $table->string('name_ar');
21:             $table->string('name_en');
22:             $table->string('slug')->unique();
23:             $table->longText('description_ar')->nullable();
24:             $table->longText('description_en')->nullable();
25:             $table->string('material_ar')->nullable();
26:             $table->string('material_en')->nullable();
27:             $table->decimal('length_cm', 8, 2)->nullable();
28:             $table->decimal('width_cm', 8, 2)->nullable();
29:             $table->decimal('height_cm', 8, 2)->nullable();
30:             $table->decimal('weight_kg', 8, 3)->nullable();
31:             $table->unsignedSmallInteger('warranty_months')->nullable();
32:             $table->string('warranty_details')->nullable();
33:             $table->string('country_of_origin')->nullable();
34:             $table->string('status')->default('draft');            // ProductStatus
35:             $table->string('sales_channel')->default('both');      // SalesChannel
36:             $table->boolean('is_featured')->default(false);
37:             $table->timestamp('published_at')->nullable();
38:             $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
39:             $table->timestamps();
40:             $table->softDeletes();
41:             $table->index(['status', 'sales_channel']);
42:         });
43:     }
44: 
45:     /**
46:      * Reverse the migrations.
47:      */
48:     public function down(): void
49:     {
50:         Schema::dropIfExists('products');
51:     }
52: };
```

## File: bags-platform/backend/database/migrations/2026_10_15_140047_create_product_variants_table.php
```php
 1: <?php
 2: 
 3: use Illuminate\Database\Migrations\Migration;
 4: use Illuminate\Database\Schema\Blueprint;
 5: use Illuminate\Support\Facades\Schema;
 6: 
 7: return new class extends Migration
 8: {
 9:     /**
10:      * Run the migrations.
11:      */
12:     public function up(): void
13:     {
14:         Schema::create('product_variants', function (Blueprint $table) {
15:             $table->id();
16:             $table->foreignId('product_id')->constrained()->cascadeOnDelete();
17:             $table->string('sku')->unique();
18:             $table->string('barcode')->nullable()->unique();
19:             $table->string('color_name_ar')->nullable();
20:             $table->string('color_name_en')->nullable();
21:             $table->string('color_hex', 7)->nullable();            // #000000
22:             $table->string('size')->nullable();                     // S, M, L, 20 inch
23:             $table->string('capacity')->nullable();                 // 40L
24:             $table->decimal('cost_price', 12, 2)->default(0);
25:             $table->decimal('retail_price', 12, 2)->nullable();
26:             $table->decimal('wholesale_price', 12, 2)->nullable();
27:             $table->unsignedInteger('reorder_level')->default(0);
28:             $table->decimal('weight_kg', 8, 3)->nullable();         // لو فاضي ياخد وزن المنتج
29:             $table->unsignedInteger('sort_order')->default(0);
30:             $table->boolean('is_active')->default(true);
31:             $table->timestamps();
32:             $table->softDeletes();
33:         });
34:     }
35: 
36:     /**
37:      * Reverse the migrations.
38:      */
39:     public function down(): void
40:     {
41:         Schema::dropIfExists('product_variants');
42:     }
43: };
```

## File: bags-platform/backend/database/seeders/BranchSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Models\Branch;
 8: use Illuminate\Database\Seeder;
 9: 
10: class BranchSeeder extends Seeder
11: {
12:     public function run(): void
13:     {
14:         if (! app()->environment(['local', 'testing']) || Branch::query()->exists()) {
15:             return;
16:         }
17: 
18:         Branch::factory()->count(3)->create();
19:     }
20: }
```

## File: bags-platform/backend/database/seeders/CatalogPermissionSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\StaffRole;
 8: use Illuminate\Database\Seeder;
 9: use Illuminate\Support\Facades\DB;
10: use Spatie\Permission\Models\Permission;
11: use Spatie\Permission\Models\Role;
12: use Spatie\Permission\PermissionRegistrar;
13: 
14: class CatalogPermissionSeeder extends Seeder
15: {
16:     /**
17:      * Run the database seeds.
18:      */
19:     public function run(): void
20:     {
21:         app(PermissionRegistrar::class)->forgetCachedPermissions();
22: 
23:         DB::transaction(function (): void {
24:             $permissions = [
25:                 'categories.view', 'categories.create', 'categories.update', 'categories.delete',
26:                 'brands.view', 'brands.create', 'brands.update', 'brands.delete',
27:                 'products.view', 'products.create', 'products.update', 'products.delete',
28:                 'products.view_cost',     // يشوف سعر التكلفة
29:                 'products.publish',       // ينشر المنتج
30:             ];
31: 
32:             $created = [];
33:             foreach ($permissions as $permission) {
34:                 $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
35:             }
36: 
37:             Role::where('name', StaffRole::SuperAdmin->value)
38:                 ->where('guard_name', 'staff')
39:                 ->first()
40:                 ?->givePermissionTo($created);
41:         });
42:     }
43: }
```

## File: bags-platform/backend/database/seeders/CatalogSeeder.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Database\Seeders;
  6: 
  7: use App\Enums\SalesChannel;
  8: use App\Models\Brand;
  9: use App\Models\Category;
 10: use App\Models\Product;
 11: use App\Models\User;
 12: use App\Services\Catalog\ProductService;
 13: use Illuminate\Database\Seeder;
 14: use Illuminate\Support\Facades\DB;
 15: use Illuminate\Support\Facades\Hash;
 16: 
 17: /**
 18:  * بيانات كتالوج تجريبية: أقسام وبراندات ومنتجات مسودة (من غير صور، فمبتتنشرش).
 19:  */
 20: class CatalogSeeder extends Seeder
 21: {
 22:     public function run(): void
 23:     {
 24:         DB::transaction(function (): void {
 25:             $categories = $this->seedCategories();
 26:             $brands = $this->seedBrands();
 27:             $this->seedProducts($categories, $brands);
 28:         });
 29:     }
 30: 
 31:     /** @return array<string, Category> */
 32:     private function seedCategories(): array
 33:     {
 34:         $tree = [
 35:             ['name_ar' => 'شنط يد', 'name_en' => 'Handbags', 'slug' => 'handbags'],
 36:             ['name_ar' => 'محافظ', 'name_en' => 'Wallets', 'slug' => 'wallets'],
 37:             ['name_ar' => 'شنط سفر', 'name_en' => 'Luggage', 'slug' => 'luggage', 'children' => [
 38:                 ['name_ar' => 'كابينة', 'name_en' => 'Cabin', 'slug' => 'cabin'],
 39:                 ['name_ar' => 'متوسطة', 'name_en' => 'Medium', 'slug' => 'medium'],
 40:                 ['name_ar' => 'كبيرة', 'name_en' => 'Large', 'slug' => 'large'],
 41:             ]],
 42:             ['name_ar' => 'شنط ظهر', 'name_en' => 'Backpacks', 'slug' => 'backpacks'],
 43:         ];
 44: 
 45:         $categories = [];
 46:         foreach ($tree as $i => $node) {
 47:             $parent = Category::firstOrCreate(
 48:                 ['slug' => $node['slug']],
 49:                 ['name_ar' => $node['name_ar'], 'name_en' => $node['name_en'], 'sort_order' => $i, 'is_active' => true],
 50:             );
 51:             $categories[$node['slug']] = $parent;
 52:             foreach ($node['children'] ?? [] as $j => $child) {
 53:                 $categories[$child['slug']] = Category::firstOrCreate(
 54:                     ['slug' => $child['slug']],
 55:                     ['parent_id' => $parent->id, 'name_ar' => $child['name_ar'], 'name_en' => $child['name_en'], 'sort_order' => $j, 'is_active' => true],
 56:                 );
 57:             }
 58:         }
 59: 
 60:         return $categories;
 61:     }
 62: 
 63:     /** @return array<string, Brand> */
 64:     private function seedBrands(): array
 65:     {
 66:         $brands = [];
 67:         foreach ([
 68:             ['name_ar' => 'ميلانو', 'name_en' => 'Milano', 'slug' => 'milano'],
 69:             ['name_ar' => 'فوياجر', 'name_en' => 'Voyager', 'slug' => 'voyager'],
 70:             ['name_ar' => 'أطلس', 'name_en' => 'Atlas', 'slug' => 'atlas'],
 71:             ['name_ar' => 'لونا', 'name_en' => 'Luna', 'slug' => 'luna'],
 72:         ] as $i => $brand) {
 73:             $brands[$brand['slug']] = Brand::firstOrCreate(
 74:                 ['slug' => $brand['slug']],
 75:                 ['name_ar' => $brand['name_ar'], 'name_en' => $brand['name_en'], 'is_active' => true],
 76:             );
 77:         }
 78: 
 79:         return $brands;
 80:     }
 81: 
 82:     /**
 83:      * @param  array<string, Category>  $categories
 84:      * @param  array<string, Brand>  $brands
 85:      */
 86:     private function seedProducts(array $categories, array $brands): void
 87:     {
 88:         if (Product::query()->exists()) {
 89:             return; // البيانات التجريبية بتتزرع مرة واحدة بس
 90:         }
 91: 
 92:         $author = User::query()->first() ?? User::factory()->create([
 93:             'name' => 'Catalog Demo',
 94:             'email' => 'catalog-demo@alfarid.test',
 95:             'password' => Hash::make('password'),
 96:         ]);
 97: 
 98:         $service = app(ProductService::class);
 99:         foreach ($this->catalog($categories, $brands) as $data) {
100:             $service->create($data, $author);
101:         }
102:     }
103: 
104:     /**
105:      * @param  array<string, Category>  $categories
106:      * @param  array<string, Brand>  $brands
107:      * @return list<array<string, mixed>>
108:      */
109:     private function catalog(array $categories, array $brands): array
110:     {
111:         $variant = fn (string $ar, string $en, string $hex, float $cost, float $retail, float $wholesale, array $extra = []) => array_merge([
112:             'color_name_ar' => $ar, 'color_name_en' => $en, 'color_hex' => $hex,
113:             'cost_price' => $cost, 'retail_price' => $retail, 'wholesale_price' => $wholesale,
114:         ], $extra);
115:         $black = ['أسود', 'Black', '#000000'];
116:         $brown = ['بني', 'Brown', '#8B4513'];
117: 
118:         return [
119:             [
120:                 'category_id' => $categories['handbags']->id, 'brand_id' => $brands['milano']->id,
121:                 'name_ar' => 'حقيبة يد جلد كلاسيك', 'name_en' => 'Classic Leather Handbag',
122:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.9,
123:                 'variants' => [
124:                     $variant(...$black, cost: 800, retail: 1450, wholesale: 1200),
125:                     $variant(...$brown, cost: 800, retail: 1450, wholesale: 1200),
126:                 ],
127:             ],
128:             [
129:                 'category_id' => $categories['handbags']->id, 'brand_id' => $brands['luna']->id,
130:                 'name_ar' => 'حقيبة كروس صغيرة', 'name_en' => 'Mini Crossbody Bag',
131:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.4,
132:                 'variants' => [
133:                     $variant('وردي', 'Pink', '#E8A2B4', 350, 690, 560),
134:                     $variant('بيج', 'Beige', '#D9C7A7', 350, 690, 560),
135:                     $variant(...$black, cost: 350, retail: 690, wholesale: 560),
136:                 ],
137:             ],
138:             [
139:                 'category_id' => $categories['wallets']->id, 'brand_id' => $brands['atlas']->id,
140:                 'name_ar' => 'محفظة جلد طبيعي', 'name_en' => 'Leather Bifold Wallet',
141:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.15,
142:                 'variants' => [
143:                     $variant(...$black, cost: 220, retail: 450, wholesale: 370),
144:                     $variant(...$brown, cost: 220, retail: 450, wholesale: 370),
145:                 ],
146:             ],
147:             [
148:                 'category_id' => $categories['wallets']->id, 'brand_id' => $brands['milano']->id,
149:                 'name_ar' => 'محفظة كروت', 'name_en' => 'Card Holder',
150:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.08,
151:                 'variants' => [
152:                     $variant(...$black, cost: 120, retail: 260, wholesale: 210),
153:                     $variant('جملي', 'Tan', '#C68B4E', 120, 260, 210),
154:                     $variant('كحلي', 'Navy', '#1F2A5A', 120, 260, 210),
155:                 ],
156:             ],
157:             [
158:                 'category_id' => $categories['cabin']->id, 'brand_id' => $brands['voyager']->id,
159:                 'name_ar' => 'حقيبة سفر كابينة 20 بوصة', 'name_en' => 'Cabin Trolley 20',
160:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 2.6,
161:                 'variants' => [
162:                     $variant('فضي', 'Silver', '#C0C0C0', 1500, 2750, 2300, ['size' => '20"']),
163:                     $variant('أزرق', 'Blue', '#2563EB', 1500, 2750, 2300, ['size' => '20"']),
164:                 ],
165:             ],
166:             [
167:                 'category_id' => $categories['medium']->id, 'brand_id' => $brands['voyager']->id,
168:                 'name_ar' => 'حقيبة سفر متوسطة 24 بوصة', 'name_en' => 'Medium Suitcase 24',
169:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 3.4,
170:                 'variants' => [
171:                     $variant(...$black, cost: 1900, retail: 3450, wholesale: 2900, extra: ['size' => '24"']),
172:                     $variant('أحمر', 'Red', '#B91C1C', 1900, 3450, 2900, ['size' => '24"']),
173:                     $variant('رمادي', 'Gray', '#6B7280', 1900, 3450, 2900, ['size' => '24"']),
174:                 ],
175:             ],
176:             [
177:                 'category_id' => $categories['large']->id, 'brand_id' => $brands['atlas']->id,
178:                 'name_ar' => 'حقيبة سفر كبيرة 28 بوصة', 'name_en' => 'Large Suitcase 28',
179:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 4.2,
180:                 'variants' => [
181:                     $variant(...$black, cost: 2300, retail: 4200, wholesale: 3500, extra: ['size' => '28"']),
182:                     $variant('أزرق', 'Blue', '#2563EB', 2300, 4200, 3500, ['size' => '28"']),
183:                 ],
184:             ],
185:             [
186:                 'category_id' => $categories['backpacks']->id, 'brand_id' => $brands['voyager']->id,
187:                 'name_ar' => 'حقيبة ظهر سفر 30 لتر', 'name_en' => 'Travel Backpack 30L',
188:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 1.1,
189:                 'variants' => [
190:                     $variant('زيتي', 'Olive', '#556B2F', 600, 1150, 950, ['capacity' => '30L']),
191:                     $variant(...$black, cost: 600, retail: 1150, wholesale: 950, extra: ['capacity' => '30L']),
192:                 ],
193:             ],
194:             [
195:                 'category_id' => $categories['backpacks']->id, 'brand_id' => $brands['atlas']->id,
196:                 'name_ar' => 'حقيبة ظهر لابتوب', 'name_en' => 'Laptop Backpack',
197:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.95,
198:                 'variants' => [
199:                     $variant('رمادي', 'Gray', '#6B7280', 520, 990, 820, ['capacity' => '15L']),
200:                     $variant('كحلي', 'Navy', '#1F2A5A', 520, 990, 820, ['capacity' => '15L']),
201:                     $variant(...$black, cost: 520, retail: 990, wholesale: 820, extra: ['capacity' => '15L']),
202:                 ],
203:             ],
204:             [
205:                 'category_id' => $categories['handbags']->id, 'brand_id' => $brands['luna']->id,
206:                 'name_ar' => 'حقيبة توت قماش', 'name_en' => 'Canvas Tote Bag',
207:                 'sales_channel' => SalesChannel::Both->value, 'weight_kg' => 0.3,
208:                 'variants' => [
209:                     $variant('بيج', 'Beige', '#D9C7A7', 180, 390, 320),
210:                     $variant('أبيض', 'White', '#FFFFFF', 180, 390, 320),
211:                 ],
212:             ],
213:         ];
214:     }
215: }
```

## File: bags-platform/backend/database/seeders/DatabaseSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Models\User;
 8: use Illuminate\Database\Console\Seeds\WithoutModelEvents;
 9: use Illuminate\Database\Seeder;
10: 
11: class DatabaseSeeder extends Seeder
12: {
13:     use WithoutModelEvents;
14: 
15:     /**
16:      * Seed the application's database.
17:      */
18:     public function run(): void
19:     {
20:         $this->call(SettingSeeder::class);
21:         $this->call(BranchSeeder::class);
22:         $this->call(LocationSeeder::class);
23:         $this->call(RolePermissionSeeder::class);
24:         $this->call(CatalogPermissionSeeder::class);
25: 
26:         if (app()->environment(['local', 'testing'])) {
27:             $testUser = User::factory()->make([
28:                 'name' => 'Test User',
29:                 'email' => 'test@example.com',
30:             ]);
31: 
32:             User::query()->firstOrCreate(
33:                 ['email' => 'test@example.com'],
34:                 $testUser->getAttributes(),
35:             );
36: 
37:             // بيانات الكتالوج التجريبية للبيئات المحلية والاختبار بس
38:             $this->call(CatalogSeeder::class);
39:         }
40:     }
41: }
```

## File: bags-platform/backend/database/seeders/LocationSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\LocationType;
 8: use App\Models\Branch;
 9: use App\Models\Location;
10: use Illuminate\Database\Seeder;
11: 
12: class LocationSeeder extends Seeder
13: {
14:     public function run(): void
15:     {
16:         if (! app()->environment(['local', 'testing'])) {
17:             return;
18:         }
19: 
20:         $now = now();
21:         $rows = [];
22: 
23:         Branch::query()->orderBy('id')->get(['id'])->each(function (Branch $branch) use (&$rows, $now): void {
24:             foreach ([1, 2, 3] as $number) {
25:                 $rows[] = [
26:                     'branch_id' => $branch->id,
27:                     'name' => "Shelf {$number}",
28:                     'code' => sprintf('A-01-%02d', $number),
29:                     'type' => LocationType::Shelf->value,
30:                     'is_active' => true,
31:                     'created_at' => $now,
32:                     'updated_at' => $now,
33:                 ];
34:             }
35:         });
36: 
37:         if ($rows !== []) {
38:             Location::query()->insertOrIgnore($rows);
39:         }
40:     }
41: }
```

## File: bags-platform/backend/database/seeders/RolePermissionSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Enums\StaffRole;
 8: use Illuminate\Database\Seeder;
 9: use Spatie\Permission\Models\Permission;
10: use Spatie\Permission\Models\Role;
11: 
12: class RolePermissionSeeder extends Seeder
13: {
14:     /**
15:      * @var array<string, list<string>>
16:      */
17:     private const PERMISSIONS_BY_MODULE = [
18:         'branches' => ['view', 'create', 'update', 'delete', 'manage'],
19:         'locations' => ['view', 'create', 'update', 'delete', 'manage'],
20:         'users' => ['view', 'create', 'update', 'delete', 'manage'],
21:         'roles' => ['view', 'create', 'update', 'delete', 'manage'],
22:         'permissions' => ['view'],
23:         'customers' => ['view', 'create', 'update', 'delete', 'manage'],
24:         'suppliers' => ['view', 'create', 'update', 'delete', 'manage'],
25:         'products' => ['view', 'create', 'update', 'delete', 'manage'],
26:         'categories' => ['view', 'create', 'update', 'delete', 'manage'],
27:         'inventory' => ['view', 'create', 'update', 'delete', 'manage'],
28:         'warehouses' => ['view', 'create', 'update', 'delete', 'manage'],
29:         'orders' => ['view', 'create', 'update', 'delete', 'manage'],
30:         'sales' => ['view', 'create', 'update', 'delete', 'manage'],
31:         'purchases' => ['view', 'create', 'update', 'delete', 'manage'],
32:         'returns' => ['view', 'create', 'update', 'delete', 'manage'],
33:         'payments' => ['view', 'create', 'update', 'delete', 'manage'],
34:         'shipping' => ['view', 'create', 'update', 'delete', 'manage'],
35:         'transfers' => ['view', 'create', 'update', 'delete', 'manage'],
36:         'reports' => ['view', 'export'],
37:         'notifications' => ['view', 'create', 'update', 'delete', 'manage'],
38:         'pricing' => ['view', 'create', 'update', 'delete', 'manage'],
39:         'settings' => ['view', 'update', 'manage'],
40:         'pos' => ['access', 'sales.view', 'sales.create', 'sales.refund'],
41:     ];
42: 
43:     public function run(): void
44:     {
45:         foreach (self::PERMISSIONS_BY_MODULE as $module => $actions) {
46:             foreach ($actions as $action) {
47:                 Permission::findOrCreate("{$module}.{$action}", 'staff');
48:             }
49:         }
50: 
51:         $superAdmin = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
52:         $superAdmin->syncPermissions(
53:             Permission::query()->where('guard_name', 'staff')->get(),
54:         );
55:     }
56: }
```

## File: bags-platform/backend/database/seeders/SettingSeeder.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Database\Seeders;
 6: 
 7: use App\Models\Setting;
 8: use App\Services\SettingService;
 9: use Illuminate\Database\Seeder;
10: use Illuminate\Support\Facades\DB;
11: 
12: class SettingSeeder extends Seeder
13: {
14:     /**
15:      * @var list<array{group: string, key: string, value: string|null, type: string}>
16:      */
17:     private const SETTINGS = [
18:         ['group' => 'company', 'key' => 'company_name', 'value' => 'Alfarid', 'type' => 'string'],
19:         ['group' => 'company', 'key' => 'company_email', 'value' => null, 'type' => 'string'],
20:         ['group' => 'company', 'key' => 'company_phone', 'value' => null, 'type' => 'string'],
21:         ['group' => 'company', 'key' => 'company_address', 'value' => null, 'type' => 'string'],
22:         ['group' => 'company', 'key' => 'company_logo', 'value' => null, 'type' => 'string'],
23:         ['group' => 'company', 'key' => 'commercial_register', 'value' => null, 'type' => 'string'],
24: 
25:         ['group' => 'tax', 'key' => 'tax_enabled', 'value' => '0', 'type' => 'boolean'],
26:         ['group' => 'tax', 'key' => 'tax_number', 'value' => null, 'type' => 'string'],
27:         ['group' => 'tax', 'key' => 'default_tax_rate', 'value' => '14', 'type' => 'float'],
28:         ['group' => 'tax', 'key' => 'prices_include_tax', 'value' => '0', 'type' => 'boolean'],
29: 
30:         ['group' => 'numbering', 'key' => 'invoice_prefix', 'value' => 'INV-', 'type' => 'string'],
31:         ['group' => 'numbering', 'key' => 'invoice_next_number', 'value' => '1000', 'type' => 'integer'],
32:         ['group' => 'numbering', 'key' => 'invoice_padding', 'value' => '6', 'type' => 'integer'],
33:         ['group' => 'numbering', 'key' => 'order_prefix', 'value' => 'ORD-', 'type' => 'string'],
34:         ['group' => 'numbering', 'key' => 'order_next_number', 'value' => '1000', 'type' => 'integer'],
35:         ['group' => 'numbering', 'key' => 'order_padding', 'value' => '6', 'type' => 'integer'],
36:         ['group' => 'numbering', 'key' => 'purchase_order_prefix', 'value' => 'PO-', 'type' => 'string'],
37:         ['group' => 'numbering', 'key' => 'purchase_order_next_number', 'value' => '1000', 'type' => 'integer'],
38:         ['group' => 'numbering', 'key' => 'purchase_order_padding', 'value' => '6', 'type' => 'integer'],
39:         ['group' => 'numbering', 'key' => 'quotation_prefix', 'value' => 'QT-', 'type' => 'string'],
40:         ['group' => 'numbering', 'key' => 'quotation_next_number', 'value' => '1000', 'type' => 'integer'],
41:         ['group' => 'numbering', 'key' => 'quotation_padding', 'value' => '6', 'type' => 'integer'],
42:     ];
43: 
44:     public function run(): void
45:     {
46:         $now = now();
47:         $rows = array_map(
48:             static fn (array $setting): array => [
49:                 ...$setting,
50:                 'created_at' => $now,
51:                 'updated_at' => $now,
52:             ],
53:             self::SETTINGS,
54:         );
55: 
56:         Setting::query()->insertOrIgnore($rows);
57:         DB::afterCommit(fn () => app(SettingService::class)->clearCache());
58:     }
59: }
```

## File: bags-platform/backend/routes/api/admin.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: use App\Http\Controllers\Api\V1\Admin\AuthController;
  6: use App\Http\Controllers\Api\V1\Admin\BranchController;
  7: use App\Http\Controllers\Api\V1\Admin\BrandController;
  8: use App\Http\Controllers\Api\V1\Admin\CategoryController;
  9: use App\Http\Controllers\Api\V1\Admin\LocationController;
 10: use App\Http\Controllers\Api\V1\Admin\PermissionController;
 11: use App\Http\Controllers\Api\V1\Admin\ProductController;
 12: use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
 13: use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
 14: use App\Http\Controllers\Api\V1\Admin\RoleController;
 15: use App\Http\Controllers\Api\V1\Admin\SettingController;
 16: use App\Http\Controllers\Api\V1\Admin\UserController;
 17: use Illuminate\Support\Facades\Route;
 18: 
 19: // Public admin authentication endpoint.
 20: Route::post('auth/login', [AuthController::class, 'login'])
 21:     ->middleware('throttle:admin-login')
 22:     ->name('admin.auth.login');
 23: 
 24: // Staff-only API endpoints: require a Sanctum token and an active staff account.
 25: Route::middleware(['auth:sanctum', 'auth.staff'])
 26:     ->name('admin.')
 27:     ->group(function (): void {
 28:         Route::get('me', [AuthController::class, 'me'])->name('auth.me');
 29:         Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
 30: 
 31:         Route::apiResource('branches', BranchController::class)
 32:             ->only(['index', 'show'])
 33:             ->middleware('permission:branches.view|branches.manage');
 34:         Route::apiResource('branches', BranchController::class)
 35:             ->only(['store'])
 36:             ->middleware('permission:branches.create|branches.manage');
 37:         Route::apiResource('branches', BranchController::class)
 38:             ->only(['update'])
 39:             ->middleware('permission:branches.update|branches.manage');
 40:         Route::apiResource('branches', BranchController::class)
 41:             ->only(['destroy'])
 42:             ->middleware('permission:branches.delete|branches.manage');
 43: 
 44:         Route::scopeBindings()->group(function (): void {
 45:             Route::apiResource('branches.locations', LocationController::class)
 46:                 ->only(['index', 'show'])
 47:                 ->middleware('permission:locations.view|locations.manage');
 48:             Route::apiResource('branches.locations', LocationController::class)
 49:                 ->only(['store'])
 50:                 ->middleware('permission:locations.create|locations.manage');
 51:             Route::apiResource('branches.locations', LocationController::class)
 52:                 ->only(['update'])
 53:                 ->middleware('permission:locations.update|locations.manage');
 54:             Route::apiResource('branches.locations', LocationController::class)
 55:                 ->only(['destroy'])
 56:                 ->middleware('permission:locations.delete|locations.manage');
 57:         });
 58: 
 59:         Route::apiResource('users', UserController::class)
 60:             ->only(['index', 'show'])
 61:             ->middleware('permission:users.view|users.manage');
 62:         Route::apiResource('users', UserController::class)
 63:             ->only(['store'])
 64:             ->middleware('permission:users.create|users.manage');
 65:         Route::apiResource('users', UserController::class)
 66:             ->only(['update'])
 67:             ->middleware('permission:users.update|users.manage');
 68:         Route::apiResource('users', UserController::class)
 69:             ->only(['destroy'])
 70:             ->middleware('permission:users.delete|users.manage');
 71: 
 72:         Route::apiResource('roles', RoleController::class)
 73:             ->only(['index', 'show'])
 74:             ->middleware('permission:roles.view|roles.manage');
 75:         Route::apiResource('roles', RoleController::class)
 76:             ->only(['store'])
 77:             ->middleware('permission:roles.create|roles.manage');
 78:         Route::apiResource('roles', RoleController::class)
 79:             ->only(['update'])
 80:             ->middleware('permission:roles.update|roles.manage');
 81:         Route::apiResource('roles', RoleController::class)
 82:             ->only(['destroy'])
 83:             ->middleware('permission:roles.delete|roles.manage');
 84: 
 85:         Route::get('permissions', [PermissionController::class, 'index'])
 86:             ->middleware('permission:permissions.view|roles.manage')
 87:             ->name('permissions.index');
 88: 
 89:         // Catalog: authorization is handled in the controllers via can:* middleware.
 90:         // مهم: reorder قبل apiResource عشان ميتقريش كأنه {category}
 91:         Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
 92:         Route::apiResource('categories', CategoryController::class);
 93: 
 94:         Route::apiResource('brands', BrandController::class);
 95: 
 96:         Route::apiResource('products', ProductController::class);
 97:         Route::apiResource('products.variants', ProductVariantController::class)->scoped();
 98: 
 99:         Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
100:         Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
101:         Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->name('products.media.store');
102:         Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');
103: 
104:         Route::get('settings', [SettingController::class, 'index'])
105:             ->middleware('permission:settings.view|settings.manage')
106:             ->name('settings.index');
107:         Route::put('settings', [SettingController::class, 'update'])
108:             ->middleware('permission:settings.update|settings.manage')
109:             ->name('settings.update');
110:     });
```

## File: bags-platform/backend/tests/Feature/Admin/Catalog/CatalogAuditTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature\Admin\Catalog;
  6: 
  7: use App\Enums\ProductStatus;
  8: use App\Models\Brand;
  9: use App\Models\Category;
 10: use App\Models\Product;
 11: use App\Models\ProductVariant;
 12: use App\Models\User;
 13: use App\Services\Catalog\BrandService;
 14: use App\Services\Catalog\CategoryService;
 15: use Database\Seeders\DatabaseSeeder;
 16: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 17: use Illuminate\Http\UploadedFile;
 18: use Illuminate\Support\Facades\Auth;
 19: use Illuminate\Support\Facades\Storage;
 20: use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
 21: use Spatie\Permission\Models\Permission;
 22: use Tests\TestCase;
 23: 
 24: class CatalogAuditTest extends TestCase
 25: {
 26:     use LazilyRefreshDatabase;
 27: 
 28:     protected function setUp(): void
 29:     {
 30:         parent::setUp();
 31:         Storage::fake('public');
 32:     }
 33: 
 34:     private function signIn(array $permissions): void
 35:     {
 36:         Auth::forgetGuards();
 37:         $staff = User::factory()->create();
 38:         foreach ($permissions as $permission) {
 39:             Permission::findOrCreate($permission, 'staff');
 40:         }
 41:         $staff->givePermissionTo($permissions);
 42:         $this->withToken($staff->createToken('audit')->plainTextToken);
 43:     }
 44: 
 45:     public function test_nested_cost_is_ignored_without_permission(): void
 46:     {
 47:         $this->signIn(['products.create']);
 48:         $response = $this->postJson('/api/v1/admin/products', [
 49:             'category_id' => Category::factory()->create()->id,
 50:             'name_ar' => 'حقيبة', 'name_en' => 'Bag', 'sales_channel' => 'retail',
 51:             'variants' => [['cost_price' => 999, 'retail_price' => 1200]],
 52:         ])->assertCreated();
 53:         $this->assertArrayNotHasKey('cost_price', $response->json('data.variants.0'));
 54:         $this->assertDatabaseHas('product_variants', ['product_id' => $response->json('data.id'), 'cost_price' => 0]);
 55:     }
 56: 
 57:     public function test_nested_variants_cannot_override_their_product_or_inject_columns(): void
 58:     {
 59:         $this->signIn(['products.create', 'products.view_cost']);
 60:         $other = Product::factory()->create();
 61:         $this->postJson('/api/v1/admin/products', [
 62:             'category_id' => $other->category_id, 'name_ar' => 'حقيبة', 'name_en' => 'Bag',
 63:             'sales_channel' => 'retail', 'variants' => [['product_id' => $other->id, 'id' => 800]],
 64:         ])->assertUnprocessable()->assertJsonValidationErrors('variants.0');
 65:         $this->assertSame(0, $other->variants()->count());
 66:         $this->assertDatabaseCount('products', 1);
 67:     }
 68: 
 69:     public function test_duplicate_nested_identifiers_are_validation_errors(): void
 70:     {
 71:         $this->signIn(['products.create']);
 72:         $this->postJson('/api/v1/admin/products', [
 73:             'category_id' => Category::factory()->create()->id,
 74:             'name_ar' => 'حقيبة', 'name_en' => 'Bag', 'sales_channel' => 'retail',
 75:             'variants' => [
 76:                 ['sku' => 'REPEATED', 'barcode' => '123', 'color_name_en' => 'Red'],
 77:                 ['sku' => 'REPEATED', 'barcode' => '123', 'color_name_en' => 'Blue'],
 78:             ],
 79:         ])->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku', 'variants.1.barcode']);
 80:         $this->assertDatabaseCount('products', 0);
 81:     }
 82: 
 83:     public function test_empty_product_slug_is_regenerated_on_update(): void
 84:     {
 85:         $this->signIn(['products.update']);
 86:         $product = Product::factory()->create();
 87:         $this->putJson("/api/v1/admin/products/{$product->id}", ['slug' => null, 'name_en' => 'New Bag'])
 88:             ->assertOk()->assertJsonPath('data.slug', 'new-bag');
 89:     }
 90: 
 91:     public function test_empty_variant_sku_returns_validation_error(): void
 92:     {
 93:         $this->signIn(['products.update']);
 94:         $variant = ProductVariant::factory()->create();
 95:         $this->putJson("/api/v1/admin/products/{$variant->product_id}/variants/{$variant->id}", ['sku' => null])
 96:             ->assertUnprocessable()->assertJsonValidationErrors('sku');
 97:         $this->assertSame($variant->sku, $variant->fresh()->sku);
 98:     }
 99: 
100:     public function test_variant_cost_writes_require_permission(): void
101:     {
102:         $this->signIn(['products.update']);
103:         $product = Product::factory()->create();
104:         $created = $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['cost_price' => 500])
105:             ->assertCreated();
106:         $id = $created->json('data.id');
107:         $this->putJson("/api/v1/admin/products/{$product->id}/variants/{$id}", ['cost_price' => 700])
108:             ->assertOk();
109:         $this->assertDatabaseHas('product_variants', ['id' => $id, 'cost_price' => 0]);
110:     }
111: 
112:     public function test_create_with_invalid_media_rolls_back_category_and_brand(): void
113:     {
114:         foreach ([CategoryService::class => Category::class, BrandService::class => Brand::class] as $service => $model) {
115:             try {
116:                 app($service)->create(['name_ar' => 'اختبار', 'name_en' => 'Rollback'], UploadedFile::fake()->create('bad.txt', 1, 'text/plain'));
117:                 $this->fail('Invalid media must fail.');
118:             } catch (FileUnacceptableForCollection) {
119:                 $this->assertSame(0, $model::where('name_en', 'Rollback')->count());
120:             }
121:         }
122:     }
123: 
124:     public function test_update_with_invalid_media_rolls_back_category_and_brand_changes(): void
125:     {
126:         foreach ([CategoryService::class => Category::class, BrandService::class => Brand::class] as $service => $model) {
127:             $record = $model::factory()->create(['name_en' => 'Original']);
128:             try {
129:                 app($service)->update($record, ['name_en' => 'Changed'], UploadedFile::fake()->create('bad.txt', 1, 'text/plain'));
130:                 $this->fail('Invalid media must fail.');
131:             } catch (FileUnacceptableForCollection) {
132:                 $this->assertSame('Original', $record->fresh()->name_en);
133:             }
134:         }
135:     }
136: 
137:     public function test_reordering_invalid_subtree_rolls_back_all_items(): void
138:     {
139:         $this->signIn(['categories.update']);
140:         $root = Category::factory()->create(['sort_order' => 0]);
141:         $child = Category::factory()->create(['parent_id' => $root->id]);
142:         $leaf = Category::factory()->create(['parent_id' => $child->id]);
143:         $this->putJson('/api/v1/admin/categories/reorder', ['items' => [
144:             ['id' => $root->id, 'parent_id' => null, 'sort_order' => 50],
145:             ['id' => $root->id, 'parent_id' => $leaf->id, 'sort_order' => 1],
146:         ]])->assertUnprocessable();
147:         $this->assertSame(0, $root->fresh()->sort_order);
148:         // A distinct second item makes this fail inside the service after the first update.
149:         $other = Category::factory()->create(['sort_order' => 0]);
150:         $this->putJson('/api/v1/admin/categories/reorder', ['items' => [
151:             ['id' => $other->id, 'parent_id' => null, 'sort_order' => 50],
152:             ['id' => $root->id, 'parent_id' => $leaf->id, 'sort_order' => 1],
153:         ]])->assertUnprocessable();
154:         $this->assertSame(0, $other->fresh()->sort_order);
155:     }
156: 
157:     public function test_moving_a_subtree_cannot_exceed_three_levels(): void
158:     {
159:         $this->signIn(['categories.update']);
160:         $root = Category::factory()->create();
161:         $child = Category::factory()->create(['parent_id' => $root->id]);
162:         $otherRoot = Category::factory()->create();
163:         $otherChild = Category::factory()->create(['parent_id' => $otherRoot->id]);
164:         $this->putJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $otherChild->id])->assertUnprocessable();
165:         $this->assertNull($root->fresh()->parent_id);
166:         $this->assertSame($root->id, $child->fresh()->parent_id);
167:     }
168: 
169:     public function test_brand_supports_svg_and_rejects_deletion_with_products(): void
170:     {
171:         $this->signIn(['brands.create', 'brands.delete']);
172:         $logo = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');
173:         $response = $this->post('/api/v1/admin/brands', ['name_ar' => 'براند', 'name_en' => 'Brand', 'logo' => $logo])->assertCreated();
174:         $this->assertNotEmpty($response->json('data.logo_url'));
175:         $brandId = $response->json('data.id');
176:         Product::factory()->create(['brand_id' => $brandId]);
177:         $this->deleteJson("/api/v1/admin/brands/{$brandId}")->assertUnprocessable();
178:     }
179: 
180:     public function test_last_active_variant_of_published_product_is_protected(): void
181:     {
182:         $this->signIn(['products.update']);
183:         $product = Product::factory()->published()->create();
184:         $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
185:         $url = "/api/v1/admin/products/{$product->id}/variants/{$variant->id}";
186:         $this->putJson($url, ['is_active' => false])->assertUnprocessable();
187:         $this->deleteJson($url)->assertUnprocessable();
188:         $this->assertTrue($variant->fresh()->is_active);
189:         $this->assertFalse($variant->fresh()->trashed());
190:     }
191: 
192:     public function test_media_conversions_reorder_and_last_image_protection(): void
193:     {
194:         $this->signIn(['products.view', 'products.update']);
195:         $product = Product::factory()->create();
196:         $url = "/api/v1/admin/products/{$product->id}/media";
197:         $uploaded = $this->post($url, ['collection' => 'images', 'files' => [
198:             UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->image('second.jpg'),
199:         ]])->assertCreated();
200:         $first = $uploaded->json('data.0.id');
201:         $second = $uploaded->json('data.1.id');
202:         $this->assertNotEmpty($uploaded->json('data.0.thumb_url'));
203:         $this->assertNotEmpty($uploaded->json('data.0.medium_url'));
204:         $this->putJson($url.'/reorder', ['ids' => [$second, $first]])->assertOk();
205:         $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $second);
206:         $product->update(['status' => ProductStatus::Published]);
207:         $this->deleteJson($url.'/'.$first)->assertOk();
208:         $this->deleteJson($url.'/'.$second)->assertUnprocessable();
209:     }
210: 
211:     public function test_reordering_foreign_media_is_rejected_without_changes(): void
212:     {
213:         $this->signIn(['products.update']);
214:         $product = Product::factory()->create();
215:         $other = Product::factory()->create();
216:         $media = $other->addMedia(UploadedFile::fake()->image('other.jpg'))->toMediaCollection('images');
217:         $this->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [$media->id]])->assertNotFound();
218:         $this->assertSame($other->id, $media->fresh()->model_id);
219:     }
220: 
221:     public function test_variant_video_is_rejected(): void
222:     {
223:         $this->signIn(['products.update']);
224:         $variant = ProductVariant::factory()->create();
225:         $this->post("/api/v1/admin/products/{$variant->product_id}/media", [
226:             'collection' => 'video', 'variant_id' => $variant->id,
227:             'file' => UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'),
228:         ])->assertUnprocessable();
229:         $this->assertDatabaseCount('media', 0);
230:     }
231: 
232:     public function test_publishing_requires_permission_and_rolls_back_edits(): void
233:     {
234:         $this->signIn(['products.update']);
235:         $product = Product::factory()->create(['name_en' => 'Original']);
236:         $this->putJson("/api/v1/admin/products/{$product->id}", ['status' => 'published', 'name_en' => 'Changed'])
237:             ->assertForbidden();
238:         $this->assertSame('Original', $product->fresh()->name_en);
239:         $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
240:     }
241: 
242:     public function test_product_delete_soft_deletes_all_variants(): void
243:     {
244:         $this->signIn(['products.delete']);
245:         $variant = ProductVariant::factory()->create();
246:         $this->deleteJson("/api/v1/admin/products/{$variant->product_id}")->assertOk();
247:         $this->assertSoftDeleted('products', ['id' => $variant->product_id]);
248:         $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
249:     }
250: 
251:     public function test_non_positive_pagination_is_clamped(): void
252:     {
253:         $this->signIn(['categories.view', 'brands.view', 'products.view']);
254:         foreach (['categories', 'brands', 'products'] as $resource) {
255:             $this->getJson("/api/v1/admin/{$resource}?per_page=-1")->assertOk()->assertJsonPath('meta.per_page', 1);
256:             $this->getJson("/api/v1/admin/{$resource}?per_page=101")->assertOk()->assertJsonPath('meta.per_page', 100);
257:         }
258:     }
259: 
260:     public function test_catalog_seed_matches_plan_and_is_repeatable(): void
261:     {
262:         $this->seed(DatabaseSeeder::class);
263:         $this->seed(DatabaseSeeder::class);
264:         $this->assertDatabaseCount('categories', 7);
265:         $this->assertDatabaseCount('brands', 4);
266:         $this->assertDatabaseCount('products', 10);
267:         $this->assertSame(3, Category::where('slug', 'luggage')->firstOrFail()->children()->count());
268:         foreach (Product::with('variants')->get() as $product) {
269:             $this->assertSame(ProductStatus::Draft, $product->status);
270:             $this->assertContains($product->variants->count(), [2, 3]);
271:             $this->assertSame('P'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT), $product->code);
272:             foreach ($product->variants as $variant) {
273:                 $this->assertStringStartsWith($product->code.'-', $variant->sku);
274:                 $this->assertMatchesRegularExpression('/^2[0-9]{12}$/', $variant->barcode);
275:             }
276:         }
277:     }
278: }
```

## File: bags-platform/backend/tests/Feature/Admin/Catalog/CategoryCatalogTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature\Admin\Catalog;
  6: 
  7: use App\Models\Category;
  8: use App\Models\Product;
  9: use App\Models\User;
 10: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 11: use Illuminate\Http\UploadedFile;
 12: use Illuminate\Support\Facades\Auth;
 13: use Illuminate\Support\Facades\Storage;
 14: use Illuminate\Testing\TestResponse;
 15: use Spatie\Permission\Models\Permission;
 16: use Tests\TestCase;
 17: 
 18: class CategoryCatalogTest extends TestCase
 19: {
 20:     use LazilyRefreshDatabase;
 21: 
 22:     private const ALL_PERMISSIONS = [
 23:         'categories.view', 'categories.create', 'categories.update', 'categories.delete',
 24:     ];
 25: 
 26:     public function test_staff_without_create_permission_gets_403(): void
 27:     {
 28:         $viewer = $this->staffWithPermissions(['categories.view']);
 29:         $token = $viewer->createToken('test')->plainTextToken;
 30: 
 31:         $this->requestWithToken($token, 'POST', '/api/v1/admin/categories', [
 32:             'name_ar' => 'قسم', 'name_en' => 'Category',
 33:         ])->assertForbidden();
 34:     }
 35: 
 36:     public function test_create_category_with_image_returns_201_and_image_url(): void
 37:     {
 38:         Storage::fake('public');
 39:         $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
 40:         $token = $admin->createToken('test')->plainTextToken;
 41: 
 42:         $response = $this->uploadWithToken($token, '/api/v1/admin/categories', [
 43:             'name_ar' => 'شنط يد',
 44:             'name_en' => 'Handbags',
 45:             'image' => UploadedFile::fake()->image('handbags.jpg'),
 46:         ]);
 47: 
 48:         $response->assertCreated()
 49:             ->assertJsonPath('data.name_en', 'Handbags')
 50:             ->assertJsonPath('data.slug', 'handbags');
 51:         $this->assertNotEmpty($response->json('data.image_url'));
 52:     }
 53: 
 54:     public function test_category_cannot_be_its_own_parent(): void
 55:     {
 56:         $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
 57:         $token = $admin->createToken('test')->plainTextToken;
 58:         $category = Category::factory()->create();
 59: 
 60:         $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$category->id}", [
 61:             'parent_id' => $category->id,
 62:         ])->assertUnprocessable();
 63:     }
 64: 
 65:     public function test_category_cannot_move_under_its_descendant(): void
 66:     {
 67:         $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
 68:         $token = $admin->createToken('test')->plainTextToken;
 69:         $root = Category::factory()->create();
 70:         $child = Category::factory()->create(['parent_id' => $root->id]);
 71:         $grandchild = Category::factory()->create(['parent_id' => $child->id]);
 72: 
 73:         $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$root->id}", [
 74:             'parent_id' => $grandchild->id,
 75:         ])->assertUnprocessable();
 76:     }
 77: 
 78:     public function test_creating_a_fourth_level_category_is_rejected(): void
 79:     {
 80:         $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
 81:         $token = $admin->createToken('test')->plainTextToken;
 82:         $level1 = Category::factory()->create();
 83:         $level2 = Category::factory()->create(['parent_id' => $level1->id]);
 84:         $level3 = Category::factory()->create(['parent_id' => $level2->id]);
 85: 
 86:         $this->requestWithToken($token, 'POST', '/api/v1/admin/categories', [
 87:             'name_ar' => 'مستوى رابع', 'name_en' => 'Level Four', 'parent_id' => $level3->id,
 88:         ])->assertUnprocessable();
 89:     }
 90: 
 91:     public function test_deleting_a_category_with_products_is_rejected(): void
 92:     {
 93:         $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
 94:         $token = $admin->createToken('test')->plainTextToken;
 95:         $category = Category::factory()->create();
 96:         Product::factory()->create(['category_id' => $category->id]);
 97: 
 98:         $this->requestWithToken($token, 'DELETE', "/api/v1/admin/categories/{$category->id}")
 99:             ->assertUnprocessable();
100:     }
101: 
102:     public function test_tree_returns_correctly_nested_categories(): void
103:     {
104:         $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
105:         $token = $admin->createToken('test')->plainTextToken;
106:         $root = Category::factory()->create(['name_en' => 'Luggage']);
107:         $child = Category::factory()->create(['parent_id' => $root->id]);
108:         $grandchild = Category::factory()->create(['parent_id' => $child->id]);
109:         Category::factory()->create(['name_en' => 'Other Root']);
110: 
111:         $response = $this->requestWithToken($token, 'GET', '/api/v1/admin/categories?tree=1');
112:         $response->assertOk();
113: 
114:         $treeRoot = collect($response->json('data'))->firstWhere('id', $root->id);
115:         $this->assertNotNull($treeRoot);
116:         $this->assertSame($child->id, $treeRoot['children'][0]['id']);
117:         $this->assertSame($grandchild->id, $treeRoot['children'][0]['children'][0]['id']);
118:     }
119: 
120:     /** @param list<string> $permissions */
121:     private function staffWithPermissions(array $permissions): User
122:     {
123:         foreach ($permissions as $permission) {
124:             Permission::findOrCreate($permission, 'staff');
125:         }
126:         $staff = User::factory()->create();
127:         $staff->givePermissionTo($permissions);
128: 
129:         return $staff;
130:     }
131: 
132:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
133:     {
134:         Auth::forgetGuards();
135: 
136:         return $this->withToken($token)->json($method, $uri, $payload);
137:     }
138: 
139:     private function uploadWithToken(string $token, string $uri, array $payload): TestResponse
140:     {
141:         Auth::forgetGuards();
142: 
143:         return $this->withToken($token)->post($uri, $payload);
144:     }
145: }
```

## File: bags-platform/backend/tests/Feature/Admin/Catalog/ProductCatalogTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature\Admin\Catalog;
  6: 
  7: use App\Models\Category;
  8: use App\Models\User;
  9: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 10: use Illuminate\Http\UploadedFile;
 11: use Illuminate\Support\Facades\Auth;
 12: use Illuminate\Support\Facades\Storage;
 13: use Illuminate\Testing\TestResponse;
 14: use Spatie\Permission\Models\Permission;
 15: use Tests\TestCase;
 16: 
 17: class ProductCatalogTest extends TestCase
 18: {
 19:     use LazilyRefreshDatabase;
 20: 
 21:     private const ALL_PERMISSIONS = [
 22:         'products.view', 'products.create', 'products.update', 'products.delete',
 23:         'products.publish', 'products.view_cost',
 24:     ];
 25: 
 26:     public function test_creating_a_product_generates_code_and_slug(): void
 27:     {
 28:         $token = $this->adminToken();
 29:         $category = Category::factory()->create();
 30: 
 31:         $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
 32:             'category_id' => $category->id,
 33:             'name_ar' => 'حقيبة جلد',
 34:             'name_en' => 'Leather Handbag',
 35:             'sales_channel' => 'retail',
 36:         ])->assertCreated()
 37:             ->assertJsonPath('data.slug', 'leather-handbag')
 38:             ->assertJsonPath('data.status', 'draft');
 39: 
 40:         // الكود = P + رقم المنتج مبطّن لخمس خانات (أول منتج في قاعدة فاضية بيبقى P00001)
 41:         $this->assertMatchesRegularExpression('/^P\d{5}$/', (string) $response->json('data.code'));
 42:         $this->assertSame(
 43:             'P'.str_pad((string) $response->json('data.id'), 5, '0', STR_PAD_LEFT),
 44:             $response->json('data.code'),
 45:         );
 46:     }
 47: 
 48:     public function test_publishing_without_prices_fails_with_error_messages(): void
 49:     {
 50:         $token = $this->adminToken();
 51:         $productId = $this->createProduct($token, [
 52:             'variants' => [['color_name_en' => 'Red', 'size' => 'M']],
 53:         ]);
 54: 
 55:         $response = $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
 56:             'status' => 'published',
 57:         ]);
 58: 
 59:         $response->assertUnprocessable();
 60:         $errors = $response->json('errors.status') ?? [];
 61:         $this->assertNotEmpty($errors);
 62:         $this->assertStringContainsString('سعر قطاعي', implode(' ', $errors));
 63:     }
 64: 
 65:     public function test_publishing_without_an_image_fails_with_error_messages(): void
 66:     {
 67:         $token = $this->adminToken();
 68:         $productId = $this->createProduct($token, [
 69:             'variants' => [['color_name_en' => 'Red', 'retail_price' => 100]],
 70:         ]);
 71: 
 72:         $response = $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
 73:             'status' => 'published',
 74:         ]);
 75: 
 76:         $response->assertUnprocessable();
 77:         $errors = $response->json('errors.status') ?? [];
 78:         $this->assertStringContainsString('صورة واحدة', implode(' ', $errors));
 79:     }
 80: 
 81:     public function test_publishing_a_complete_product_sets_status_and_published_at(): void
 82:     {
 83:         Storage::fake('public');
 84:         $token = $this->adminToken();
 85:         $productId = $this->createProduct($token, [
 86:             'variants' => [['color_name_en' => 'Red', 'retail_price' => 100]],
 87:         ]);
 88: 
 89:         Auth::forgetGuards();
 90:         $this->withToken($token)->post("/api/v1/admin/products/{$productId}/media", [
 91:             'collection' => 'images',
 92:             'files' => [UploadedFile::fake()->image('product.jpg')],
 93:         ])->assertCreated();
 94: 
 95:         $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
 96:             'status' => 'published',
 97:         ])->assertOk()
 98:             ->assertJsonPath('data.status', 'published')
 99:             ->assertJsonPath('data.published_at', fn ($value) => ! empty($value));
100: 
101:         $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'published']);
102:     }
103: 
104:     public function test_staff_without_view_cost_cannot_see_cost_price(): void
105:     {
106:         $token = $this->adminToken();
107:         $productId = $this->createProduct($token, [
108:             'variants' => [['color_name_en' => 'Red', 'cost_price' => 500, 'retail_price' => 900]],
109:         ]);
110: 
111:         $viewer = $this->staffWithPermissions(['products.view']);
112:         $viewerToken = $viewer->createToken('test')->plainTextToken;
113: 
114:         $response = $this->requestWithToken($viewerToken, 'GET', "/api/v1/admin/products/{$productId}");
115:         $response->assertOk();
116:         $this->assertArrayNotHasKey('cost_price', $response->json('data.variants.0'));
117: 
118:         $adminResponse = $this->requestWithToken($token, 'GET', "/api/v1/admin/products/{$productId}");
119:         $this->assertSame('500.00', (string) $adminResponse->json('data.variants.0.cost_price'));
120:     }
121: 
122:     public function test_search_by_barcode_finds_the_right_product(): void
123:     {
124:         $token = $this->adminToken();
125:         $targetId = $this->createProduct($token, [
126:             'name_en' => 'Target Bag',
127:             'variants' => [['color_name_en' => 'Red', 'barcode' => '2000000000015']],
128:         ]);
129:         $this->createProduct($token, ['name_en' => 'Other Bag']);
130: 
131:         $this->requestWithToken($token, 'GET', '/api/v1/admin/products?search=2000000000015')
132:             ->assertOk()
133:             ->assertJsonCount(1, 'data')
134:             ->assertJsonPath('data.0.id', $targetId);
135:     }
136: 
137:     private function adminToken(): string
138:     {
139:         return $this->staffWithPermissions(self::ALL_PERMISSIONS)->createToken('test')->plainTextToken;
140:     }
141: 
142:     private function createProduct(string $token, array $overrides = []): int
143:     {
144:         $category = Category::factory()->create();
145:         $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', array_merge([
146:             'category_id' => $category->id,
147:             'name_ar' => 'منتج',
148:             'name_en' => 'Some Product',
149:             'sales_channel' => 'retail',
150:         ], $overrides));
151:         $response->assertCreated();
152: 
153:         return $response->json('data.id');
154:     }
155: 
156:     /** @param list<string> $permissions */
157:     private function staffWithPermissions(array $permissions): User
158:     {
159:         foreach ($permissions as $permission) {
160:             Permission::findOrCreate($permission, 'staff');
161:         }
162:         $staff = User::factory()->create();
163:         $staff->givePermissionTo($permissions);
164: 
165:         return $staff;
166:     }
167: 
168:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
169:     {
170:         Auth::forgetGuards();
171: 
172:         return $this->withToken($token)->json($method, $uri, $payload);
173:     }
174: }
```

## File: bags-platform/backend/tests/Feature/Admin/Catalog/ProductMediaCatalogTest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Tests\Feature\Admin\Catalog;
 6: 
 7: use App\Models\Product;
 8: use App\Models\User;
 9: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
10: use Illuminate\Http\UploadedFile;
11: use Illuminate\Support\Facades\Auth;
12: use Illuminate\Support\Facades\Storage;
13: use Spatie\Permission\Models\Permission;
14: use Tests\TestCase;
15: 
16: class ProductMediaCatalogTest extends TestCase
17: {
18:     use LazilyRefreshDatabase;
19: 
20:     private const ALL_PERMISSIONS = [
21:         'products.view', 'products.create', 'products.update', 'products.delete',
22:         'products.publish', 'products.view_cost',
23:     ];
24: 
25:     public function test_deleting_media_of_another_product_returns_404(): void
26:     {
27:         Storage::fake('public');
28:         $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
29:         $token = $staff->createToken('test')->plainTextToken;
30: 
31:         $product = Product::factory()->create();
32:         $other = Product::factory()->create();
33: 
34:         Auth::forgetGuards();
35:         $uploaded = $this->withToken($token)->post("/api/v1/admin/products/{$other->id}/media", [
36:             'collection' => 'images',
37:             'files' => [UploadedFile::fake()->image('other.jpg')],
38:         ])->assertCreated();
39:         $mediaId = $uploaded->json('data.0.id');
40:         $this->assertNotEmpty($mediaId);
41: 
42:         Auth::forgetGuards();
43:         $this->withToken($token)->deleteJson("/api/v1/admin/products/{$product->id}/media/{$mediaId}")
44:             ->assertNotFound();
45: 
46:         Auth::forgetGuards();
47:         $this->withToken($token)->deleteJson("/api/v1/admin/products/{$other->id}/media/{$mediaId}")
48:             ->assertOk()
49:             ->assertJsonPath('message', 'تم الحذف');
50:     }
51: 
52:     /** @param list<string> $permissions */
53:     private function staffWithPermissions(array $permissions): User
54:     {
55:         foreach ($permissions as $permission) {
56:             Permission::findOrCreate($permission, 'staff');
57:         }
58:         $staff = User::factory()->create();
59:         $staff->givePermissionTo($permissions);
60: 
61:         return $staff;
62:     }
63: }
```

## File: bags-platform/backend/tests/Feature/Admin/Catalog/VariantCatalogTest.php
```php
  1: <?php
  2: 
  3: declare(strict_types=1);
  4: 
  5: namespace Tests\Feature\Admin\Catalog;
  6: 
  7: use App\Models\Category;
  8: use App\Models\Product;
  9: use App\Models\ProductVariant;
 10: use App\Models\User;
 11: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 12: use Illuminate\Support\Facades\Auth;
 13: use Illuminate\Testing\TestResponse;
 14: use Spatie\Permission\Models\Permission;
 15: use Tests\TestCase;
 16: 
 17: class VariantCatalogTest extends TestCase
 18: {
 19:     use LazilyRefreshDatabase;
 20: 
 21:     private const ALL_PERMISSIONS = [
 22:         'products.view', 'products.create', 'products.update', 'products.delete',
 23:         'products.publish', 'products.view_cost',
 24:     ];
 25: 
 26:     public function test_variant_without_sku_gets_sequence_sku_and_valid_ean13_barcode(): void
 27:     {
 28:         $token = $this->adminToken();
 29:         $category = Category::factory()->create();
 30: 
 31:         $product = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
 32:             'category_id' => $category->id,
 33:             'name_ar' => 'منتج',
 34:             'name_en' => 'Barcode Product',
 35:             'sales_channel' => 'retail',
 36:         ])->assertCreated();
 37:         $productId = $product->json('data.id');
 38:         $code = (string) $product->json('data.code');
 39:         $this->assertMatchesRegularExpression('/^P\d{5}$/', $code);
 40: 
 41:         // أول متغير لأي منتج بياخد لاحقة ‎-01‎ (في قاعدة فاضية بيبقى P00001-01)
 42:         $variant = $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$productId}/variants", [
 43:             'color_name_en' => 'Red', 'size' => 'M', 'retail_price' => 100,
 44:         ])->assertCreated()
 45:             ->assertJsonPath('data.sku', $code.'-01');
 46: 
 47:         $barcode = (string) $variant->json('data.barcode');
 48:         $this->assertMatchesRegularExpression('/^\d{13}$/', $barcode);
 49:         $this->assertTrue($this->ean13CheckDigitIsValid($barcode), "Invalid EAN-13 check digit: {$barcode}");
 50:     }
 51: 
 52:     public function test_two_variants_with_same_color_and_size_are_rejected(): void
 53:     {
 54:         $token = $this->adminToken();
 55:         $product = Product::factory()->create();
 56: 
 57:         $payload = ['color_name_en' => 'Red', 'size' => 'M'];
 58:         $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", $payload)
 59:             ->assertCreated();
 60:         $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", $payload)
 61:             ->assertUnprocessable();
 62:     }
 63: 
 64:     public function test_variant_of_another_product_returns_404(): void
 65:     {
 66:         $token = $this->adminToken();
 67:         $product = Product::factory()->create();
 68:         $other = Product::factory()->create();
 69:         $foreignVariant = ProductVariant::factory()->create(['product_id' => $other->id]);
 70: 
 71:         $this->requestWithToken($token, 'GET', "/api/v1/admin/products/{$product->id}/variants/{$foreignVariant->id}")
 72:             ->assertNotFound();
 73:         $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/variants/{$foreignVariant->id}", [
 74:             'retail_price' => 10,
 75:         ])->assertNotFound();
 76:     }
 77: 
 78:     private function ean13CheckDigitIsValid(string $barcode): bool
 79:     {
 80:         $sum = 0;
 81:         foreach (str_split(substr($barcode, 0, 12)) as $i => $digit) {
 82:             $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
 83:         }
 84: 
 85:         return ((10 - ($sum % 10)) % 10) === (int) $barcode[12];
 86:     }
 87: 
 88:     private function adminToken(): string
 89:     {
 90:         $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
 91: 
 92:         return $staff->createToken('test')->plainTextToken;
 93:     }
 94: 
 95:     /** @param list<string> $permissions */
 96:     private function staffWithPermissions(array $permissions): User
 97:     {
 98:         foreach ($permissions as $permission) {
 99:             Permission::findOrCreate($permission, 'staff');
100:         }
101:         $staff = User::factory()->create();
102:         $staff->givePermissionTo($permissions);
103: 
104:         return $staff;
105:     }
106: 
107:     private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
108:     {
109:         Auth::forgetGuards();
110: 
111:         return $this->withToken($token)->json($method, $uri, $payload);
112:     }
113: }
```

## File: bags-platform/backend/tests/Feature/AdminRouteAggregationTest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Tests\Feature;
 6: 
 7: use Illuminate\Support\Facades\Route;
 8: use Tests\TestCase;
 9: 
10: class AdminRouteAggregationTest extends TestCase
11: {
12:     public function test_admin_routes_are_loaded_with_expected_names_and_security_middleware(): void
13:     {
14:         $routes = Route::getRoutes();
15: 
16:         foreach ([
17:             'admin.auth.login',
18:             'admin.auth.me',
19:             'admin.branches.index',
20:             'admin.branches.locations.index',
21:             'admin.users.index',
22:             'admin.roles.index',
23:             'admin.permissions.index',
24:             'admin.settings.index',
25:             'admin.settings.update',
26:         ] as $routeName) {
27:             $this->assertNotNull($routes->getByName($routeName), "Expected [{$routeName}] to be registered.");
28:         }
29: 
30:         $login = $routes->getByName('admin.auth.login');
31:         $this->assertContains('throttle:admin-login', $login->gatherMiddleware());
32:         $this->assertNotContains('auth:sanctum', $login->gatherMiddleware());
33: 
34:         foreach ([
35:             'admin.auth.me',
36:             'admin.branches.index',
37:             'admin.branches.locations.index',
38:             'admin.users.index',
39:             'admin.roles.index',
40:             'admin.permissions.index',
41:             'admin.settings.index',
42:             'admin.settings.update',
43:         ] as $routeName) {
44:             $middleware = $routes->getByName($routeName)->gatherMiddleware();
45:             $this->assertContains('auth:sanctum', $middleware, "Expected [{$routeName}] to require a Sanctum token.");
46:             $this->assertContains('auth.staff', $middleware, "Expected [{$routeName}] to require staff authentication.");
47:         }
48: 
49:         $this->assertContains(
50:             'permission:settings.update|settings.manage',
51:             $routes->getByName('admin.settings.update')->gatherMiddleware(),
52:         );
53:     }
54: }
```

## File: bags-platform/backend/tests/Feature/DatabaseIndexTest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Tests\Feature;
 6: 
 7: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
 8: use Illuminate\Support\Facades\Schema;
 9: use Tests\TestCase;
10: 
11: class DatabaseIndexTest extends TestCase
12: {
13:     use LazilyRefreshDatabase;
14: 
15:     public function test_lookup_indexes_exist_for_customer_supplier_branch_and_location_queries(): void
16:     {
17:         $this->assertTrue(Schema::hasIndex('customers', ['type', 'status']));
18:         $this->assertTrue(Schema::hasIndex('customers', ['phone']));
19:         $this->assertTrue(Schema::hasIndex('supplier_users', ['supplier_id', 'is_active']));
20:         $this->assertTrue(Schema::hasIndex('branches', ['type', 'is_active']));
21:         $this->assertTrue(Schema::hasIndex('locations', ['branch_id', 'is_active', 'type']));
22:     }
23: }
```

## File: bags-platform/backend/tests/Feature/DatabaseSeederTest.php
```php
 1: <?php
 2: 
 3: declare(strict_types=1);
 4: 
 5: namespace Tests\Feature;
 6: 
 7: use App\Models\Branch;
 8: use App\Models\Setting;
 9: use App\Services\SettingService;
10: use Database\Seeders\DatabaseSeeder;
11: use Database\Seeders\LocationSeeder;
12: use Database\Seeders\SettingSeeder;
13: use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
14: use Tests\TestCase;
15: 
16: class DatabaseSeederTest extends TestCase
17: {
18:     use LazilyRefreshDatabase;
19: 
20:     public function test_database_seeder_is_idempotent_in_testing_environment(): void
21:     {
22:         $this->seed(DatabaseSeeder::class);
23:         $this->seed(DatabaseSeeder::class);
24: 
25:         $this->assertDatabaseCount('users', 1);
26:         $this->assertDatabaseHas('users', [
27:             'name' => 'Test User',
28:             'email' => 'test@example.com',
29:         ]);
30:     }
31: 
32:     public function test_database_seeder_does_not_create_test_account_in_production_environment(): void
33:     {
34:         $this->app['env'] = 'production';
35: 
36:         $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
37:             ->assertSuccessful();
38: 
39:         $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
40:     }
41: 
42:     public function test_setting_seeder_fills_missing_settings_without_overwriting_custom_values(): void
43:     {
44:         $this->seed(SettingSeeder::class);
45:         Setting::query()->where('key', 'company_name')->update(['value' => 'Custom Company']);
46:         Setting::query()->where('key', 'tax_enabled')->delete();
47: 
48:         $this->seed(SettingSeeder::class);
49: 
50:         $this->assertDatabaseHas('settings', ['key' => 'company_name', 'value' => 'Custom Company']);
51:         $this->assertDatabaseHas('settings', ['key' => 'tax_enabled', 'value' => '0']);
52:     }
53: 
54:     public function test_setting_seeder_invalidates_cache_when_restoring_missing_settings(): void
55:     {
56:         $this->seed(SettingSeeder::class);
57:         Setting::query()->where('key', 'purchase_order_padding')->delete();
58:         $service = app(SettingService::class);
59:         $this->assertNull($service->get('purchase_order_padding'));
60: 
61:         $this->seed(SettingSeeder::class);
62: 
63:         $this->assertSame(6, $service->get('purchase_order_padding'));
64:     }
65: 
66:     public function test_location_seeder_fills_missing_shelves_for_every_branch_without_overwriting_existing_locations(): void
67:     {
68:         $firstBranch = Branch::factory()->create();
69:         $secondBranch = Branch::factory()->create();
70:         $customLocation = $firstBranch->locations()->create([
71:             'name' => 'Custom shelf name',
72:             'code' => 'A-01-01',
73:             'type' => 'shelf',
74:         ]);
75: 
76:         $this->seed(LocationSeeder::class);
77: 
78:         $this->assertDatabaseCount('locations', 6);
79:         $this->assertDatabaseHas('locations', [
80:             'id' => $customLocation->id,
81:             'name' => 'Custom shelf name',
82:         ]);
83: 
84:         foreach ([$firstBranch, $secondBranch] as $branch) {
85:             $this->assertSame(3, $branch->locations()->count());
86:         }
87:     }
88: }
```

## File: bags-platform/backend/tests/TestCase.php
```php
 1: <?php
 2: 
 3: namespace Tests;
 4: 
 5: use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
 6: 
 7: abstract class TestCase extends BaseTestCase
 8: {
 9:     //
10: }
```

## File: bags-platform/backend/.env.example
```
 1: APP_NAME=Laravel
 2: APP_ENV=local
 3: APP_KEY=
 4: APP_DEBUG=true
 5: APP_URL=http://localhost:8000
 6: SANCTUM_EXPIRATION=1440
 7: # Configure only your actual reverse-proxy IPs/CIDRs in production.
 8: # TRUSTED_PROXIES=10.0.0.10,192.0.2.0/24
 9: 
10: APP_LOCALE=en
11: APP_FALLBACK_LOCALE=en
12: APP_FAKER_LOCALE=en_US
13: 
14: APP_MAINTENANCE_DRIVER=file
15: # APP_MAINTENANCE_STORE=database
16: 
17: # PHP_CLI_SERVER_WORKERS=4
18: 
19: BCRYPT_ROUNDS=12
20: 
21: LOG_CHANNEL=stack
22: LOG_STACK=single
23: LOG_DEPRECATIONS_CHANNEL=null
24: LOG_LEVEL=debug
25: 
26: DB_CONNECTION=pgsql
27: DB_HOST=127.0.0.1
28: DB_PORT=5432
29: DB_DATABASE=alfarid
30: DB_USERNAME=postgres
31: DB_PASSWORD=
32: 
33: SESSION_DRIVER=database
34: SESSION_LIFETIME=120
35: SESSION_ENCRYPT=false
36: SESSION_PATH=/
37: SESSION_DOMAIN=null
38: 
39: BROADCAST_CONNECTION=log
40: FILESYSTEM_DISK=local
41: QUEUE_CONNECTION=database
42: 
43: CACHE_STORE=database
44: # CACHE_PREFIX=
45: 
46: MEMCACHED_HOST=127.0.0.1
47: 
48: REDIS_CLIENT=phpredis
49: REDIS_HOST=127.0.0.1
50: REDIS_PASSWORD=null
51: REDIS_PORT=6379
52: 
53: MAIL_MAILER=log
54: MAIL_SCHEME=null
55: MAIL_HOST=127.0.0.1
56: MAIL_PORT=2525
57: MAIL_USERNAME=null
58: MAIL_PASSWORD=null
59: MAIL_FROM_ADDRESS="hello@example.com"
60: MAIL_FROM_NAME="${APP_NAME}"
61: 
62: AWS_ACCESS_KEY_ID=
63: AWS_SECRET_ACCESS_KEY=
64: AWS_DEFAULT_REGION=us-east-1
65: AWS_BUCKET=
66: AWS_USE_PATH_STYLE_ENDPOINT=false
67: 
68: VITE_APP_NAME="${APP_NAME}"
69: 
70: MEDIA_DISK=public
```

## File: bags-platform/backend/composer.json
```json
 1: {
 2:     "$schema": "https://getcomposer.org/schema.json",
 3:     "name": "laravel/laravel",
 4:     "type": "project",
 5:     "description": "The skeleton application for the Laravel framework.",
 6:     "keywords": ["laravel", "framework"],
 7:     "license": "MIT",
 8:     "require": {
 9:         "php": "^8.3",
10:         "laravel/framework": "^13.17",
11:         "laravel/sanctum": "^4.3",
12:         "laravel/tinker": "^3.0",
13:         "spatie/laravel-medialibrary": "^11.23",
14:         "spatie/laravel-permission": "^8.3"
15:     },
16:     "require-dev": {
17:         "fakerphp/faker": "^1.23",
18:         "laravel/boost": "^2.10",
19:         "laravel/pail": "^1.2.5",
20:         "laravel/pao": "^1.0.6",
21:         "laravel/pint": "^1.27",
22:         "mockery/mockery": "^1.6",
23:         "nunomaduro/collision": "^8.6",
24:         "phpunit/phpunit": "^12.5.12"
25:     },
26:     "autoload": {
27:         "psr-4": {
28:             "App\\": "app/",
29:             "Database\\Factories\\": "database/factories/",
30:             "Database\\Seeders\\": "database/seeders/"
31:         }
32:     },
33:     "autoload-dev": {
34:         "psr-4": {
35:             "Tests\\": "tests/"
36:         }
37:     },
38:     "scripts": {
39:         "setup": [
40:             "composer install",
41:             "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
42:             "@php artisan key:generate",
43:             "@php artisan migrate --force",
44:             "npm install --ignore-scripts",
45:             "npm run build"
46:         ],
47:         "dev": [
48:             "Composer\\Config::disableProcessTimeout",
49:             "@php artisan dev"
50:         ],
51:         "test": [
52:             "@php artisan config:clear --ansi @no_additional_args",
53:             "@php artisan test"
54:         ],
55:         "post-autoload-dump": [
56:             "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
57:             "@php artisan package:discover --ansi"
58:         ],
59:         "post-update-cmd": [
60:             "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
61:         ],
62:         "post-root-package-install": [
63:             "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
64:         ],
65:         "post-create-project-cmd": [
66:             "@php artisan key:generate --ansi",
67:             "@php artisan migrate --graceful --ansi"
68:         ],
69:         "pre-package-uninstall": [
70:             "Illuminate\\Foundation\\ComposerScripts::prePackageUninstall"
71:         ]
72:     },
73:     "extra": {
74:         "laravel": {
75:             "dont-discover": []
76:         }
77:     },
78:     "config": {
79:         "optimize-autoloader": true,
80:         "preferred-install": "dist",
81:         "sort-packages": true,
82:         "allow-plugins": {
83:             "pestphp/pest-plugin": true,
84:             "php-http/discovery": true
85:         }
86:     },
87:     "minimum-stability": "stable",
88:     "prefer-stable": true
89: }
```

## File: bags-platform/backend/composer.lock
```
   1: {
   2:     "_readme": [
   3:         "This file locks the dependencies of your project to a known state",
   4:         "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
   5:         "This file is @generated automatically"
   6:     ],
   7:     "content-hash": "4c7c59369fa0018bda89ab15059fe0ea",
   8:     "packages": [
   9:         {
  10:             "name": "brick/math",
  11:             "version": "1.0.0",
  12:             "source": {
  13:                 "type": "git",
  14:                 "url": "https://github.com/brick/math.git",
  15:                 "reference": "2effe05d2177c451b86c6a073196a4034c02f211"
  16:             },
  17:             "dist": {
  18:                 "type": "zip",
  19:                 "url": "https://api.github.com/repos/brick/math/zipball/2effe05d2177c451b86c6a073196a4034c02f211",
  20:                 "reference": "2effe05d2177c451b86c6a073196a4034c02f211",
  21:                 "shasum": ""
  22:             },
  23:             "require": {
  24:                 "php": "^8.2"
  25:             },
  26:             "require-dev": {
  27:                 "phpstan/phpstan": "2.2.13",
  28:                 "phpstan/phpstan-phpunit": "2.0.18",
  29:                 "phpunit/phpunit": "^11.5"
  30:             },
  31:             "type": "library",
  32:             "autoload": {
  33:                 "psr-4": {
  34:                     "Brick\\Math\\": "src/"
  35:                 }
  36:             },
  37:             "notification-url": "https://packagist.org/downloads/",
  38:             "license": [
  39:                 "MIT"
  40:             ],
  41:             "description": "Arbitrary-precision arithmetic library",
  42:             "keywords": [
  43:                 "Arbitrary-precision",
  44:                 "BigInteger",
  45:                 "BigRational",
  46:                 "arithmetic",
  47:                 "bigdecimal",
  48:                 "bignum",
  49:                 "bignumber",
  50:                 "brick",
  51:                 "decimal",
  52:                 "integer",
  53:                 "math",
  54:                 "mathematics",
  55:                 "rational"
  56:             ],
  57:             "support": {
  58:                 "issues": "https://github.com/brick/math/issues",
  59:                 "source": "https://github.com/brick/math/tree/1.0.0"
  60:             },
  61:             "funding": [
  62:                 {
  63:                     "url": "https://github.com/BenMorel",
  64:                     "type": "github"
  65:                 }
  66:             ],
  67:             "time": "2026-09-12T10:28:18+00:00"
  68:         },
  69:         {
  70:             "name": "carbonphp/carbon-doctrine-types",
  71:             "version": "3.2.1",
  72:             "source": {
  73:                 "type": "git",
  74:                 "url": "https://github.com/CarbonPHP/carbon-doctrine-types.git",
  75:                 "reference": "5fa5eacafd9ef47c8c6ab9143fc901ba0194d3dc"
  76:             },
  77:             "dist": {
  78:                 "type": "zip",
  79:                 "url": "https://api.github.com/repos/CarbonPHP/carbon-doctrine-types/zipball/5fa5eacafd9ef47c8c6ab9143fc901ba0194d3dc",
  80:                 "reference": "5fa5eacafd9ef47c8c6ab9143fc901ba0194d3dc",
  81:                 "shasum": ""
  82:             },
  83:             "require": {
  84:                 "php": "^8.1"
  85:             },
  86:             "conflict": {
  87:                 "doctrine/dbal": "<4.0.0 || >=5.0.0"
  88:             },
  89:             "require-dev": {
  90:                 "doctrine/dbal": "^4.0.0",
  91:                 "nesbot/carbon": "^2.71.0 || ^3.0.0",
  92:                 "phpunit/phpunit": "^10.3"
  93:             },
  94:             "type": "library",
  95:             "extra": {
  96:                 "branch-alias": {
  97:                     "dev-main": "3.x-dev"
  98:                 }
  99:             },
 100:             "autoload": {
 101:                 "psr-4": {
 102:                     "Carbon\\Doctrine\\": "src/Carbon/Doctrine/"
 103:                 }
 104:             },
 105:             "notification-url": "https://packagist.org/downloads/",
 106:             "license": [
 107:                 "MIT"
 108:             ],
 109:             "authors": [
 110:                 {
 111:                     "name": "KyleKatarn",
 112:                     "email": "kylekatarnls@gmail.com"
 113:                 }
 114:             ],
 115:             "description": "Types to use Carbon in Doctrine",
 116:             "keywords": [
 117:                 "carbon",
 118:                 "date",
 119:                 "datetime",
 120:                 "doctrine",
 121:                 "time"
 122:             ],
 123:             "support": {
 124:                 "issues": "https://github.com/CarbonPHP/carbon-doctrine-types/issues",
 125:                 "source": "https://github.com/CarbonPHP/carbon-doctrine-types/tree/3.2.1"
 126:             },
 127:             "funding": [
 128:                 {
 129:                     "url": "https://github.com/kylekatarnls",
 130:                     "type": "github"
 131:                 },
 132:                 {
 133:                     "url": "https://opencollective.com/Carbon",
 134:                     "type": "open_collective"
 135:                 },
 136:                 {
 137:                     "url": "https://tidelift.com/funding/github/packagist/nesbot/carbon",
 138:                     "type": "tidelift"
 139:                 }
 140:             ],
 141:             "time": "2026-09-06T14:11:38+00:00"
 142:         },
 143:         {
 144:             "name": "composer/semver",
 145:             "version": "3.5.1",
 146:             "source": {
 147:                 "type": "git",
 148:                 "url": "https://github.com/composer/semver.git",
 149:                 "reference": "528948210cc46f967a4b98e83b19e76f2b504993"
 150:             },
 151:             "dist": {
 152:                 "type": "zip",
 153:                 "url": "https://api.github.com/repos/composer/semver/zipball/528948210cc46f967a4b98e83b19e76f2b504993",
 154:                 "reference": "528948210cc46f967a4b98e83b19e76f2b504993",
 155:                 "shasum": ""
 156:             },
 157:             "require": {
 158:                 "php": "^5.3.2 || ^7.0 || ^8.0"
 159:             },
 160:             "require-dev": {
 161:                 "phpstan/phpstan": "^1.11",
 162:                 "symfony/phpunit-bridge": "^3 || ^7"
 163:             },
 164:             "type": "library",
 165:             "extra": {
 166:                 "branch-alias": {
 167:                     "dev-main": "3.x-dev"
 168:                 }
 169:             },
 170:             "autoload": {
 171:                 "psr-4": {
 172:                     "Composer\\Semver\\": "src"
 173:                 }
 174:             },
 175:             "notification-url": "https://packagist.org/downloads/",
 176:             "license": [
 177:                 "MIT"
 178:             ],
 179:             "authors": [
 180:                 {
 181:                     "name": "Nils Adermann",
 182:                     "email": "naderman@naderman.de",
 183:                     "homepage": "http://www.naderman.de"
 184:                 },
 185:                 {
 186:                     "name": "Jordi Boggiano",
 187:                     "email": "j.boggiano@seld.be",
 188:                     "homepage": "http://seld.be"
 189:                 },
 190:                 {
 191:                     "name": "Rob Bast",
 192:                     "email": "rob.bast@gmail.com",
 193:                     "homepage": "http://robbast.nl"
 194:                 }
 195:             ],
 196:             "description": "Version comparison library that offers utilities, version constraint parsing and validation.",
 197:             "keywords": [
 198:                 "semantic",
 199:                 "semver",
 200:                 "validation",
 201:                 "versioning"
 202:             ],
 203:             "support": {
 204:                 "irc": "ircs://irc.libera.chat:6697/composer",
 205:                 "issues": "https://github.com/composer/semver/issues",
 206:                 "source": "https://github.com/composer/semver/tree/3.5.1"
 207:             },
 208:             "funding": [
 209:                 {
 210:                     "url": "https://packagist.com",
 211:                     "type": "custom"
 212:                 },
 213:                 {
 214:                     "url": "https://github.com/composer",
 215:                     "type": "github"
 216:                 }
 217:             ],
 218:             "time": "2026-10-05T13:12:12+00:00"
 219:         },
 220:         {
 221:             "name": "dflydev/dot-access-data",
 222:             "version": "v3.0.3",
 223:             "source": {
 224:                 "type": "git",
 225:                 "url": "https://github.com/dflydev/dflydev-dot-access-data.git",
 226:                 "reference": "a23a2bf4f31d3518f3ecb38660c95715dfead60f"
 227:             },
 228:             "dist": {
 229:                 "type": "zip",
 230:                 "url": "https://api.github.com/repos/dflydev/dflydev-dot-access-data/zipball/a23a2bf4f31d3518f3ecb38660c95715dfead60f",
 231:                 "reference": "a23a2bf4f31d3518f3ecb38660c95715dfead60f",
 232:                 "shasum": ""
 233:             },
 234:             "require": {
 235:                 "php": "^7.1 || ^8.0"
 236:             },
 237:             "require-dev": {
 238:                 "phpstan/phpstan": "^0.12.42",
 239:                 "phpunit/phpunit": "^7.5 || ^8.5 || ^9.3",
 240:                 "scrutinizer/ocular": "1.6.0",
 241:                 "squizlabs/php_codesniffer": "^3.5",
 242:                 "vimeo/psalm": "^4.0.0"
 243:             },
 244:             "type": "library",
 245:             "extra": {
 246:                 "branch-alias": {
 247:                     "dev-main": "3.x-dev"
 248:                 }
 249:             },
 250:             "autoload": {
 251:                 "psr-4": {
 252:                     "Dflydev\\DotAccessData\\": "src/"
 253:                 }
 254:             },
 255:             "notification-url": "https://packagist.org/downloads/",
 256:             "license": [
 257:                 "MIT"
 258:             ],
 259:             "authors": [
 260:                 {
 261:                     "name": "Dragonfly Development Inc.",
 262:                     "email": "info@dflydev.com",
 263:                     "homepage": "http://dflydev.com"
 264:                 },
 265:                 {
 266:                     "name": "Beau Simensen",
 267:                     "email": "beau@dflydev.com",
 268:                     "homepage": "http://beausimensen.com"
 269:                 },
 270:                 {
 271:                     "name": "Carlos Frutos",
 272:                     "email": "carlos@kiwing.it",
 273:                     "homepage": "https://github.com/cfrutos"
 274:                 },
 275:                 {
 276:                     "name": "Colin O'Dell",
 277:                     "email": "colinodell@gmail.com",
 278:                     "homepage": "https://www.colinodell.com"
 279:                 }
 280:             ],
 281:             "description": "Given a deep data structure, access data by dot notation.",
 282:             "homepage": "https://github.com/dflydev/dflydev-dot-access-data",
 283:             "keywords": [
 284:                 "access",
 285:                 "data",
 286:                 "dot",
 287:                 "notation"
 288:             ],
 289:             "support": {
 290:                 "issues": "https://github.com/dflydev/dflydev-dot-access-data/issues",
 291:                 "source": "https://github.com/dflydev/dflydev-dot-access-data/tree/v3.0.3"
 292:             },
 293:             "time": "2024-07-08T12:26:09+00:00"
 294:         },
 295:         {
 296:             "name": "doctrine/inflector",
 297:             "version": "2.1.0",
 298:             "source": {
 299:                 "type": "git",
 300:                 "url": "https://github.com/doctrine/inflector.git",
 301:                 "reference": "6d6c96277ea252fc1304627204c3d5e6e15faa3b"
 302:             },
 303:             "dist": {
 304:                 "type": "zip",
 305:                 "url": "https://api.github.com/repos/doctrine/inflector/zipball/6d6c96277ea252fc1304627204c3d5e6e15faa3b",
 306:                 "reference": "6d6c96277ea252fc1304627204c3d5e6e15faa3b",
 307:                 "shasum": ""
 308:             },
 309:             "require": {
 310:                 "php": "^7.2 || ^8.0"
 311:             },
 312:             "require-dev": {
 313:                 "doctrine/coding-standard": "^12.0 || ^13.0",
 314:                 "phpstan/phpstan": "^1.12 || ^2.0",
 315:                 "phpstan/phpstan-phpunit": "^1.4 || ^2.0",
 316:                 "phpstan/phpstan-strict-rules": "^1.6 || ^2.0",
 317:                 "phpunit/phpunit": "^8.5 || ^12.2"
 318:             },
 319:             "type": "library",
 320:             "autoload": {
 321:                 "psr-4": {
 322:                     "Doctrine\\Inflector\\": "src"
 323:                 }
 324:             },
 325:             "notification-url": "https://packagist.org/downloads/",
 326:             "license": [
 327:                 "MIT"
 328:             ],
 329:             "authors": [
 330:                 {
 331:                     "name": "Guilherme Blanco",
 332:                     "email": "guilhermeblanco@gmail.com"
 333:                 },
 334:                 {
 335:                     "name": "Roman Borschel",
 336:                     "email": "roman@code-factory.org"
 337:                 },
 338:                 {
 339:                     "name": "Benjamin Eberlei",
 340:                     "email": "kontakt@beberlei.de"
 341:                 },
 342:                 {
 343:                     "name": "Jonathan Wage",
 344:                     "email": "jonwage@gmail.com"
 345:                 },
 346:                 {
 347:                     "name": "Johannes Schmitt",
 348:                     "email": "schmittjoh@gmail.com"
 349:                 }
 350:             ],
 351:             "description": "PHP Doctrine Inflector is a small library that can perform string manipulations with regard to upper/lowercase and singular/plural forms of words.",
 352:             "homepage": "https://www.doctrine-project.org/projects/inflector.html",
 353:             "keywords": [
 354:                 "inflection",
 355:                 "inflector",
 356:                 "lowercase",
 357:                 "manipulation",
 358:                 "php",
 359:                 "plural",
 360:                 "singular",
 361:                 "strings",
 362:                 "uppercase",
 363:                 "words"
 364:             ],
 365:             "support": {
 366:                 "issues": "https://github.com/doctrine/inflector/issues",
 367:                 "source": "https://github.com/doctrine/inflector/tree/2.1.0"
 368:             },
 369:             "funding": [
 370:                 {
 371:                     "url": "https://www.doctrine-project.org/sponsorship.html",
 372:                     "type": "custom"
 373:                 },
 374:                 {
 375:                     "url": "https://www.patreon.com/phpdoctrine",
 376:                     "type": "patreon"
 377:                 },
 378:                 {
 379:                     "url": "https://tidelift.com/funding/github/packagist/doctrine%2Finflector",
 380:                     "type": "tidelift"
 381:                 }
 382:             ],
 383:             "time": "2025-08-10T19:31:58+00:00"
 384:         },
 385:         {
 386:             "name": "doctrine/lexer",
 387:             "version": "3.0.3",
 388:             "source": {
 389:                 "type": "git",
 390:                 "url": "https://github.com/doctrine/lexer.git",
 391:                 "reference": "71d305eaa6480d3d3460c7d053d1da7749fa9cca"
 392:             },
 393:             "dist": {
 394:                 "type": "zip",
 395:                 "url": "https://api.github.com/repos/doctrine/lexer/zipball/71d305eaa6480d3d3460c7d053d1da7749fa9cca",
 396:                 "reference": "71d305eaa6480d3d3460c7d053d1da7749fa9cca",
 397:                 "shasum": ""
 398:             },
 399:             "require": {
 400:                 "php": "^8.1"
 401:             },
 402:             "require-dev": {
 403:                 "doctrine/coding-standard": "^14",
 404:                 "phpstan/phpstan": "^2",
 405:                 "phpunit/phpunit": "^10.5.58 || ^12.5.4"
 406:             },
 407:             "type": "library",
 408:             "autoload": {
 409:                 "psr-4": {
 410:                     "Doctrine\\Common\\Lexer\\": "src"
 411:                 }
 412:             },
 413:             "notification-url": "https://packagist.org/downloads/",
 414:             "license": [
 415:                 "MIT"
 416:             ],
 417:             "authors": [
 418:                 {
 419:                     "name": "Guilherme Blanco",
 420:                     "email": "guilhermeblanco@gmail.com"
 421:                 },
 422:                 {
 423:                     "name": "Roman Borschel",
 424:                     "email": "roman@code-factory.org"
 425:                 },
 426:                 {
 427:                     "name": "Johannes Schmitt",
 428:                     "email": "schmittjoh@gmail.com"
 429:                 }
 430:             ],
 431:             "description": "PHP Doctrine Lexer parser library that can be used in Top-Down, Recursive Descent Parsers.",
 432:             "homepage": "https://www.doctrine-project.org/projects/lexer.html",
 433:             "keywords": [
 434:                 "annotations",
 435:                 "docblock",
 436:                 "lexer",
 437:                 "parser",
 438:                 "php"
 439:             ],
 440:             "support": {
 441:                 "issues": "https://github.com/doctrine/lexer/issues",
 442:                 "source": "https://github.com/doctrine/lexer/tree/3.0.3"
 443:             },
 444:             "funding": [
 445:                 {
 446:                     "url": "https://www.doctrine-project.org/sponsorship.html",
 447:                     "type": "custom"
 448:                 },
 449:                 {
 450:                     "url": "https://www.patreon.com/phpdoctrine",
 451:                     "type": "patreon"
 452:                 },
 453:                 {
 454:                     "url": "https://tidelift.com/funding/github/packagist/doctrine%2Flexer",
 455:                     "type": "tidelift"
 456:                 }
 457:             ],
 458:             "time": "2026-10-01T06:59:29+00:00"
 459:         },
 460:         {
 461:             "name": "dragonmantank/cron-expression",
 462:             "version": "v3.6.0",
 463:             "source": {
 464:                 "type": "git",
 465:                 "url": "https://github.com/dragonmantank/cron-expression.git",
 466:                 "reference": "d61a8a9604ec1f8c3d150d09db6ce98b32675013"
 467:             },
 468:             "dist": {
 469:                 "type": "zip",
 470:                 "url": "https://api.github.com/repos/dragonmantank/cron-expression/zipball/d61a8a9604ec1f8c3d150d09db6ce98b32675013",
 471:                 "reference": "d61a8a9604ec1f8c3d150d09db6ce98b32675013",
 472:                 "shasum": ""
 473:             },
 474:             "require": {
 475:                 "php": "^8.2|^8.3|^8.4|^8.5"
 476:             },
 477:             "replace": {
 478:                 "mtdowling/cron-expression": "^1.0"
 479:             },
 480:             "require-dev": {
 481:                 "phpstan/extension-installer": "^1.4.3",
 482:                 "phpstan/phpstan": "^1.12.32|^2.1.31",
 483:                 "phpunit/phpunit": "^8.5.48|^9.0"
 484:             },
 485:             "type": "library",
 486:             "extra": {
 487:                 "branch-alias": {
 488:                     "dev-master": "3.x-dev"
 489:                 }
 490:             },
 491:             "autoload": {
 492:                 "psr-4": {
 493:                     "Cron\\": "src/Cron/"
 494:                 }
 495:             },
 496:             "notification-url": "https://packagist.org/downloads/",
 497:             "license": [
 498:                 "MIT"
 499:             ],
 500:             "authors": [
 501:                 {
 502:                     "name": "Chris Tankersley",
 503:                     "email": "chris@ctankersley.com",
 504:                     "homepage": "https://github.com/dragonmantank"
 505:                 }
 506:             ],
 507:             "description": "CRON for PHP: Calculate the next or previous run date and determine if a CRON expression is due",
 508:             "keywords": [
 509:                 "cron",
 510:                 "schedule"
 511:             ],
 512:             "support": {
 513:                 "issues": "https://github.com/dragonmantank/cron-expression/issues",
 514:                 "source": "https://github.com/dragonmantank/cron-expression/tree/v3.6.0"
 515:             },
 516:             "funding": [
 517:                 {
 518:                     "url": "https://github.com/dragonmantank",
 519:                     "type": "github"
 520:                 }
 521:             ],
 522:             "time": "2025-10-31T18:51:33+00:00"
 523:         },
 524:         {
 525:             "name": "egulias/email-validator",
 526:             "version": "4.0.4",
 527:             "source": {
 528:                 "type": "git",
 529:                 "url": "https://github.com/egulias/EmailValidator.git",
 530:                 "reference": "d42c8731f0624ad6bdc8d3e5e9a4524f68801cfa"
 531:             },
 532:             "dist": {
 533:                 "type": "zip",
 534:                 "url": "https://api.github.com/repos/egulias/EmailValidator/zipball/d42c8731f0624ad6bdc8d3e5e9a4524f68801cfa",
 535:                 "reference": "d42c8731f0624ad6bdc8d3e5e9a4524f68801cfa",
 536:                 "shasum": ""
 537:             },
 538:             "require": {
 539:                 "doctrine/lexer": "^2.0 || ^3.0",
 540:                 "php": ">=8.1",
 541:                 "symfony/polyfill-intl-idn": "^1.26"
 542:             },
 543:             "require-dev": {
 544:                 "phpunit/phpunit": "^10.2",
 545:                 "vimeo/psalm": "^5.12"
 546:             },
 547:             "suggest": {
 548:                 "ext-intl": "PHP Internationalization Libraries are required to use the SpoofChecking validation"
 549:             },
 550:             "type": "library",
 551:             "extra": {
 552:                 "branch-alias": {
 553:                     "dev-master": "4.0.x-dev"
 554:                 }
 555:             },
 556:             "autoload": {
 557:                 "psr-4": {
 558:                     "Egulias\\EmailValidator\\": "src"
 559:                 }
 560:             },
 561:             "notification-url": "https://packagist.org/downloads/",
 562:             "license": [
 563:                 "MIT"
 564:             ],
 565:             "authors": [
 566:                 {
 567:                     "name": "Eduardo Gulias Davis"
 568:                 }
 569:             ],
 570:             "description": "A library for validating emails against several RFCs",
 571:             "homepage": "https://github.com/egulias/EmailValidator",
 572:             "keywords": [
 573:                 "email",
 574:                 "emailvalidation",
 575:                 "emailvalidator",
 576:                 "validation",
 577:                 "validator"
 578:             ],
 579:             "support": {
 580:                 "issues": "https://github.com/egulias/EmailValidator/issues",
 581:                 "source": "https://github.com/egulias/EmailValidator/tree/4.0.4"
 582:             },
 583:             "funding": [
 584:                 {
 585:                     "url": "https://github.com/egulias",
 586:                     "type": "github"
 587:                 }
 588:             ],
 589:             "time": "2025-03-06T22:45:56+00:00"
 590:         },
 591:         {
 592:             "name": "fruitcake/php-cors",
 593:             "version": "v1.4.0",
 594:             "source": {
 595:                 "type": "git",
 596:                 "url": "https://github.com/fruitcake/php-cors.git",
 597:                 "reference": "38aaa6c3fd4c157ffe2a4d10aa8b9b16ba8de379"
 598:             },
 599:             "dist": {
 600:                 "type": "zip",
 601:                 "url": "https://api.github.com/repos/fruitcake/php-cors/zipball/38aaa6c3fd4c157ffe2a4d10aa8b9b16ba8de379",
 602:                 "reference": "38aaa6c3fd4c157ffe2a4d10aa8b9b16ba8de379",
 603:                 "shasum": ""
 604:             },
 605:             "require": {
 606:                 "php": "^8.1",
 607:                 "symfony/http-foundation": "^5.4|^6.4|^7.3|^8"
 608:             },
 609:             "require-dev": {
 610:                 "phpstan/phpstan": "^2",
 611:                 "phpunit/phpunit": "^9",
 612:                 "squizlabs/php_codesniffer": "^4"
 613:             },
 614:             "type": "library",
 615:             "extra": {
 616:                 "branch-alias": {
 617:                     "dev-master": "1.3-dev"
 618:                 }
 619:             },
 620:             "autoload": {
 621:                 "psr-4": {
 622:                     "Fruitcake\\Cors\\": "src/"
 623:                 }
 624:             },
 625:             "notification-url": "https://packagist.org/downloads/",
 626:             "license": [
 627:                 "MIT"
 628:             ],
 629:             "authors": [
 630:                 {
 631:                     "name": "Fruitcake",
 632:                     "homepage": "https://fruitcake.nl"
 633:                 },
 634:                 {
 635:                     "name": "Barryvdh",
 636:                     "email": "barryvdh@gmail.com"
 637:                 }
 638:             ],
 639:             "description": "Cross-origin resource sharing library for the Symfony HttpFoundation",
 640:             "homepage": "https://github.com/fruitcake/php-cors",
 641:             "keywords": [
 642:                 "cors",
 643:                 "laravel",
 644:                 "symfony"
 645:             ],
 646:             "support": {
 647:                 "issues": "https://github.com/fruitcake/php-cors/issues",
 648:                 "source": "https://github.com/fruitcake/php-cors/tree/v1.4.0"
 649:             },
 650:             "funding": [
 651:                 {
 652:                     "url": "https://fruitcake.nl",
 653:                     "type": "custom"
 654:                 },
 655:                 {
 656:                     "url": "https://github.com/barryvdh",
 657:                     "type": "github"
 658:                 }
 659:             ],
 660:             "time": "2025-12-03T09:33:47+00:00"
 661:         },
 662:         {
 663:             "name": "graham-campbell/result-type",
 664:             "version": "v1.2.0",
 665:             "source": {
 666:                 "type": "git",
 667:                 "url": "https://github.com/GrahamCampbell/Result-Type.git",
 668:                 "reference": "adccca3324eece92ca35463648c12b9e6293c05b"
 669:             },
 670:             "dist": {
 671:                 "type": "zip",
 672:                 "url": "https://api.github.com/repos/GrahamCampbell/Result-Type/zipball/adccca3324eece92ca35463648c12b9e6293c05b",
 673:                 "reference": "adccca3324eece92ca35463648c12b9e6293c05b",
 674:                 "shasum": ""
 675:             },
 676:             "require": {
 677:                 "php": "^7.2.5 || ^8.0",
 678:                 "phpoption/phpoption": "^1.10"
 679:             },
 680:             "require-dev": {
 681:                 "phpunit/phpunit": "^8.5.52 || ^9.6.34 || ^10.5.63 || ^11.5.55 || ^12.5.14"
 682:             },
 683:             "type": "library",
 684:             "autoload": {
 685:                 "psr-4": {
 686:                     "GrahamCampbell\\ResultType\\": "src/"
 687:                 }
 688:             },
 689:             "notification-url": "https://packagist.org/downloads/",
 690:             "license": [
 691:                 "MIT"
 692:             ],
 693:             "authors": [
 694:                 {
 695:                     "name": "Graham Campbell",
 696:                     "email": "hello@gjcampbell.co.uk",
 697:                     "homepage": "https://github.com/GrahamCampbell"
 698:                 }
 699:             ],
 700:             "description": "An Implementation Of The Result Type",
 701:             "keywords": [
 702:                 "Graham Campbell",
 703:                 "GrahamCampbell",
 704:                 "Result Type",
 705:                 "Result-Type",
 706:                 "result"
 707:             ],
 708:             "support": {
 709:                 "issues": "https://github.com/GrahamCampbell/Result-Type/issues",
 710:                 "source": "https://github.com/GrahamCampbell/Result-Type/tree/v1.2.0"
 711:             },
 712:             "funding": [
 713:                 {
 714:                     "url": "https://github.com/GrahamCampbell",
 715:                     "type": "github"
 716:                 },
 717:                 {
 718:                     "url": "https://tidelift.com/funding/github/packagist/graham-campbell/result-type",
 719:                     "type": "tidelift"
 720:                 }
 721:             ],
 722:             "time": "2026-08-24T09:06:52+00:00"
 723:         },
 724:         {
 725:             "name": "guzzlehttp/guzzle",
 726:             "version": "8.2.0",
 727:             "source": {
 728:                 "type": "git",
 729:                 "url": "https://github.com/guzzle/guzzle.git",
 730:                 "reference": "93939470950a9b11e2e84204166ef5e048c55fe4"
 731:             },
 732:             "dist": {
 733:                 "type": "zip",
 734:                 "url": "https://api.github.com/repos/guzzle/guzzle/zipball/93939470950a9b11e2e84204166ef5e048c55fe4",
 735:                 "reference": "93939470950a9b11e2e84204166ef5e048c55fe4",
 736:                 "shasum": ""
 737:             },
 738:             "require": {
 739:                 "ext-json": "*",
 740:                 "guzzlehttp/promises": "^3.0.2",
 741:                 "guzzlehttp/psr7": "^3.1",
 742:                 "php": "^7.4 || ^8.0",
 743:                 "psr/http-client": "^1.0",
 744:                 "psr/http-factory": "^1.0",
 745:                 "symfony/polyfill-php80": "^1.25",
 746:                 "symfony/polyfill-php82": "^1.27"
 747:             },
 748:             "provide": {
 749:                 "psr/http-client-implementation": "1.0"
 750:             },
 751:             "require-dev": {
 752:                 "bamarni/composer-bin-plugin": "^1.8.2",
 753:                 "ext-curl": "*",
 754:                 "guzzle/client-integration-tests": "4.0.1",
 755:                 "guzzlehttp/test-server": "^1.0.1",
 756:                 "php-http/message-factory": "^1.1",
 757:                 "phpunit/phpunit": "^9.6.34",
 758:                 "psr/log": "^1.1 || ^2.0 || ^3.0"
 759:             },
 760:             "suggest": {
 761:                 "ext-curl": "Required for CURL handler support",
 762:                 "ext-intl": "Required for Internationalized Domain Name (IDN) support",
 763:                 "psr/log": "Required for using the Log middleware"
 764:             },
 765:             "type": "library",
 766:             "extra": {
 767:                 "bamarni-bin": {
 768:                     "bin-links": true,
 769:                     "forward-command": false
 770:                 }
 771:             },
 772:             "autoload": {
 773:                 "psr-4": {
 774:                     "GuzzleHttp\\": "src/"
 775:                 }
 776:             },
 777:             "notification-url": "https://packagist.org/downloads/",
 778:             "license": [
 779:                 "MIT"
 780:             ],
 781:             "authors": [
 782:                 {
 783:                     "name": "Graham Campbell",
 784:                     "email": "hello@gjcampbell.co.uk",
 785:                     "homepage": "https://github.com/GrahamCampbell"
 786:                 },
 787:                 {
 788:                     "name": "Michael Dowling",
 789:                     "email": "mtdowling@gmail.com",
 790:                     "homepage": "https://github.com/mtdowling"
 791:                 },
 792:                 {
 793:                     "name": "Jeremy Lindblom",
 794:                     "email": "jeremeamia@gmail.com",
 795:                     "homepage": "https://github.com/jeremeamia"
 796:                 },
 797:                 {
 798:                     "name": "George Mponos",
 799:                     "email": "gmponos@gmail.com",
 800:                     "homepage": "https://github.com/gmponos"
 801:                 },
 802:                 {
 803:                     "name": "Tobias Nyholm",
 804:                     "email": "tobias.nyholm@gmail.com",
 805:                     "homepage": "https://github.com/Nyholm"
 806:                 },
 807:                 {
 808:                     "name": "Márk Sági-Kazár",
 809:                     "email": "mark.sagikazar@gmail.com",
 810:                     "homepage": "https://github.com/sagikazarmark"
 811:                 },
 812:                 {
 813:                     "name": "Tobias Schultze",
 814:                     "email": "webmaster@tubo-world.de",
 815:                     "homepage": "https://github.com/Tobion"
 816:                 }
 817:             ],
 818:             "description": "Guzzle is a PHP HTTP client library",
 819:             "keywords": [
 820:                 "client",
 821:                 "curl",
 822:                 "framework",
 823:                 "http",
 824:                 "http client",
 825:                 "psr-18",
 826:                 "psr-7",
 827:                 "rest",
 828:                 "web service"
 829:             ],
 830:             "support": {
 831:                 "issues": "https://github.com/guzzle/guzzle/issues",
 832:                 "source": "https://github.com/guzzle/guzzle/tree/8.2.0"
 833:             },
 834:             "funding": [
 835:                 {
 836:                     "url": "https://github.com/GrahamCampbell",
 837:                     "type": "github"
 838:                 },
 839:                 {
 840:                     "url": "https://github.com/Nyholm",
 841:                     "type": "github"
 842:                 },
 843:                 {
 844:                     "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/guzzle",
 845:                     "type": "tidelift"
 846:                 }
 847:             ],
 848:             "time": "2026-09-06T13:55:09+00:00"
 849:         },
 850:         {
 851:             "name": "guzzlehttp/promises",
 852:             "version": "3.0.2",
 853:             "source": {
 854:                 "type": "git",
 855:                 "url": "https://github.com/guzzle/promises.git",
 856:                 "reference": "42118e66a53c492effaf92bc357e931985d5c6f9"
 857:             },
 858:             "dist": {
 859:                 "type": "zip",
 860:                 "url": "https://api.github.com/repos/guzzle/promises/zipball/42118e66a53c492effaf92bc357e931985d5c6f9",
 861:                 "reference": "42118e66a53c492effaf92bc357e931985d5c6f9",
 862:                 "shasum": ""
 863:             },
 864:             "require": {
 865:                 "php": "^7.4 || ^8.0"
 866:             },
 867:             "require-dev": {
 868:                 "bamarni/composer-bin-plugin": "^1.8.2",
 869:                 "phpunit/phpunit": "^9.6.34"
 870:             },
 871:             "type": "library",
 872:             "extra": {
 873:                 "bamarni-bin": {
 874:                     "bin-links": true,
 875:                     "forward-command": false
 876:                 }
 877:             },
 878:             "autoload": {
 879:                 "psr-4": {
 880:                     "GuzzleHttp\\Promise\\": "src/"
 881:                 }
 882:             },
 883:             "notification-url": "https://packagist.org/downloads/",
 884:             "license": [
 885:                 "MIT"
 886:             ],
 887:             "authors": [
 888:                 {
 889:                     "name": "Graham Campbell",
 890:                     "email": "hello@gjcampbell.co.uk",
 891:                     "homepage": "https://github.com/GrahamCampbell"
 892:                 },
 893:                 {
 894:                     "name": "Michael Dowling",
 895:                     "email": "mtdowling@gmail.com",
 896:                     "homepage": "https://github.com/mtdowling"
 897:                 },
 898:                 {
 899:                     "name": "Tobias Nyholm",
 900:                     "email": "tobias.nyholm@gmail.com",
 901:                     "homepage": "https://github.com/Nyholm"
 902:                 },
 903:                 {
 904:                     "name": "Tobias Schultze",
 905:                     "email": "webmaster@tubo-world.de",
 906:                     "homepage": "https://github.com/Tobion"
 907:                 }
 908:             ],
 909:             "description": "Guzzle promises library",
 910:             "keywords": [
 911:                 "promise"
 912:             ],
 913:             "support": {
 914:                 "issues": "https://github.com/guzzle/promises/issues",
 915:                 "source": "https://github.com/guzzle/promises/tree/3.0.2"
 916:             },
 917:             "funding": [
 918:                 {
 919:                     "url": "https://github.com/GrahamCampbell",
 920:                     "type": "github"
 921:                 },
 922:                 {
 923:                     "url": "https://github.com/Nyholm",
 924:                     "type": "github"
 925:                 },
 926:                 {
 927:                     "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/promises",
 928:                     "type": "tidelift"
 929:                 }
 930:             ],
 931:             "time": "2026-08-24T10:00:26+00:00"
 932:         },
 933:         {
 934:             "name": "guzzlehttp/psr7",
 935:             "version": "3.1.0",
 936:             "source": {
 937:                 "type": "git",
 938:                 "url": "https://github.com/guzzle/psr7.git",
 939:                 "reference": "a3059ba1a84c9139c4ae03cf0f45bea276c97c74"
 940:             },
 941:             "dist": {
 942:                 "type": "zip",
 943:                 "url": "https://api.github.com/repos/guzzle/psr7/zipball/a3059ba1a84c9139c4ae03cf0f45bea276c97c74",
 944:                 "reference": "a3059ba1a84c9139c4ae03cf0f45bea276c97c74",
 945:                 "shasum": ""
 946:             },
 947:             "require": {
 948:                 "php": "^7.4 || ^8.0",
 949:                 "psr/http-factory": "^1.1",
 950:                 "psr/http-message": "^2.0",
 951:                 "symfony/polyfill-php80": "^1.25",
 952:                 "symfony/polyfill-php82": "^1.27"
 953:             },
 954:             "provide": {
 955:                 "psr/http-factory-implementation": "1.1",
 956:                 "psr/http-message-implementation": "2.0"
 957:             },
 958:             "require-dev": {
 959:                 "bamarni/composer-bin-plugin": "^1.8.2",
 960:                 "http-interop/http-factory-tests": "1.1.0",
 961:                 "jshttp/mime-db": "1.54.0.1",
 962:                 "php-http/psr7-integration-tests": "^1.5.1",
 963:                 "phpunit/phpunit": "^9.6.34"
 964:             },
 965:             "suggest": {
 966:                 "laminas/laminas-httphandlerrunner": "Emit PSR-7 responses"
 967:             },
 968:             "type": "library",
 969:             "extra": {
 970:                 "bamarni-bin": {
 971:                     "bin-links": true,
 972:                     "forward-command": false
 973:                 }
 974:             },
 975:             "autoload": {
 976:                 "psr-4": {
 977:                     "GuzzleHttp\\Psr7\\": "src/"
 978:                 }
 979:             },
 980:             "notification-url": "https://packagist.org/downloads/",
 981:             "license": [
 982:                 "MIT"
 983:             ],
 984:             "authors": [
 985:                 {
 986:                     "name": "Graham Campbell",
 987:                     "email": "hello@gjcampbell.co.uk",
 988:                     "homepage": "https://github.com/GrahamCampbell"
 989:                 },
 990:                 {
 991:                     "name": "Michael Dowling",
 992:                     "email": "mtdowling@gmail.com",
 993:                     "homepage": "https://github.com/mtdowling"
 994:                 },
 995:                 {
 996:                     "name": "George Mponos",
 997:                     "email": "gmponos@gmail.com",
 998:                     "homepage": "https://github.com/gmponos"
 999:                 },
1000:                 {
1001:                     "name": "Tobias Nyholm",
1002:                     "email": "tobias.nyholm@gmail.com",
1003:                     "homepage": "https://github.com/Nyholm"
1004:                 },
1005:                 {
1006:                     "name": "Márk Sági-Kazár",
1007:                     "email": "mark.sagikazar@gmail.com",
1008:                     "homepage": "https://github.com/sagikazarmark"
1009:                 },
1010:                 {
1011:                     "name": "Tobias Schultze",
1012:                     "email": "webmaster@tubo-world.de",
1013:                     "homepage": "https://github.com/Tobion"
1014:                 },
1015:                 {
1016:                     "name": "Márk Sági-Kazár",
1017:                     "email": "mark.sagikazar@gmail.com",
1018:                     "homepage": "https://sagikazarmark.hu"
1019:                 }
1020:             ],
1021:             "description": "PSR-7 message implementation that also provides common utility methods",
1022:             "keywords": [
1023:                 "http",
1024:                 "message",
1025:                 "psr-7",
1026:                 "request",
1027:                 "response",
1028:                 "stream",
1029:                 "uri",
1030:                 "url"
1031:             ],
1032:             "support": {
1033:                 "issues": "https://github.com/guzzle/psr7/issues",
1034:                 "source": "https://github.com/guzzle/psr7/tree/3.1.0"
1035:             },
1036:             "funding": [
1037:                 {
1038:                     "url": "https://github.com/GrahamCampbell",
1039:                     "type": "github"
1040:                 },
1041:                 {
1042:                     "url": "https://github.com/Nyholm",
1043:                     "type": "github"
1044:                 },
1045:                 {
1046:                     "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/psr7",
1047:                     "type": "tidelift"
1048:                 }
1049:             ],
1050:             "time": "2026-08-24T11:02:13+00:00"
1051:         },
1052:         {
1053:             "name": "guzzlehttp/uri-template",
1054:             "version": "v2.0.1",
1055:             "source": {
1056:                 "type": "git",
1057:                 "url": "https://github.com/guzzle/uri-template.git",
1058:                 "reference": "7a466ad606491eb6528c717482f7cca77f1851f3"
1059:             },
1060:             "dist": {
1061:                 "type": "zip",
1062:                 "url": "https://api.github.com/repos/guzzle/uri-template/zipball/7a466ad606491eb6528c717482f7cca77f1851f3",
1063:                 "reference": "7a466ad606491eb6528c717482f7cca77f1851f3",
1064:                 "shasum": ""
1065:             },
1066:             "require": {
1067:                 "php": "^7.4 || ^8.0",
1068:                 "symfony/polyfill-php80": "^1.25"
1069:             },
1070:             "require-dev": {
1071:                 "bamarni/composer-bin-plugin": "^1.8.2",
1072:                 "phpunit/phpunit": "^9.6.34",
1073:                 "uri-template/tests": "1.0.2"
1074:             },
1075:             "type": "library",
1076:             "extra": {
1077:                 "bamarni-bin": {
1078:                     "bin-links": true,
1079:                     "forward-command": false
1080:                 }
1081:             },
1082:             "autoload": {
1083:                 "psr-4": {
1084:                     "GuzzleHttp\\UriTemplate\\": "src"
1085:                 }
1086:             },
1087:             "notification-url": "https://packagist.org/downloads/",
1088:             "license": [
1089:                 "MIT"
1090:             ],
1091:             "authors": [
1092:                 {
1093:                     "name": "Graham Campbell",
1094:                     "email": "hello@gjcampbell.co.uk",
1095:                     "homepage": "https://github.com/GrahamCampbell"
1096:                 },
1097:                 {
1098:                     "name": "Michael Dowling",
1099:                     "email": "mtdowling@gmail.com",
1100:                     "homepage": "https://github.com/mtdowling"
1101:                 },
1102:                 {
1103:                     "name": "George Mponos",
1104:                     "email": "gmponos@gmail.com",
1105:                     "homepage": "https://github.com/gmponos"
1106:                 },
1107:                 {
1108:                     "name": "Tobias Nyholm",
1109:                     "email": "tobias.nyholm@gmail.com",
1110:                     "homepage": "https://github.com/Nyholm"
1111:                 }
1112:             ],
1113:             "description": "A polyfill class for uri_template of PHP",
1114:             "keywords": [
1115:                 "guzzlehttp",
1116:                 "uri-template"
1117:             ],
1118:             "support": {
1119:                 "issues": "https://github.com/guzzle/uri-template/issues",
1120:                 "source": "https://github.com/guzzle/uri-template/tree/v2.0.1"
1121:             },
1122:             "funding": [
1123:                 {
1124:                     "url": "https://github.com/GrahamCampbell",
1125:                     "type": "github"
1126:                 },
1127:                 {
1128:                     "url": "https://github.com/Nyholm",
1129:                     "type": "github"
1130:                 },
1131:                 {
1132:                     "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/uri-template",
1133:                     "type": "tidelift"
1134:                 }
1135:             ],
1136:             "time": "2026-08-24T17:13:02+00:00"
1137:         },
1138:         {
1139:             "name": "laravel/framework",
1140:             "version": "v13.36.0",
1141:             "source": {
1142:                 "type": "git",
1143:                 "url": "https://github.com/laravel/framework.git",
1144:                 "reference": "e5626e38137cf0d82b1e52f15b16283e8442c13c"
1145:             },
1146:             "dist": {
1147:                 "type": "zip",
1148:                 "url": "https://api.github.com/repos/laravel/framework/zipball/e5626e38137cf0d82b1e52f15b16283e8442c13c",
1149:                 "reference": "e5626e38137cf0d82b1e52f15b16283e8442c13c",
1150:                 "shasum": ""
1151:             },
1152:             "require": {
1153:                 "brick/math": "^0.14.2 || ^0.15 || ^0.16 || ^0.17 || ^0.18 || ^0.19 || ^0.20 || ^1.0",
1154:                 "composer-runtime-api": "^2.2",
1155:                 "doctrine/inflector": "^2.0.5",
1156:                 "dragonmantank/cron-expression": "^3.4",
1157:                 "egulias/email-validator": "^4.0",
1158:                 "ext-ctype": "*",
1159:                 "ext-filter": "*",
1160:                 "ext-hash": "*",
1161:                 "ext-mbstring": "*",
1162:                 "ext-openssl": "*",
1163:                 "ext-session": "*",
1164:                 "ext-tokenizer": "*",
1165:                 "fruitcake/php-cors": "^1.3",
1166:                 "guzzlehttp/guzzle": "^7.8.2 || ^8.0",
1167:                 "guzzlehttp/promises": "^2.0.3 || ^3.0",
1168:                 "guzzlehttp/psr7": "^2.9 || ^3.0",
1169:                 "guzzlehttp/uri-template": "^1.0 || ^2.0",
1170:                 "laravel/prompts": "^0.3.25",
1171:                 "laravel/serializable-closure": "^2.0.10",
1172:                 "league/commonmark": "^2.8.1",
1173:                 "league/flysystem": "^3.25.1",
1174:                 "league/flysystem-local": "^3.25.1",
1175:                 "league/uri": "^7.5.1",
1176:                 "monolog/monolog": "^3.10",
1177:                 "nesbot/carbon": "^3.8.4",
1178:                 "nunomaduro/termwind": "^2.0",
1179:                 "php": "^8.3",
1180:                 "psr/container": "^1.1.1 || ^2.0.1",
1181:                 "psr/http-message": "^1.0 || ^2.0",
1182:                 "psr/log": "^1.0 || ^2.0 || ^3.0",
1183:                 "psr/simple-cache": "^1.0 || ^2.0 || ^3.0",
1184:                 "ramsey/uuid": "^4.7",
1185:                 "symfony/console": "^7.4.0 || ^8.0.0",
1186:                 "symfony/error-handler": "^7.4.0 || ^8.0.0",
1187:                 "symfony/finder": "^7.4.0 || ^8.0.0",
1188:                 "symfony/http-foundation": "^7.4.0 || ^8.0.0",
1189:                 "symfony/http-kernel": "^7.4.0 || ^8.0.0",
1190:                 "symfony/mailer": "^7.4.0 || ^8.0.0",
1191:                 "symfony/mime": "^7.4.0 || ^8.0.0",
1192:                 "symfony/polyfill-php84": "^1.36",
1193:                 "symfony/polyfill-php85": "^1.36",
1194:                 "symfony/polyfill-php86": "^1.36",
1195:                 "symfony/process": "^7.4.5 || ^8.0.5",
1196:                 "symfony/routing": "^7.4.0 || ^8.0.0",
1197:                 "symfony/uid": "^7.4.0 || ^8.0.0",
1198:                 "symfony/var-dumper": "^7.4.0 || ^8.0.0",
1199:                 "tijsverkoyen/css-to-inline-styles": "^2.2.5",
1200:                 "vlucas/phpdotenv": "^5.6.1",
1201:                 "voku/portable-ascii": "^2.0.2"
1202:             },
1203:             "conflict": {
1204:                 "tightenco/collect": "<5.5.33"
1205:             },
1206:             "provide": {
1207:                 "psr/container-implementation": "1.1 || 2.0",
1208:                 "psr/log-implementation": "1.0 || 2.0 || 3.0",
1209:                 "psr/simple-cache-implementation": "1.0 || 2.0 || 3.0"
1210:             },
1211:             "replace": {
1212:                 "illuminate/auth": "self.version",
1213:                 "illuminate/broadcasting": "self.version",
1214:                 "illuminate/bus": "self.version",
1215:                 "illuminate/cache": "self.version",
1216:                 "illuminate/collections": "self.version",
1217:                 "illuminate/concurrency": "self.version",
1218:                 "illuminate/conditionable": "self.version",
1219:                 "illuminate/config": "self.version",
1220:                 "illuminate/console": "self.version",
1221:                 "illuminate/container": "self.version",
1222:                 "illuminate/contracts": "self.version",
1223:                 "illuminate/cookie": "self.version",
1224:                 "illuminate/database": "self.version",
1225:                 "illuminate/encryption": "self.version",
1226:                 "illuminate/events": "self.version",
1227:                 "illuminate/filesystem": "self.version",
1228:                 "illuminate/hashing": "self.version",
1229:                 "illuminate/http": "self.version",
1230:                 "illuminate/image": "self.version",
1231:                 "illuminate/json-schema": "self.version",
1232:                 "illuminate/log": "self.version",
1233:                 "illuminate/macroable": "self.version",
1234:                 "illuminate/mail": "self.version",
1235:                 "illuminate/notifications": "self.version",
1236:                 "illuminate/pagination": "self.version",
1237:                 "illuminate/pipeline": "self.version",
1238:                 "illuminate/process": "self.version",
1239:                 "illuminate/queue": "self.version",
1240:                 "illuminate/redis": "self.version",
1241:                 "illuminate/reflection": "self.version",
1242:                 "illuminate/routing": "self.version",
1243:                 "illuminate/session": "self.version",
1244:                 "illuminate/support": "self.version",
1245:                 "illuminate/testing": "self.version",
1246:                 "illuminate/translation": "self.version",
1247:                 "illuminate/validation": "self.version",
1248:                 "illuminate/view": "self.version",
1249:                 "spatie/once": "*"
1250:             },
1251:             "require-dev": {
1252:                 "ably/ably-php": "^1.0",
1253:                 "aws/aws-sdk-php": "^3.322.9",
1254:                 "ext-gmp": "*",
1255:                 "fakerphp/faker": "^1.24",
1256:                 "intervention/image": "^4.0",
1257:                 "laravel/pint": "^1.18",
1258:                 "league/flysystem-aws-s3-v3": "^3.25.1",
1259:                 "league/flysystem-ftp": "^3.25.1",
1260:                 "league/flysystem-path-prefixing": "^3.25.1",
1261:                 "league/flysystem-read-only": "^3.25.1",
1262:                 "league/flysystem-sftp-v3": "^3.25.1",
1263:                 "mockery/mockery": "^1.6.10",
1264:                 "opis/json-schema": "^2.4.1",
1265:                 "orchestra/testbench-core": "^11.6.0",
1266:                 "pda/pheanstalk": "^7.0.0 || ^8.0.0",
1267:                 "php-http/discovery": "^1.15",
1268:                 "phpstan/phpstan": "2.3.1",
1269:                 "phpunit/phpunit": "^11.5.50 || ^12.5.8 || ^13.0.3",
1270:                 "predis/predis": "^2.3 || ^3.0",
1271:                 "rector/rector": "2.6.3",
1272:                 "resend/resend-php": "^1.0",
1273:                 "symfony/cache": "^7.4.0 || ^8.0.0",
1274:                 "symfony/http-client": "^7.4.0 || ^8.0.0",
1275:                 "symfony/mercure": "^0.8.0",
1276:                 "symfony/psr-http-message-bridge": "^7.4.0 || ^8.0.0",
1277:                 "symfony/translation": "^7.4.0 || ^8.0.0",
1278:                 "web-token/jwt-library": "^4.1"
1279:             },
1280:             "suggest": {
1281:                 "ably/ably-php": "Required to use the Ably broadcast driver (^1.0).",
1282:                 "aws/aws-sdk-php": "Required to use the SQS queue driver, DynamoDb failed job storage, and SES mail driver (^3.322.9).",
1283:                 "brianium/paratest": "Required to run tests in parallel (^7.0 || ^8.0).",
1284:                 "ext-apcu": "Required to use the APC cache driver.",
1285:                 "ext-fileinfo": "Required to use the Filesystem class.",
1286:                 "ext-ftp": "Required to use the Flysystem FTP driver.",
1287:                 "ext-gd": "Required to use Illuminate\\Http\\Testing\\FileFactory::image().",
1288:                 "ext-memcached": "Required to use the memcache cache driver.",
1289:                 "ext-pcntl": "Required to use all features of the queue worker and console signal trapping.",
1290:                 "ext-pdo": "Required to use all database features.",
1291:                 "ext-posix": "Required to use all features of the queue worker.",
1292:                 "ext-redis": "Required to use the Redis cache and queue drivers (^4.0 || ^5.0 || ^6.0).",
1293:                 "fakerphp/faker": "Required to generate fake data using the fake() helper (^1.23).",
1294:                 "filp/whoops": "Required for friendly error pages in development (^2.14.3).",
1295:                 "intervention/image": "Required to use the image processing features (^4.0).",
1296:                 "laravel/tinker": "Required to use the tinker console command (^2.0).",
1297:                 "league/flysystem-aws-s3-v3": "Required to use the Flysystem S3 driver (^3.25.1).",
1298:                 "league/flysystem-ftp": "Required to use the Flysystem FTP driver (^3.25.1).",
1299:                 "league/flysystem-path-prefixing": "Required to use the scoped driver (^3.25.1).",
1300:                 "league/flysystem-read-only": "Required to use read-only disks (^3.25.1)",
1301:                 "league/flysystem-sftp-v3": "Required to use the Flysystem SFTP driver (^3.25.1).",
1302:                 "mockery/mockery": "Required to use mocking (^1.6).",
1303:                 "pda/pheanstalk": "Required to use the beanstalk queue driver (^7.0 || ^8.0).",
1304:                 "php-http/discovery": "Required to use PSR-7 bridging features (^1.15).",
1305:                 "phpunit/phpunit": "Required to use assertions and run tests (^11.5.50 || ^12.5.8 || ^13.0.3).",
1306:                 "predis/predis": "Required to use the predis connector (^2.3 || ^3.0).",
1307:                 "pusher/pusher-php-server": "Required to use the Pusher broadcast driver (^6.0 || ^7.0).",
1308:                 "resend/resend-php": "Required to enable support for the Resend mail transport (^0.10.0 || ^1.0).",
1309:                 "spatie/fork": "Required to use the 'fork' concurrency driver (^1.2).",
1310:                 "symfony/cache": "Required to PSR-6 cache bridge (^7.4 || ^8.0).",
1311:                 "symfony/filesystem": "Required to enable support for relative symbolic links (^7.4 || ^8.0).",
1312:                 "symfony/http-client": "Required to enable support for the Symfony API mail transports (^7.4 || ^8.0).",
1313:                 "symfony/mailgun-mailer": "Required to enable support for the Mailgun mail transport (^7.4 || ^8.0).",
1314:                 "symfony/mercure": "Required to use the Mercure broadcast driver (^0.8).",
1315:                 "symfony/postmark-mailer": "Required to enable support for the Postmark mail transport (^7.4 || ^8.0).",
1316:                 "symfony/psr-http-message-bridge": "Required to use PSR-7 bridging features (^7.4 || ^8.0).",
1317:                 "web-token/jwt-library": "Required to sign Mercure JWTs and use end-to-end encrypted channels (^4.1)."
1318:             },
1319:             "type": "library",
1320:             "extra": {
1321:                 "branch-alias": {
1322:                     "dev-master": "13.0.x-dev"
1323:                 }
1324:             },
1325:             "autoload": {
1326:                 "files": [
1327:                     "src/Illuminate/Collections/functions.php",
1328:                     "src/Illuminate/Collections/helpers.php",
1329:                     "src/Illuminate/Events/functions.php",
1330:                     "src/Illuminate/Filesystem/functions.php",
1331:                     "src/Illuminate/Foundation/helpers.php",
1332:                     "src/Illuminate/Log/functions.php",
1333:                     "src/Illuminate/Reflection/helpers.php",
1334:                     "src/Illuminate/Support/functions.php",
1335:                     "src/Illuminate/Support/helpers.php"
1336:                 ],
1337:                 "psr-4": {
1338:                     "Illuminate\\": "src/Illuminate/",
1339:                     "Illuminate\\Support\\": [
1340:                         "src/Illuminate/Macroable/",
1341:                         "src/Illuminate/Collections/",
1342:                         "src/Illuminate/Conditionable/",
1343:                         "src/Illuminate/Reflection/"
1344:                     ]
1345:                 }
1346:             },
1347:             "notification-url": "https://packagist.org/downloads/",
1348:             "license": [
1349:                 "MIT"
1350:             ],
1351:             "authors": [
1352:                 {
1353:                     "name": "Taylor Otwell",
1354:                     "email": "taylor@laravel.com"
1355:                 }
1356:             ],
1357:             "description": "The Laravel Framework.",
1358:             "homepage": "https://laravel.com",
1359:             "keywords": [
1360:                 "framework",
1361:                 "laravel"
1362:             ],
1363:             "support": {
1364:                 "issues": "https://github.com/laravel/framework/issues",
1365:                 "source": "https://github.com/laravel/framework"
1366:             },
1367:             "time": "2026-10-13T13:21:00+00:00"
1368:         },
1369:         {
1370:             "name": "laravel/prompts",
1371:             "version": "v0.3.26",
1372:             "source": {
1373:                 "type": "git",
1374:                 "url": "https://github.com/laravel/prompts.git",
1375:                 "reference": "2d1e9bf6bab8d3f383c254db7f2b4ea8816d55e0"
1376:             },
1377:             "dist": {
1378:                 "type": "zip",
1379:                 "url": "https://api.github.com/repos/laravel/prompts/zipball/2d1e9bf6bab8d3f383c254db7f2b4ea8816d55e0",
1380:                 "reference": "2d1e9bf6bab8d3f383c254db7f2b4ea8816d55e0",
1381:                 "shasum": ""
1382:             },
1383:             "require": {
1384:                 "composer-runtime-api": "^2.2",
1385:                 "ext-mbstring": "*",
1386:                 "php": "^8.1",
1387:                 "symfony/console": "^6.2|^7.0|^8.0"
1388:             },
1389:             "conflict": {
1390:                 "illuminate/console": ">=10.17.0 <10.25.0",
1391:                 "laravel/framework": ">=10.17.0 <10.25.0"
1392:             },
1393:             "require-dev": {
1394:                 "illuminate/collections": "^10.0|^11.0|^12.0|^13.0",
1395:                 "mockery/mockery": "^1.5",
1396:                 "pestphp/pest": "^2.3|^3.4|^4.0",
1397:                 "phpstan/phpstan": "^1.12.28",
1398:                 "phpstan/phpstan-mockery": "^1.1.3"
1399:             },
1400:             "suggest": {
1401:                 "ext-pcntl": "Required for the spinner to be animated."
1402:             },
1403:             "type": "library",
1404:             "extra": {
1405:                 "branch-alias": {
1406:                     "dev-main": "0.3.x-dev"
1407:                 }
1408:             },
1409:             "autoload": {
1410:                 "files": [
1411:                     "src/helpers.php"
1412:                 ],
1413:                 "psr-4": {
1414:                     "Laravel\\Prompts\\": "src/"
1415:                 }
1416:             },
1417:             "notification-url": "https://packagist.org/downloads/",
1418:             "license": [
1419:                 "MIT"
1420:             ],
1421:             "description": "Add beautiful and user-friendly forms to your command-line applications.",
1422:             "support": {
1423:                 "source": "https://github.com/laravel/prompts/tree/v0.3.26"
1424:             },
1425:             "time": "2026-10-09T14:45:13+00:00"
1426:         },
1427:         {
1428:             "name": "laravel/sanctum",
1429:             "version": "v4.3.3",
1430:             "source": {
1431:                 "type": "git",
1432:                 "url": "https://github.com/laravel/sanctum.git",
1433:                 "reference": "fee27a573d1a013af3721d86153a65e0b11927e6"
1434:             },
1435:             "dist": {
1436:                 "type": "zip",
1437:                 "url": "https://api.github.com/repos/laravel/sanctum/zipball/fee27a573d1a013af3721d86153a65e0b11927e6",
1438:                 "reference": "fee27a573d1a013af3721d86153a65e0b11927e6",
1439:                 "shasum": ""
1440:             },
1441:             "require": {
1442:                 "ext-json": "*",
1443:                 "illuminate/console": "^11.0|^12.0|^13.0",
1444:                 "illuminate/contracts": "^11.0|^12.0|^13.0",
1445:                 "illuminate/database": "^11.0|^12.0|^13.0",
1446:                 "illuminate/support": "^11.0|^12.0|^13.0",
1447:                 "php": "^8.2",
1448:                 "symfony/console": "^7.0|^8.0"
1449:             },
1450:             "require-dev": {
1451:                 "mockery/mockery": "^1.6",
1452:                 "orchestra/testbench": "^9.15|^10.8|^11.0",
1453:                 "phpstan/phpstan": "^1.10"
1454:             },
1455:             "type": "library",
1456:             "extra": {
1457:                 "laravel": {
1458:                     "providers": [
1459:                         "Laravel\\Sanctum\\SanctumServiceProvider"
1460:                     ]
1461:                 }
1462:             },
1463:             "autoload": {
1464:                 "psr-4": {
1465:                     "Laravel\\Sanctum\\": "src/"
1466:                 }
1467:             },
1468:             "notification-url": "https://packagist.org/downloads/",
1469:             "license": [
1470:                 "MIT"
1471:             ],
1472:             "authors": [
1473:                 {
1474:                     "name": "Taylor Otwell",
1475:                     "email": "taylor@laravel.com"
1476:                 }
1477:             ],
1478:             "description": "Laravel Sanctum provides a featherweight authentication system for SPAs and simple APIs.",
1479:             "keywords": [
1480:                 "auth",
1481:                 "laravel",
1482:                 "sanctum"
1483:             ],
1484:             "support": {
1485:                 "issues": "https://github.com/laravel/sanctum/issues",
1486:                 "source": "https://github.com/laravel/sanctum"
1487:             },
1488:             "time": "2026-06-23T18:26:55+00:00"
1489:         },
1490:         {
1491:             "name": "laravel/serializable-closure",
1492:             "version": "v2.1.0",
1493:             "source": {
1494:                 "type": "git",
1495:                 "url": "https://github.com/laravel/serializable-closure.git",
1496:                 "reference": "2d5869a838bbcf37e0d8b0568fc41914e81374b5"
1497:             },
1498:             "dist": {
1499:                 "type": "zip",
1500:                 "url": "https://api.github.com/repos/laravel/serializable-closure/zipball/2d5869a838bbcf37e0d8b0568fc41914e81374b5",
1501:                 "reference": "2d5869a838bbcf37e0d8b0568fc41914e81374b5",
1502:                 "shasum": ""
1503:             },
1504:             "require": {
1505:                 "php": "^8.1"
1506:             },
1507:             "require-dev": {
1508:                 "illuminate/support": "^10.0|^11.0|^12.0|^13.0",
1509:                 "nesbot/carbon": "^2.67|^3.0",
1510:                 "pestphp/pest": "^2.36|^3.0|^4.0",
1511:                 "phpstan/phpstan": "^2.0",
1512:                 "symfony/var-dumper": "^6.2.0|^7.0.0|^8.0.0"
1513:             },
1514:             "type": "library",
1515:             "extra": {
1516:                 "branch-alias": {
1517:                     "dev-master": "2.x-dev"
1518:                 }
1519:             },
1520:             "autoload": {
1521:                 "psr-4": {
1522:                     "Laravel\\SerializableClosure\\": "src/"
1523:                 }
1524:             },
1525:             "notification-url": "https://packagist.org/downloads/",
1526:             "license": [
1527:                 "MIT"
1528:             ],
1529:             "authors": [
1530:                 {
1531:                     "name": "Taylor Otwell",
1532:                     "email": "taylor@laravel.com"
1533:                 },
1534:                 {
1535:                     "name": "Nuno Maduro",
1536:                     "email": "nuno@laravel.com"
1537:                 }
1538:             ],
1539:             "description": "Laravel Serializable Closure provides an easy and secure way to serialize closures in PHP.",
1540:             "keywords": [
1541:                 "closure",
1542:                 "laravel",
1543:                 "serializable"
1544:             ],
1545:             "support": {
1546:                 "issues": "https://github.com/laravel/serializable-closure/issues",
1547:                 "source": "https://github.com/laravel/serializable-closure"
1548:             },
1549:             "time": "2026-09-22T14:32:34+00:00"
1550:         },
1551:         {
1552:             "name": "laravel/tinker",
1553:             "version": "v3.0.2",
1554:             "source": {
1555:                 "type": "git",
1556:                 "url": "https://github.com/laravel/tinker.git",
1557:                 "reference": "4faba77764bd33411735936acdf30446d058c78b"
1558:             },
1559:             "dist": {
1560:                 "type": "zip",
1561:                 "url": "https://api.github.com/repos/laravel/tinker/zipball/4faba77764bd33411735936acdf30446d058c78b",
1562:                 "reference": "4faba77764bd33411735936acdf30446d058c78b",
1563:                 "shasum": ""
1564:             },
1565:             "require": {
1566:                 "illuminate/console": "^8.0|^9.0|^10.0|^11.0|^12.0|^13.0",
1567:                 "illuminate/contracts": "^8.0|^9.0|^10.0|^11.0|^12.0|^13.0",
1568:                 "illuminate/support": "^8.0|^9.0|^10.0|^11.0|^12.0|^13.0",
1569:                 "php": "^8.1",
1570:                 "psy/psysh": "^0.12.0",
1571:                 "symfony/var-dumper": "^5.4|^6.0|^7.0|^8.0"
1572:             },
1573:             "require-dev": {
1574:                 "mockery/mockery": "~1.3.3|^1.4.2",
1575:                 "phpstan/phpstan": "^1.10",
1576:                 "phpunit/phpunit": "^10.5|^11.5"
1577:             },
1578:             "suggest": {
1579:                 "illuminate/database": "The Illuminate Database package (^8.0|^9.0|^10.0|^11.0|^12.0|^13.0)."
1580:             },
1581:             "type": "library",
1582:             "extra": {
1583:                 "laravel": {
1584:                     "providers": [
1585:                         "Laravel\\Tinker\\TinkerServiceProvider"
1586:                     ]
1587:                 },
1588:                 "branch-alias": {
1589:                     "dev-master": "3.x-dev"
1590:                 }
1591:             },
1592:             "autoload": {
1593:                 "psr-4": {
1594:                     "Laravel\\Tinker\\": "src/"
1595:                 }
1596:             },
1597:             "notification-url": "https://packagist.org/downloads/",
1598:             "license": [
1599:                 "MIT"
1600:             ],
1601:             "authors": [
1602:                 {
1603:                     "name": "Taylor Otwell",
1604:                     "email": "taylor@laravel.com"
1605:                 }
1606:             ],
1607:             "description": "Powerful REPL for the Laravel framework.",
1608:             "keywords": [
1609:                 "REPL",
1610:                 "Tinker",
1611:                 "laravel",
1612:                 "psysh"
1613:             ],
1614:             "support": {
1615:                 "issues": "https://github.com/laravel/tinker/issues",
1616:                 "source": "https://github.com/laravel/tinker/tree/v3.0.2"
1617:             },
1618:             "time": "2026-03-17T14:54:13+00:00"
1619:         },
1620:         {
1621:             "name": "league/commonmark",
1622:             "version": "2.10.3",
1623:             "source": {
1624:                 "type": "git",
1625:                 "url": "https://github.com/thephpleague/commonmark.git",
1626:                 "reference": "6efbd9c472b91db0a3350fcd601c8332c2382e1f"
1627:             },
1628:             "dist": {
1629:                 "type": "zip",
1630:                 "url": "https://api.github.com/repos/thephpleague/commonmark/zipball/6efbd9c472b91db0a3350fcd601c8332c2382e1f",
1631:                 "reference": "6efbd9c472b91db0a3350fcd601c8332c2382e1f",
1632:                 "shasum": ""
1633:             },
1634:             "require": {
1635:                 "ext-mbstring": "*",
1636:                 "league/config": "^1.1.1",
1637:                 "php": "^7.4 || ^8.0",
1638:                 "psr/event-dispatcher": "^1.0",
1639:                 "symfony/deprecation-contracts": "^2.1 || ^3.0",
1640:                 "symfony/polyfill-php80": "^1.16"
1641:             },
1642:             "require-dev": {
1643:                 "cebe/markdown": "^1.0",
1644:                 "commonmark/cmark": "0.31.1",
1645:                 "commonmark/commonmark.js": "0.31.1",
1646:                 "composer/package-versions-deprecated": "^1.8",
1647:                 "embed/embed": "^4.4",
1648:                 "erusev/parsedown": "^1.0",
1649:                 "ext-json": "*",
1650:                 "github/gfm": "0.29.0",
1651:                 "michelf/php-markdown": "^1.4 || ^2.0",
1652:                 "nyholm/psr7": "^1.5",
1653:                 "phpstan/phpstan": "^2.0.0",
1654:                 "phpunit/phpunit": "^9.5.21 || ^10.5.9 || ^11.0.0 || ^12.0.0 || ^13.0.0",
1655:                 "scrutinizer/ocular": "^1.8.1",
1656:                 "symfony/finder": "^5.3 | ^6.0 | ^7.0 || ^8.0",
1657:                 "symfony/process": "^5.4 | ^6.0 | ^7.0 || ^8.0",
1658:                 "symfony/yaml": "^2.3 | ^3.0 | ^4.0 | ^5.0 | ^6.0 | ^7.0 || ^8.0",
1659:                 "unleashedtech/php-coding-standard": "^3.1.1",
1660:                 "vimeo/psalm": "^4.24.0 || ^5.0.0 || ^6.0.0"
1661:             },
1662:             "suggest": {
1663:                 "symfony/yaml": "v2.3+ required if using the Front Matter extension"
1664:             },
1665:             "type": "library",
1666:             "extra": {
1667:                 "branch-alias": {
1668:                     "dev-main": "2.11-dev"
1669:                 }
1670:             },
1671:             "autoload": {
1672:                 "psr-4": {
1673:                     "League\\CommonMark\\": "src"
1674:                 }
1675:             },
1676:             "notification-url": "https://packagist.org/downloads/",
1677:             "license": [
1678:                 "BSD-3-Clause"
1679:             ],
1680:             "authors": [
1681:                 {
1682:                     "name": "Colin O'Dell",
1683:                     "email": "colinodell@gmail.com",
1684:                     "homepage": "https://www.colinodell.com",
1685:                     "role": "Lead Developer"
1686:                 }
1687:             ],
1688:             "description": "Highly-extensible PHP Markdown parser which fully supports the CommonMark spec and GitHub-Flavored Markdown (GFM)",
1689:             "homepage": "https://commonmark.thephpleague.com",
1690:             "keywords": [
1691:                 "commonmark",
1692:                 "flavored",
1693:                 "gfm",
1694:                 "github",
1695:                 "github-flavored",
1696:                 "markdown",
1697:                 "md",
1698:                 "parser"
1699:             ],
1700:             "support": {
1701:                 "docs": "https://commonmark.thephpleague.com/",
1702:                 "forum": "https://github.com/thephpleague/commonmark/discussions",
1703:                 "issues": "https://github.com/thephpleague/commonmark/issues",
1704:                 "rss": "https://github.com/thephpleague/commonmark/releases.atom",
1705:                 "source": "https://github.com/thephpleague/commonmark"
1706:             },
1707:             "funding": [
1708:                 {
1709:                     "url": "https://www.colinodell.com/sponsor",
1710:                     "type": "custom"
1711:                 },
1712:                 {
1713:                     "url": "https://www.paypal.me/colinpodell/10.00",
1714:                     "type": "custom"
1715:                 },
1716:                 {
1717:                     "url": "https://github.com/colinodell",
1718:                     "type": "github"
1719:                 },
1720:                 {
1721:                     "url": "https://tidelift.com/funding/github/packagist/league/commonmark",
1722:                     "type": "tidelift"
1723:                 }
1724:             ],
1725:             "time": "2026-09-21T13:07:34+00:00"
1726:         },
1727:         {
1728:             "name": "league/config",
1729:             "version": "v1.2.0",
1730:             "source": {
1731:                 "type": "git",
1732:                 "url": "https://github.com/thephpleague/config.git",
1733:                 "reference": "754b3604fb2984c71f4af4a9cbe7b57f346ec1f3"
1734:             },
1735:             "dist": {
1736:                 "type": "zip",
1737:                 "url": "https://api.github.com/repos/thephpleague/config/zipball/754b3604fb2984c71f4af4a9cbe7b57f346ec1f3",
1738:                 "reference": "754b3604fb2984c71f4af4a9cbe7b57f346ec1f3",
1739:                 "shasum": ""
1740:             },
1741:             "require": {
1742:                 "dflydev/dot-access-data": "^3.0.1",
1743:                 "nette/schema": "^1.2",
1744:                 "php": "^7.4 || ^8.0"
1745:             },
1746:             "require-dev": {
1747:                 "phpstan/phpstan": "^1.8.2",
1748:                 "phpunit/phpunit": "^9.5.5",
1749:                 "scrutinizer/ocular": "^1.8.1",
1750:                 "unleashedtech/php-coding-standard": "^3.1",
1751:                 "vimeo/psalm": "^4.7.3"
1752:             },
1753:             "type": "library",
1754:             "extra": {
1755:                 "branch-alias": {
1756:                     "dev-main": "1.2-dev"
1757:                 }
1758:             },
1759:             "autoload": {
1760:                 "psr-4": {
1761:                     "League\\Config\\": "src"
1762:                 }
1763:             },
1764:             "notification-url": "https://packagist.org/downloads/",
1765:             "license": [
1766:                 "BSD-3-Clause"
1767:             ],
1768:             "authors": [
1769:                 {
1770:                     "name": "Colin O'Dell",
1771:                     "email": "colinodell@gmail.com",
1772:                     "homepage": "https://www.colinodell.com",
1773:                     "role": "Lead Developer"
1774:                 }
1775:             ],
1776:             "description": "Define configuration arrays with strict schemas and access values with dot notation",
1777:             "homepage": "https://config.thephpleague.com",
1778:             "keywords": [
1779:                 "array",
1780:                 "config",
1781:                 "configuration",
1782:                 "dot",
1783:                 "dot-access",
1784:                 "nested",
1785:                 "schema"
1786:             ],
1787:             "support": {
1788:                 "docs": "https://config.thephpleague.com/",
1789:                 "issues": "https://github.com/thephpleague/config/issues",
1790:                 "rss": "https://github.com/thephpleague/config/releases.atom",
1791:                 "source": "https://github.com/thephpleague/config"
1792:             },
1793:             "funding": [
1794:                 {
1795:                     "url": "https://www.colinodell.com/sponsor",
1796:                     "type": "custom"
1797:                 },
1798:                 {
1799:                     "url": "https://www.paypal.me/colinpodell/10.00",
1800:                     "type": "custom"
1801:                 },
1802:                 {
1803:                     "url": "https://github.com/colinodell",
1804:                     "type": "github"
1805:                 }
1806:             ],
1807:             "time": "2022-12-11T20:36:23+00:00"
1808:         },
1809:         {
1810:             "name": "league/flysystem",
1811:             "version": "3.36.0",
1812:             "source": {
1813:                 "type": "git",
1814:                 "url": "https://github.com/thephpleague/flysystem.git",
1815:                 "reference": "f7fb152932f30072d573510cbd4dd657d6475b25"
1816:             },
1817:             "dist": {
1818:                 "type": "zip",
1819:                 "url": "https://api.github.com/repos/thephpleague/flysystem/zipball/f7fb152932f30072d573510cbd4dd657d6475b25",
1820:                 "reference": "f7fb152932f30072d573510cbd4dd657d6475b25",
1821:                 "shasum": ""
1822:             },
1823:             "require": {
1824:                 "league/flysystem-local": "^3.0.0",
1825:                 "league/mime-type-detection": "^1.0.0",
1826:                 "php": "^8.0.2"
1827:             },
1828:             "conflict": {
1829:                 "async-aws/core": "<1.19.0",
1830:                 "async-aws/s3": "<1.14.0",
1831:                 "aws/aws-sdk-php": "3.209.31 || 3.210.0",
1832:                 "guzzlehttp/guzzle": "<7.0",
1833:                 "guzzlehttp/ringphp": "<1.1.1",
1834:                 "phpseclib/phpseclib": "3.0.15",
1835:                 "symfony/http-client": "<5.2"
1836:             },
1837:             "require-dev": {
1838:                 "async-aws/s3": "^1.5 || ^2.0",
1839:                 "async-aws/simple-s3": "^1.1 || ^2.0",
1840:                 "aws/aws-sdk-php": "^3.295.10",
1841:                 "composer/semver": "^3.0",
1842:                 "ext-fileinfo": "*",
1843:                 "ext-ftp": "*",
1844:                 "ext-mongodb": "^1.3|^2",
1845:                 "ext-zip": "*",
1846:                 "friendsofphp/php-cs-fixer": "^3.5",
1847:                 "google/cloud-storage": "^1.23",
1848:                 "guzzlehttp/psr7": "^2.6",
1849:                 "microsoft/azure-storage-blob": "^1.1",
1850:                 "mongodb/mongodb": "^1.2|^2",
1851:                 "phpseclib/phpseclib": "^3.0.36",
1852:                 "phpstan/phpstan": "^1.10",
1853:                 "phpunit/phpunit": "^9.5.11|^10.0",
1854:                 "sabre/dav": "^4.6.0"
1855:             },
1856:             "type": "library",
1857:             "autoload": {
1858:                 "psr-4": {
1859:                     "League\\Flysystem\\": "src"
1860:                 }
1861:             },
1862:             "notification-url": "https://packagist.org/downloads/",
1863:             "license": [
1864:                 "MIT"
1865:             ],
1866:             "authors": [
1867:                 {
1868:                     "name": "Frank de Jonge",
1869:                     "email": "info@frankdejonge.nl"
1870:                 }
1871:             ],
1872:             "description": "File storage abstraction for PHP",
1873:             "keywords": [
1874:                 "WebDAV",
1875:                 "aws",
1876:                 "cloud",
1877:                 "file",
1878:                 "files",
1879:                 "filesystem",
1880:                 "filesystems",
1881:                 "ftp",
1882:                 "s3",
1883:                 "sftp",
1884:                 "storage"
1885:             ],
1886:             "support": {
1887:                 "issues": "https://github.com/thephpleague/flysystem/issues",
1888:                 "source": "https://github.com/thephpleague/flysystem/tree/3.36.0"
1889:             },
1890:             "time": "2026-09-02T08:00:27+00:00"
1891:         },
1892:         {
1893:             "name": "league/flysystem-local",
1894:             "version": "3.35.3",
1895:             "source": {
1896:                 "type": "git",
1897:                 "url": "https://github.com/thephpleague/flysystem-local.git",
1898:                 "reference": "a099b24dce160f3b2239043d13d47c4a1a214ea4"
1899:             },
1900:             "dist": {
1901:                 "type": "zip",
1902:                 "url": "https://api.github.com/repos/thephpleague/flysystem-local/zipball/a099b24dce160f3b2239043d13d47c4a1a214ea4",
1903:                 "reference": "a099b24dce160f3b2239043d13d47c4a1a214ea4",
1904:                 "shasum": ""
1905:             },
1906:             "require": {
1907:                 "ext-fileinfo": "*",
1908:                 "league/flysystem": "^3.0.0",
1909:                 "league/mime-type-detection": "^1.0.0",
1910:                 "php": "^8.0.2"
1911:             },
1912:             "type": "library",
1913:             "autoload": {
1914:                 "psr-4": {
1915:                     "League\\Flysystem\\Local\\": ""
1916:                 }
1917:             },
1918:             "notification-url": "https://packagist.org/downloads/",
1919:             "license": [
1920:                 "MIT"
1921:             ],
1922:             "authors": [
1923:                 {
1924:                     "name": "Frank de Jonge",
1925:                     "email": "info@frankdejonge.nl"
1926:                 }
1927:             ],
1928:             "description": "Local filesystem adapter for Flysystem.",
1929:             "keywords": [
1930:                 "Flysystem",
1931:                 "file",
1932:                 "files",
1933:                 "filesystem",
1934:                 "local"
1935:             ],
1936:             "support": {
1937:                 "source": "https://github.com/thephpleague/flysystem-local/tree/3.35.3"
1938:             },
1939:             "time": "2026-08-12T13:29:21+00:00"
1940:         },
1941:         {
1942:             "name": "league/mime-type-detection",
1943:             "version": "1.17.0",
1944:             "source": {
1945:                 "type": "git",
1946:                 "url": "https://github.com/thephpleague/mime-type-detection.git",
1947:                 "reference": "f5f47eff7c48ed1003069a2ca67f316fb4021c76"
1948:             },
1949:             "dist": {
1950:                 "type": "zip",
1951:                 "url": "https://api.github.com/repos/thephpleague/mime-type-detection/zipball/f5f47eff7c48ed1003069a2ca67f316fb4021c76",
1952:                 "reference": "f5f47eff7c48ed1003069a2ca67f316fb4021c76",
1953:                 "shasum": ""
1954:             },
1955:             "require": {
1956:                 "ext-fileinfo": "*",
1957:                 "php": "^7.4 || ^8.0"
1958:             },
1959:             "require-dev": {
1960:                 "friendsofphp/php-cs-fixer": "^3.2",
1961:                 "phpstan/phpstan": "^0.12.68",
1962:                 "phpunit/phpunit": "^8.5.8 || ^9.3 || ^10.0 || ^11.0 || ^12.0"
1963:             },
1964:             "type": "library",
1965:             "autoload": {
1966:                 "psr-4": {
1967:                     "League\\MimeTypeDetection\\": "src"
1968:                 }
1969:             },
1970:             "notification-url": "https://packagist.org/downloads/",
1971:             "license": [
1972:                 "MIT"
1973:             ],
1974:             "authors": [
1975:                 {
1976:                     "name": "Frank de Jonge",
1977:                     "email": "info@frankdejonge.nl"
1978:                 }
1979:             ],
1980:             "description": "Mime-type detection for Flysystem",
1981:             "support": {
1982:                 "issues": "https://github.com/thephpleague/mime-type-detection/issues",
1983:                 "source": "https://github.com/thephpleague/mime-type-detection/tree/1.17.0"
1984:             },
1985:             "funding": [
1986:                 {
1987:                     "url": "https://github.com/frankdejonge",
1988:                     "type": "github"
1989:                 },
1990:                 {
1991:                     "url": "https://tidelift.com/funding/github/packagist/league/flysystem",
1992:                     "type": "tidelift"
1993:                 }
1994:             ],
1995:             "time": "2026-07-09T11:49:27+00:00"
1996:         },
1997:         {
1998:             "name": "league/uri",
1999:             "version": "7.8.1",
2000:             "source": {
2001:                 "type": "git",
2002:                 "url": "https://github.com/thephpleague/uri.git",
2003:                 "reference": "08cf38e3924d4f56238125547b5720496fac8fd4"
2004:             },
2005:             "dist": {
2006:                 "type": "zip",
2007:                 "url": "https://api.github.com/repos/thephpleague/uri/zipball/08cf38e3924d4f56238125547b5720496fac8fd4",
2008:                 "reference": "08cf38e3924d4f56238125547b5720496fac8fd4",
2009:                 "shasum": ""
2010:             },
2011:             "require": {
2012:                 "league/uri-interfaces": "^7.8.1",
2013:                 "php": "^8.1",
2014:                 "psr/http-factory": "^1"
2015:             },
2016:             "conflict": {
2017:                 "league/uri-schemes": "^1.0"
2018:             },
2019:             "suggest": {
2020:                 "ext-bcmath": "to improve IPV4 host parsing",
2021:                 "ext-dom": "to convert the URI into an HTML anchor tag",
2022:                 "ext-fileinfo": "to create Data URI from file contennts",
2023:                 "ext-gmp": "to improve IPV4 host parsing",
2024:                 "ext-intl": "to handle IDN host with the best performance",
2025:                 "ext-uri": "to use the PHP native URI class",
2026:                 "jeremykendall/php-domain-parser": "to further parse the URI host and resolve its Public Suffix and Top Level Domain",
2027:                 "league/uri-components": "to provide additional tools to manipulate URI objects components",
2028:                 "league/uri-polyfill": "to backport the PHP URI extension for older versions of PHP",
2029:                 "php-64bit": "to improve IPV4 host parsing",
2030:                 "rowbot/url": "to handle URLs using the WHATWG URL Living Standard specification",
2031:                 "symfony/polyfill-intl-idn": "to handle IDN host via the Symfony polyfill if ext-intl is not present"
2032:             },
2033:             "type": "library",
2034:             "extra": {
2035:                 "branch-alias": {
2036:                     "dev-master": "7.x-dev"
2037:                 }
2038:             },
2039:             "autoload": {
2040:                 "psr-4": {
2041:                     "League\\Uri\\": ""
2042:                 }
2043:             },
2044:             "notification-url": "https://packagist.org/downloads/",
2045:             "license": [
2046:                 "MIT"
2047:             ],
2048:             "authors": [
2049:                 {
2050:                     "name": "Ignace Nyamagana Butera",
2051:                     "email": "nyamsprod@gmail.com",
2052:                     "homepage": "https://nyamsprod.com"
2053:                 }
2054:             ],
2055:             "description": "URI manipulation library",
2056:             "homepage": "https://uri.thephpleague.com",
2057:             "keywords": [
2058:                 "URN",
2059:                 "data-uri",
2060:                 "file-uri",
2061:                 "ftp",
2062:                 "hostname",
2063:                 "http",
2064:                 "https",
2065:                 "middleware",
2066:                 "parse_str",
2067:                 "parse_url",
2068:                 "psr-7",
2069:                 "query-string",
2070:                 "querystring",
2071:                 "rfc2141",
2072:                 "rfc3986",
2073:                 "rfc3987",
2074:                 "rfc6570",
2075:                 "rfc8141",
2076:                 "uri",
2077:                 "uri-template",
2078:                 "url",
2079:                 "ws"
2080:             ],
2081:             "support": {
2082:                 "docs": "https://uri.thephpleague.com",
2083:                 "forum": "https://thephpleague.slack.com",
2084:                 "issues": "https://github.com/thephpleague/uri-src/issues",
2085:                 "source": "https://github.com/thephpleague/uri/tree/7.8.1"
2086:             },
2087:             "funding": [
2088:                 {
2089:                     "url": "https://github.com/sponsors/nyamsprod",
2090:                     "type": "github"
2091:                 }
2092:             ],
2093:             "time": "2026-03-15T20:22:25+00:00"
2094:         },
2095:         {
2096:             "name": "league/uri-interfaces",
2097:             "version": "7.8.1",
2098:             "source": {
2099:                 "type": "git",
2100:                 "url": "https://github.com/thephpleague/uri-interfaces.git",
2101:                 "reference": "85d5c77c5d6d3af6c54db4a78246364908f3c928"
2102:             },
2103:             "dist": {
2104:                 "type": "zip",
2105:                 "url": "https://api.github.com/repos/thephpleague/uri-interfaces/zipball/85d5c77c5d6d3af6c54db4a78246364908f3c928",
2106:                 "reference": "85d5c77c5d6d3af6c54db4a78246364908f3c928",
2107:                 "shasum": ""
2108:             },
2109:             "require": {
2110:                 "ext-filter": "*",
2111:                 "php": "^8.1",
2112:                 "psr/http-message": "^1.1 || ^2.0"
2113:             },
2114:             "suggest": {
2115:                 "ext-bcmath": "to improve IPV4 host parsing",
2116:                 "ext-gmp": "to improve IPV4 host parsing",
2117:                 "ext-intl": "to handle IDN host with the best performance",
2118:                 "php-64bit": "to improve IPV4 host parsing",
2119:                 "rowbot/url": "to handle URLs using the WHATWG URL Living Standard specification",
2120:                 "symfony/polyfill-intl-idn": "to handle IDN host via the Symfony polyfill if ext-intl is not present"
2121:             },
2122:             "type": "library",
2123:             "extra": {
2124:                 "branch-alias": {
2125:                     "dev-master": "7.x-dev"
2126:                 }
2127:             },
2128:             "autoload": {
2129:                 "psr-4": {
2130:                     "League\\Uri\\": ""
2131:                 }
2132:             },
2133:             "notification-url": "https://packagist.org/downloads/",
2134:             "license": [
2135:                 "MIT"
2136:             ],
2137:             "authors": [
2138:                 {
2139:                     "name": "Ignace Nyamagana Butera",
2140:                     "email": "nyamsprod@gmail.com",
2141:                     "homepage": "https://nyamsprod.com"
2142:                 }
2143:             ],
2144:             "description": "Common tools for parsing and resolving RFC3987/RFC3986 URI",
2145:             "homepage": "https://uri.thephpleague.com",
2146:             "keywords": [
2147:                 "data-uri",
2148:                 "file-uri",
2149:                 "ftp",
2150:                 "hostname",
2151:                 "http",
2152:                 "https",
2153:                 "parse_str",
2154:                 "parse_url",
2155:                 "psr-7",
2156:                 "query-string",
2157:                 "querystring",
2158:                 "rfc3986",
2159:                 "rfc3987",
2160:                 "rfc6570",
2161:                 "uri",
2162:                 "url",
2163:                 "ws"
2164:             ],
2165:             "support": {
2166:                 "docs": "https://uri.thephpleague.com",
2167:                 "forum": "https://thephpleague.slack.com",
2168:                 "issues": "https://github.com/thephpleague/uri-src/issues",
2169:                 "source": "https://github.com/thephpleague/uri-interfaces/tree/7.8.1"
2170:             },
2171:             "funding": [
2172:                 {
2173:                     "url": "https://github.com/sponsors/nyamsprod",
2174:                     "type": "github"
2175:                 }
2176:             ],
2177:             "time": "2026-03-08T20:05:35+00:00"
2178:         },
2179:         {
2180:             "name": "maennchen/zipstream-php",
2181:             "version": "3.2.2",
2182:             "source": {
2183:                 "type": "git",
2184:                 "url": "https://github.com/maennchen/ZipStream-PHP.git",
2185:                 "reference": "77bebeb4c6c340bb3c11c843b2cffd8bbfde4d5e"
2186:             },
2187:             "dist": {
2188:                 "type": "zip",
2189:                 "url": "https://api.github.com/repos/maennchen/ZipStream-PHP/zipball/77bebeb4c6c340bb3c11c843b2cffd8bbfde4d5e",
2190:                 "reference": "77bebeb4c6c340bb3c11c843b2cffd8bbfde4d5e",
2191:                 "shasum": ""
2192:             },
2193:             "require": {
2194:                 "ext-mbstring": "*",
2195:                 "ext-zlib": "*",
2196:                 "php-64bit": "^8.3"
2197:             },
2198:             "require-dev": {
2199:                 "brianium/paratest": "^7.7",
2200:                 "ext-zip": "*",
2201:                 "friendsofphp/php-cs-fixer": "^3.86",
2202:                 "guzzlehttp/guzzle": "^7.5",
2203:                 "mikey179/vfsstream": "^1.6",
2204:                 "php-coveralls/php-coveralls": "^2.5",
2205:                 "phpunit/phpunit": "^12.0",
2206:                 "vimeo/psalm": "^6.0"
2207:             },
2208:             "suggest": {
2209:                 "guzzlehttp/psr7": "^2.4",
2210:                 "psr/http-message": "^2.0"
2211:             },
2212:             "type": "library",
2213:             "autoload": {
2214:                 "psr-4": {
2215:                     "ZipStream\\": "src/"
2216:                 }
2217:             },
2218:             "notification-url": "https://packagist.org/downloads/",
2219:             "license": [
2220:                 "MIT"
2221:             ],
2222:             "authors": [
2223:                 {
2224:                     "name": "Paul Duncan",
2225:                     "email": "pabs@pablotron.org"
2226:                 },
2227:                 {
2228:                     "name": "Jonatan Männchen",
2229:                     "email": "jonatan@maennchen.ch"
2230:                 },
2231:                 {
2232:                     "name": "Jesse Donat",
2233:                     "email": "donatj@gmail.com"
2234:                 },
2235:                 {
2236:                     "name": "András Kolesár",
2237:                     "email": "kolesar@kolesar.hu"
2238:                 }
2239:             ],
2240:             "description": "ZipStream is a library for dynamically streaming dynamic zip files from PHP without writing to the disk at all on the server.",
2241:             "keywords": [
2242:                 "stream",
2243:                 "zip"
2244:             ],
2245:             "support": {
2246:                 "issues": "https://github.com/maennchen/ZipStream-PHP/issues",
2247:                 "source": "https://github.com/maennchen/ZipStream-PHP/tree/3.2.2"
2248:             },
2249:             "funding": [
2250:                 {
2251:                     "url": "https://github.com/maennchen",
2252:                     "type": "github"
2253:                 }
2254:             ],
2255:             "time": "2026-04-11T18:38:28+00:00"
2256:         },
2257:         {
2258:             "name": "monolog/monolog",
2259:             "version": "3.12.1",
2260:             "source": {
2261:                 "type": "git",
2262:                 "url": "https://github.com/Seldaek/monolog.git",
2263:                 "reference": "d7059e4c691d05ea1222b5124bed0f961be46e15"
2264:             },
2265:             "dist": {
2266:                 "type": "zip",
2267:                 "url": "https://api.github.com/repos/Seldaek/monolog/zipball/d7059e4c691d05ea1222b5124bed0f961be46e15",
2268:                 "reference": "d7059e4c691d05ea1222b5124bed0f961be46e15",
2269:                 "shasum": ""
2270:             },
2271:             "require": {
2272:                 "php": ">=8.1",
2273:                 "psr/log": "^2.0 || ^3.0"
2274:             },
2275:             "provide": {
2276:                 "psr/log-implementation": "3.0.0"
2277:             },
2278:             "require-dev": {
2279:                 "aws/aws-sdk-php": "^3.0",
2280:                 "doctrine/couchdb": "~1.0@dev",
2281:                 "elasticsearch/elasticsearch": "^7 || ^8",
2282:                 "ext-json": "*",
2283:                 "graylog2/gelf-php": "^1.4.2 || ^2.0",
2284:                 "guzzlehttp/guzzle": "^7.4.5",
2285:                 "guzzlehttp/psr7": "^2.2",
2286:                 "mongodb/mongodb": "^1.8 || ^2.0",
2287:                 "php-amqplib/php-amqplib": "~2.4 || ^3",
2288:                 "php-console/php-console": "^3.1.8",
2289:                 "phpstan/phpstan": "^2",
2290:                 "phpstan/phpstan-deprecation-rules": "^2",
2291:                 "phpstan/phpstan-strict-rules": "^2",
2292:                 "phpunit/phpunit": "^10.5.17 || ^11.0.7",
2293:                 "predis/predis": "^1.1 || ^2",
2294:                 "psr/clock": "^1.0",
2295:                 "rollbar/rollbar": "^4.0",
2296:                 "ruflin/elastica": "^7 || ^8",
2297:                 "symfony/mailer": "^5.4 || ^6",
2298:                 "symfony/mime": "^5.4 || ^6"
2299:             },
2300:             "suggest": {
2301:                 "aws/aws-sdk-php": "Allow sending log messages to AWS services like DynamoDB",
2302:                 "doctrine/couchdb": "Allow sending log messages to a CouchDB server",
2303:                 "elasticsearch/elasticsearch": "Allow sending log messages to an Elasticsearch server via official client",
2304:                 "ext-amqp": "Allow sending log messages to an AMQP server (1.0+ required)",
2305:                 "ext-curl": "Required to send log messages using the IFTTTHandler, the LogglyHandler, the SendGridHandler, the SlackWebhookHandler or the TelegramBotHandler",
2306:                 "ext-mbstring": "Allow to work properly with unicode symbols",
2307:                 "ext-mongodb": "Allow sending log messages to a MongoDB server (via driver)",
2308:                 "ext-openssl": "Required to send log messages using SSL",
2309:                 "ext-sockets": "Allow sending log messages to a Syslog server (via UDP driver)",
2310:                 "graylog2/gelf-php": "Allow sending log messages to a GrayLog2 server",
2311:                 "mongodb/mongodb": "Allow sending log messages to a MongoDB server (via library)",
2312:                 "php-amqplib/php-amqplib": "Allow sending log messages to an AMQP server using php-amqplib",
2313:                 "psr/clock": "Required to pass a clock to the Logger and control the timestamp of log records",
2314:                 "rollbar/rollbar": "Allow sending log messages to Rollbar",
2315:                 "ruflin/elastica": "Allow sending log messages to an Elastic Search server"
2316:             },
2317:             "type": "library",
2318:             "extra": {
2319:                 "branch-alias": {
2320:                     "dev-main": "3.x-dev"
2321:                 }
2322:             },
2323:             "autoload": {
2324:                 "psr-4": {
2325:                     "Monolog\\": "src/Monolog"
2326:                 }
2327:             },
2328:             "notification-url": "https://packagist.org/downloads/",
2329:             "license": [
2330:                 "MIT"
2331:             ],
2332:             "authors": [
2333:                 {
2334:                     "name": "Jordi Boggiano",
2335:                     "email": "j.boggiano@seld.be",
2336:                     "homepage": "https://seld.be"
2337:                 }
2338:             ],
2339:             "description": "Sends your logs to files, sockets, inboxes, databases and various web services",
2340:             "homepage": "https://github.com/Seldaek/monolog",
2341:             "keywords": [
2342:                 "log",
2343:                 "logging",
2344:                 "psr-3"
2345:             ],
2346:             "support": {
2347:                 "issues": "https://github.com/Seldaek/monolog/issues",
2348:                 "source": "https://github.com/Seldaek/monolog/tree/3.12.1"
2349:             },
2350:             "funding": [
2351:                 {
2352:                     "url": "https://github.com/Seldaek",
2353:                     "type": "github"
2354:                 },
2355:                 {
2356:                     "url": "https://tidelift.com/funding/github/packagist/monolog/monolog",
2357:                     "type": "tidelift"
2358:                 }
2359:             ],
2360:             "time": "2026-09-29T19:21:12+00:00"
2361:         },
2362:         {
2363:             "name": "nesbot/carbon",
2364:             "version": "3.14.2",
2365:             "source": {
2366:                 "type": "git",
2367:                 "url": "https://github.com/CarbonPHP/carbon.git",
2368:                 "reference": "94cfc34887f07ab5c0efa07914c8f527544e3224"
2369:             },
2370:             "dist": {
2371:                 "type": "zip",
2372:                 "url": "https://api.github.com/repos/CarbonPHP/carbon/zipball/94cfc34887f07ab5c0efa07914c8f527544e3224",
2373:                 "reference": "94cfc34887f07ab5c0efa07914c8f527544e3224",
2374:                 "shasum": ""
2375:             },
2376:             "require": {
2377:                 "carbonphp/carbon-doctrine-types": "<100.0",
2378:                 "ext-json": "*",
2379:                 "php": "^8.1",
2380:                 "psr/clock": "^1.0",
2381:                 "symfony/clock": "^6.3.12 || ^7.0 || ^8.0",
2382:                 "symfony/polyfill-mbstring": "^1.0",
2383:                 "symfony/translation": "^4.4.18 || ^5.2.1 || ^6.0 || ^7.0 || ^8.0"
2384:             },
2385:             "provide": {
2386:                 "psr/clock-implementation": "1.0"
2387:             },
2388:             "require-dev": {
2389:                 "doctrine/dbal": "^3.6.3 || ^4.0",
2390:                 "doctrine/orm": "^2.15.2 || ^3.0",
2391:                 "friendsofphp/php-cs-fixer": "^v3.87.1",
2392:                 "kylekatarnls/multi-tester": "^2.5.3",
2393:                 "phpmd/phpmd": "^3.0@dev",
2394:                 "phpstan/extension-installer": "^1.4.3",
2395:                 "phpstan/phpstan": "^2.1.22",
2396:                 "phpunit/phpunit": "^10.5.53",
2397:                 "squizlabs/php_codesniffer": "^3.13.4 || ^4.0.0"
2398:             },
2399:             "bin": [
2400:                 "bin/carbon"
2401:             ],
2402:             "type": "library",
2403:             "extra": {
2404:                 "laravel": {
2405:                     "providers": [
2406:                         "Carbon\\Laravel\\ServiceProvider"
2407:                     ]
2408:                 },
2409:                 "phpstan": {
2410:                     "includes": [
2411:                         "extension.neon"
2412:                     ]
2413:                 },
2414:                 "branch-alias": {
2415:                     "dev-2.x": "2.x-dev",
2416:                     "dev-master": "3.x-dev"
2417:                 }
2418:             },
2419:             "autoload": {
2420:                 "psr-4": {
2421:                     "Carbon\\": "src/Carbon/"
2422:                 }
2423:             },
2424:             "notification-url": "https://packagist.org/downloads/",
2425:             "license": [
2426:                 "MIT"
2427:             ],
2428:             "authors": [
2429:                 {
2430:                     "name": "Brian Nesbitt",
2431:                     "email": "brian@nesbot.com",
2432:                     "homepage": "https://markido.com"
2433:                 },
2434:                 {
2435:                     "name": "kylekatarnls",
2436:                     "homepage": "https://github.com/kylekatarnls"
2437:                 }
2438:             ],
2439:             "description": "An API extension for DateTime that supports 281 different languages.",
2440:             "homepage": "https://carbonphp.github.io/carbon/",
2441:             "keywords": [
2442:                 "date",
2443:                 "datetime",
2444:                 "time"
2445:             ],
2446:             "support": {
2447:                 "docs": "https://carbonphp.github.io/carbon/guide/getting-started/introduction.html",
2448:                 "issues": "https://github.com/CarbonPHP/carbon/issues",
2449:                 "source": "https://github.com/CarbonPHP/carbon"
2450:             },
2451:             "funding": [
2452:                 {
2453:                     "url": "https://github.com/sponsors/kylekatarnls",
2454:                     "type": "github"
2455:                 },
2456:                 {
2457:                     "url": "https://opencollective.com/Carbon#sponsor",
2458:                     "type": "opencollective"
2459:                 },
2460:                 {
2461:                     "url": "https://tidelift.com/subscription/pkg/packagist-nesbot-carbon?utm_source=packagist-nesbot-carbon&utm_medium=referral&utm_campaign=readme",
2462:                     "type": "tidelift"
2463:                 }
2464:             ],
2465:             "time": "2026-10-02T07:26:52+00:00"
2466:         },
2467:         {
2468:             "name": "nette/schema",
2469:             "version": "v1.3.6",
2470:             "source": {
2471:                 "type": "git",
2472:                 "url": "https://github.com/nette/schema.git",
2473:                 "reference": "c54350438cd6914616f790a49cb424605f421562"
2474:             },
2475:             "dist": {
2476:                 "type": "zip",
2477:                 "url": "https://api.github.com/repos/nette/schema/zipball/c54350438cd6914616f790a49cb424605f421562",
2478:                 "reference": "c54350438cd6914616f790a49cb424605f421562",
2479:                 "shasum": ""
2480:             },
2481:             "require": {
2482:                 "nette/utils": "^4.0",
2483:                 "php": "8.1 - 8.5"
2484:             },
2485:             "require-dev": {
2486:                 "nette/phpstan-rules": "^1.0",
2487:                 "nette/tester": "^2.6",
2488:                 "phpstan/extension-installer": "^1.4@stable",
2489:                 "phpstan/phpstan": "^2.1.39@stable",
2490:                 "tracy/tracy": "^2.8"
2491:             },
2492:             "type": "library",
2493:             "extra": {
2494:                 "branch-alias": {
2495:                     "dev-master": "1.3-dev"
2496:                 }
2497:             },
2498:             "autoload": {
2499:                 "psr-4": {
2500:                     "Nette\\": "src"
2501:                 },
2502:                 "classmap": [
2503:                     "src/"
2504:                 ]
2505:             },
2506:             "notification-url": "https://packagist.org/downloads/",
2507:             "license": [
2508:                 "BSD-3-Clause",
2509:                 "GPL-2.0-only",
2510:                 "GPL-3.0-only"
2511:             ],
2512:             "authors": [
2513:                 {
2514:                     "name": "David Grudl",
2515:                     "homepage": "https://davidgrudl.com"
2516:                 },
2517:                 {
2518:                     "name": "Nette Community",
2519:                     "homepage": "https://nette.org/contributors"
2520:                 }
2521:             ],
2522:             "description": "📐 Nette Schema: validating data structures against a given Schema.",
2523:             "homepage": "https://nette.org",
2524:             "keywords": [
2525:                 "config",
2526:                 "nette"
2527:             ],
2528:             "support": {
2529:                 "issues": "https://github.com/nette/schema/issues",
2530:                 "source": "https://github.com/nette/schema/tree/v1.3.6"
2531:             },
2532:             "time": "2026-08-16T21:58:41+00:00"
2533:         },
2534:         {
2535:             "name": "nette/utils",
2536:             "version": "v4.1.5",
2537:             "source": {
2538:                 "type": "git",
2539:                 "url": "https://github.com/nette/utils.git",
2540:                 "reference": "b043439dbdf954e6c28b5ea7e34b0100f83165e0"
2541:             },
2542:             "dist": {
2543:                 "type": "zip",
2544:                 "url": "https://api.github.com/repos/nette/utils/zipball/b043439dbdf954e6c28b5ea7e34b0100f83165e0",
2545:                 "reference": "b043439dbdf954e6c28b5ea7e34b0100f83165e0",
2546:                 "shasum": ""
2547:             },
2548:             "require": {
2549:                 "php": "8.2 - 8.5"
2550:             },
2551:             "conflict": {
2552:                 "nette/finder": "<3",
2553:                 "nette/schema": "<1.2.2"
2554:             },
2555:             "require-dev": {
2556:                 "jetbrains/phpstorm-attributes": "^1.2",
2557:                 "nette/phpstan-rules": "^1.0",
2558:                 "nette/tester": "^2.5",
2559:                 "phpstan/extension-installer": "^1.4@stable",
2560:                 "phpstan/phpstan": "^2.1@stable",
2561:                 "tracy/tracy": "^2.9"
2562:             },
2563:             "suggest": {
2564:                 "ext-gd": "to use Image",
2565:                 "ext-iconv": "to use Strings::chr(), ord() and reverse()",
2566:                 "ext-intl": "to use Strings::webalize(), toAscii(), normalize() and compare()",
2567:                 "ext-json": "to use Nette\\Utils\\Json",
2568:                 "ext-mbstring": "to use Strings::lower() etc...",
2569:                 "ext-tokenizer": "to use Nette\\Utils\\Reflection::getUseStatements()"
2570:             },
2571:             "type": "library",
2572:             "extra": {
2573:                 "branch-alias": {
2574:                     "dev-master": "4.1-dev"
2575:                 }
2576:             },
2577:             "autoload": {
2578:                 "psr-4": {
2579:                     "Nette\\": "src"
2580:                 },
2581:                 "classmap": [
2582:                     "src/"
2583:                 ]
2584:             },
2585:             "notification-url": "https://packagist.org/downloads/",
2586:             "license": [
2587:                 "BSD-3-Clause",
2588:                 "GPL-2.0-only",
2589:                 "GPL-3.0-only"
2590:             ],
2591:             "authors": [
2592:                 {
2593:                     "name": "David Grudl",
2594:                     "homepage": "https://davidgrudl.com"
2595:                 },
2596:                 {
2597:                     "name": "Nette Community",
2598:                     "homepage": "https://nette.org/contributors"
2599:                 }
2600:             ],
2601:             "description": "🛠  Nette Utils: lightweight utilities for string & array manipulation, image handling, safe JSON encoding/decoding, validation, slug or strong password generating etc.",
2602:             "homepage": "https://nette.org",
2603:             "keywords": [
2604:                 "array",
2605:                 "core",
2606:                 "datetime",
2607:                 "images",
2608:                 "json",
2609:                 "nette",
2610:                 "paginator",
2611:                 "password",
2612:                 "slugify",
2613:                 "string",
2614:                 "unicode",
2615:                 "utf-8",
2616:                 "utility",
2617:                 "validation"
2618:             ],
2619:             "support": {
2620:                 "issues": "https://github.com/nette/utils/issues",
2621:                 "source": "https://github.com/nette/utils/tree/v4.1.5"
2622:             },
2623:             "time": "2026-07-17T23:02:45+00:00"
2624:         },
2625:         {
2626:             "name": "nikic/php-parser",
2627:             "version": "v5.9.0",
2628:             "source": {
2629:                 "type": "git",
2630:                 "url": "https://github.com/nikic/PHP-Parser.git",
2631:                 "reference": "9e33da9553fe7786f0962b35f4e4ecf01be89def"
2632:             },
2633:             "dist": {
2634:                 "type": "zip",
2635:                 "url": "https://api.github.com/repos/nikic/PHP-Parser/zipball/9e33da9553fe7786f0962b35f4e4ecf01be89def",
2636:                 "reference": "9e33da9553fe7786f0962b35f4e4ecf01be89def",
2637:                 "shasum": ""
2638:             },
2639:             "require": {
2640:                 "ext-json": "*",
2641:                 "ext-tokenizer": "*",
2642:                 "php": ">=7.4"
2643:             },
2644:             "require-dev": {
2645:                 "ircmaxell/php-yacc": "^0.0.7",
2646:                 "phpunit/phpunit": "^9.0"
2647:             },
2648:             "bin": [
2649:                 "bin/php-parse"
2650:             ],
2651:             "type": "library",
2652:             "extra": {
2653:                 "branch-alias": {
2654:                     "dev-master": "5.x-dev"
2655:                 }
2656:             },
2657:             "autoload": {
2658:                 "psr-4": {
2659:                     "PhpParser\\": "lib/PhpParser"
2660:                 }
2661:             },
2662:             "notification-url": "https://packagist.org/downloads/",
2663:             "license": [
2664:                 "BSD-3-Clause"
2665:             ],
2666:             "authors": [
2667:                 {
2668:                     "name": "Nikita Popov"
2669:                 }
2670:             ],
2671:             "description": "A PHP parser written in PHP",
2672:             "keywords": [
2673:                 "parser",
2674:                 "php"
2675:             ],
2676:             "support": {
2677:                 "issues": "https://github.com/nikic/PHP-Parser/issues",
2678:                 "source": "https://github.com/nikic/PHP-Parser/tree/v5.9.0"
2679:             },
2680:             "time": "2026-09-13T18:51:52+00:00"
2681:         },
2682:         {
2683:             "name": "nunomaduro/termwind",
2684:             "version": "v2.4.0",
2685:             "source": {
2686:                 "type": "git",
2687:                 "url": "https://github.com/nunomaduro/termwind.git",
2688:                 "reference": "712a31b768f5daea284c2169a7d227031001b9a8"
2689:             },
2690:             "dist": {
2691:                 "type": "zip",
2692:                 "url": "https://api.github.com/repos/nunomaduro/termwind/zipball/712a31b768f5daea284c2169a7d227031001b9a8",
2693:                 "reference": "712a31b768f5daea284c2169a7d227031001b9a8",
2694:                 "shasum": ""
2695:             },
2696:             "require": {
2697:                 "ext-mbstring": "*",
2698:                 "php": "^8.2",
2699:                 "symfony/console": "^7.4.4 || ^8.0.4"
2700:             },
2701:             "require-dev": {
2702:                 "illuminate/console": "^11.47.0",
2703:                 "laravel/pint": "^1.27.1",
2704:                 "mockery/mockery": "^1.6.12",
2705:                 "pestphp/pest": "^2.36.0 || ^3.8.4 || ^4.3.2",
2706:                 "phpstan/phpstan": "^1.12.32",
2707:                 "phpstan/phpstan-strict-rules": "^1.6.2",
2708:                 "symfony/var-dumper": "^7.3.5 || ^8.0.4",
2709:                 "thecodingmachine/phpstan-strict-rules": "^1.0.0"
2710:             },
2711:             "type": "library",
2712:             "extra": {
2713:                 "laravel": {
2714:                     "providers": [
2715:                         "Termwind\\Laravel\\TermwindServiceProvider"
2716:                     ]
2717:                 },
2718:                 "branch-alias": {
2719:                     "dev-2.x": "2.x-dev"
2720:                 }
2721:             },
2722:             "autoload": {
2723:                 "files": [
2724:                     "src/Functions.php"
2725:                 ],
2726:                 "psr-4": {
2727:                     "Termwind\\": "src/"
2728:                 }
2729:             },
2730:             "notification-url": "https://packagist.org/downloads/",
2731:             "license": [
2732:                 "MIT"
2733:             ],
2734:             "authors": [
2735:                 {
2736:                     "name": "Nuno Maduro",
2737:                     "email": "enunomaduro@gmail.com"
2738:                 }
2739:             ],
2740:             "description": "It's like Tailwind CSS, but for the console.",
2741:             "keywords": [
2742:                 "cli",
2743:                 "console",
2744:                 "css",
2745:                 "package",
2746:                 "php",
2747:                 "style"
2748:             ],
2749:             "support": {
2750:                 "issues": "https://github.com/nunomaduro/termwind/issues",
2751:                 "source": "https://github.com/nunomaduro/termwind/tree/v2.4.0"
2752:             },
2753:             "funding": [
2754:                 {
2755:                     "url": "https://www.paypal.com/paypalme/enunomaduro",
2756:                     "type": "custom"
2757:                 },
2758:                 {
2759:                     "url": "https://github.com/nunomaduro",
2760:                     "type": "github"
2761:                 },
2762:                 {
2763:                     "url": "https://github.com/xiCO2k",
2764:                     "type": "github"
2765:                 }
2766:             ],
2767:             "time": "2026-02-16T23:10:27+00:00"
2768:         },
2769:         {
2770:             "name": "phpoption/phpoption",
2771:             "version": "1.10.0",
2772:             "source": {
2773:                 "type": "git",
2774:                 "url": "https://github.com/schmittjoh/php-option.git",
2775:                 "reference": "67b192b6a42ec03944b972d6e633ddec78ad2c6d"
2776:             },
2777:             "dist": {
2778:                 "type": "zip",
2779:                 "url": "https://api.github.com/repos/schmittjoh/php-option/zipball/67b192b6a42ec03944b972d6e633ddec78ad2c6d",
2780:                 "reference": "67b192b6a42ec03944b972d6e633ddec78ad2c6d",
2781:                 "shasum": ""
2782:             },
2783:             "require": {
2784:                 "php": "^7.2.5 || ^8.0"
2785:             },
2786:             "require-dev": {
2787:                 "bamarni/composer-bin-plugin": "^1.8.2",
2788:                 "phpunit/phpunit": "^8.5.54 || ^9.6.36 || ^10.5.64 || ^11.5.56 || ^12.5.33"
2789:             },
2790:             "type": "library",
2791:             "extra": {
2792:                 "bamarni-bin": {
2793:                     "bin-links": true,
2794:                     "forward-command": false
2795:                 },
2796:                 "branch-alias": {
2797:                     "dev-master": "1.9-dev"
2798:                 }
2799:             },
2800:             "autoload": {
2801:                 "psr-4": {
2802:                     "PhpOption\\": "src/PhpOption/"
2803:                 }
2804:             },
2805:             "notification-url": "https://packagist.org/downloads/",
2806:             "license": [
2807:                 "Apache-2.0"
2808:             ],
2809:             "authors": [
2810:                 {
2811:                     "name": "Johannes M. Schmitt",
2812:                     "email": "schmittjoh@gmail.com",
2813:                     "homepage": "https://github.com/schmittjoh"
2814:                 },
2815:                 {
2816:                     "name": "Graham Campbell",
2817:                     "email": "hello@gjcampbell.co.uk",
2818:                     "homepage": "https://github.com/GrahamCampbell"
2819:                 }
2820:             ],
2821:             "description": "Option Type for PHP",
2822:             "keywords": [
2823:                 "language",
2824:                 "option",
2825:                 "php",
2826:                 "type"
2827:             ],
2828:             "support": {
2829:                 "issues": "https://github.com/schmittjoh/php-option/issues",
2830:                 "source": "https://github.com/schmittjoh/php-option/tree/1.10.0"
2831:             },
2832:             "funding": [
2833:                 {
2834:                     "url": "https://github.com/GrahamCampbell",
2835:                     "type": "github"
2836:                 },
2837:                 {
2838:                     "url": "https://tidelift.com/funding/github/packagist/phpoption/phpoption",
2839:                     "type": "tidelift"
2840:                 }
2841:             ],
2842:             "time": "2026-08-24T00:54:40+00:00"
2843:         },
2844:         {
2845:             "name": "psr/clock",
2846:             "version": "1.0.0",
2847:             "source": {
2848:                 "type": "git",
2849:                 "url": "https://github.com/php-fig/clock.git",
2850:                 "reference": "e41a24703d4560fd0acb709162f73b8adfc3aa0d"
2851:             },
2852:             "dist": {
2853:                 "type": "zip",
2854:                 "url": "https://api.github.com/repos/php-fig/clock/zipball/e41a24703d4560fd0acb709162f73b8adfc3aa0d",
2855:                 "reference": "e41a24703d4560fd0acb709162f73b8adfc3aa0d",
2856:                 "shasum": ""
2857:             },
2858:             "require": {
2859:                 "php": "^7.0 || ^8.0"
2860:             },
2861:             "type": "library",
2862:             "autoload": {
2863:                 "psr-4": {
2864:                     "Psr\\Clock\\": "src/"
2865:                 }
2866:             },
2867:             "notification-url": "https://packagist.org/downloads/",
2868:             "license": [
2869:                 "MIT"
2870:             ],
2871:             "authors": [
2872:                 {
2873:                     "name": "PHP-FIG",
2874:                     "homepage": "https://www.php-fig.org/"
2875:                 }
2876:             ],
2877:             "description": "Common interface for reading the clock.",
2878:             "homepage": "https://github.com/php-fig/clock",
2879:             "keywords": [
2880:                 "clock",
2881:                 "now",
2882:                 "psr",
2883:                 "psr-20",
2884:                 "time"
2885:             ],
2886:             "support": {
2887:                 "issues": "https://github.com/php-fig/clock/issues",
2888:                 "source": "https://github.com/php-fig/clock/tree/1.0.0"
2889:             },
2890:             "time": "2022-11-25T14:36:26+00:00"
2891:         },
2892:         {
2893:             "name": "psr/container",
2894:             "version": "2.0.2",
2895:             "source": {
2896:                 "type": "git",
2897:                 "url": "https://github.com/php-fig/container.git",
2898:                 "reference": "c71ecc56dfe541dbd90c5360474fbc405f8d5963"
2899:             },
2900:             "dist": {
2901:                 "type": "zip",
2902:                 "url": "https://api.github.com/repos/php-fig/container/zipball/c71ecc56dfe541dbd90c5360474fbc405f8d5963",
2903:                 "reference": "c71ecc56dfe541dbd90c5360474fbc405f8d5963",
2904:                 "shasum": ""
2905:             },
2906:             "require": {
2907:                 "php": ">=7.4.0"
2908:             },
2909:             "type": "library",
2910:             "extra": {
2911:                 "branch-alias": {
2912:                     "dev-master": "2.0.x-dev"
2913:                 }
2914:             },
2915:             "autoload": {
2916:                 "psr-4": {
2917:                     "Psr\\Container\\": "src/"
2918:                 }
2919:             },
2920:             "notification-url": "https://packagist.org/downloads/",
2921:             "license": [
2922:                 "MIT"
2923:             ],
2924:             "authors": [
2925:                 {
2926:                     "name": "PHP-FIG",
2927:                     "homepage": "https://www.php-fig.org/"
2928:                 }
2929:             ],
2930:             "description": "Common Container Interface (PHP FIG PSR-11)",
2931:             "homepage": "https://github.com/php-fig/container",
2932:             "keywords": [
2933:                 "PSR-11",
2934:                 "container",
2935:                 "container-interface",
2936:                 "container-interop",
2937:                 "psr"
2938:             ],
2939:             "support": {
2940:                 "issues": "https://github.com/php-fig/container/issues",
2941:                 "source": "https://github.com/php-fig/container/tree/2.0.2"
2942:             },
2943:             "time": "2021-11-05T16:47:00+00:00"
2944:         },
2945:         {
2946:             "name": "psr/event-dispatcher",
2947:             "version": "1.0.0",
2948:             "source": {
2949:                 "type": "git",
2950:                 "url": "https://github.com/php-fig/event-dispatcher.git",
2951:                 "reference": "dbefd12671e8a14ec7f180cab83036ed26714bb0"
2952:             },
2953:             "dist": {
2954:                 "type": "zip",
2955:                 "url": "https://api.github.com/repos/php-fig/event-dispatcher/zipball/dbefd12671e8a14ec7f180cab83036ed26714bb0",
2956:                 "reference": "dbefd12671e8a14ec7f180cab83036ed26714bb0",
2957:                 "shasum": ""
2958:             },
2959:             "require": {
2960:                 "php": ">=7.2.0"
2961:             },
2962:             "type": "library",
2963:             "extra": {
2964:                 "branch-alias": {
2965:                     "dev-master": "1.0.x-dev"
2966:                 }
2967:             },
2968:             "autoload": {
2969:                 "psr-4": {
2970:                     "Psr\\EventDispatcher\\": "src/"
2971:                 }
2972:             },
2973:             "notification-url": "https://packagist.org/downloads/",
2974:             "license": [
2975:                 "MIT"
2976:             ],
2977:             "authors": [
2978:                 {
2979:                     "name": "PHP-FIG",
2980:                     "homepage": "http://www.php-fig.org/"
2981:                 }
2982:             ],
2983:             "description": "Standard interfaces for event handling.",
2984:             "keywords": [
2985:                 "events",
2986:                 "psr",
2987:                 "psr-14"
2988:             ],
2989:             "support": {
2990:                 "issues": "https://github.com/php-fig/event-dispatcher/issues",
2991:                 "source": "https://github.com/php-fig/event-dispatcher/tree/1.0.0"
2992:             },
2993:             "time": "2019-01-08T18:20:26+00:00"
2994:         },
2995:         {
2996:             "name": "psr/http-client",
2997:             "version": "1.0.3",
2998:             "source": {
2999:                 "type": "git",
3000:                 "url": "https://github.com/php-fig/http-client.git",
3001:                 "reference": "bb5906edc1c324c9a05aa0873d40117941e5fa90"
3002:             },
3003:             "dist": {
3004:                 "type": "zip",
3005:                 "url": "https://api.github.com/repos/php-fig/http-client/zipball/bb5906edc1c324c9a05aa0873d40117941e5fa90",
3006:                 "reference": "bb5906edc1c324c9a05aa0873d40117941e5fa90",
3007:                 "shasum": ""
3008:             },
3009:             "require": {
3010:                 "php": "^7.0 || ^8.0",
3011:                 "psr/http-message": "^1.0 || ^2.0"
3012:             },
3013:             "type": "library",
3014:             "extra": {
3015:                 "branch-alias": {
3016:                     "dev-master": "1.0.x-dev"
3017:                 }
3018:             },
3019:             "autoload": {
3020:                 "psr-4": {
3021:                     "Psr\\Http\\Client\\": "src/"
3022:                 }
3023:             },
3024:             "notification-url": "https://packagist.org/downloads/",
3025:             "license": [
3026:                 "MIT"
3027:             ],
3028:             "authors": [
3029:                 {
3030:                     "name": "PHP-FIG",
3031:                     "homepage": "https://www.php-fig.org/"
3032:                 }
3033:             ],
3034:             "description": "Common interface for HTTP clients",
3035:             "homepage": "https://github.com/php-fig/http-client",
3036:             "keywords": [
3037:                 "http",
3038:                 "http-client",
3039:                 "psr",
3040:                 "psr-18"
3041:             ],
3042:             "support": {
3043:                 "source": "https://github.com/php-fig/http-client"
3044:             },
3045:             "time": "2023-09-23T14:17:50+00:00"
3046:         },
3047:         {
3048:             "name": "psr/http-factory",
3049:             "version": "1.1.0",
3050:             "source": {
3051:                 "type": "git",
3052:                 "url": "https://github.com/php-fig/http-factory.git",
3053:                 "reference": "2b4765fddfe3b508ac62f829e852b1501d3f6e8a"
3054:             },
3055:             "dist": {
3056:                 "type": "zip",
3057:                 "url": "https://api.github.com/repos/php-fig/http-factory/zipball/2b4765fddfe3b508ac62f829e852b1501d3f6e8a",
3058:                 "reference": "2b4765fddfe3b508ac62f829e852b1501d3f6e8a",
3059:                 "shasum": ""
3060:             },
3061:             "require": {
3062:                 "php": ">=7.1",
3063:                 "psr/http-message": "^1.0 || ^2.0"
3064:             },
3065:             "type": "library",
3066:             "extra": {
3067:                 "branch-alias": {
3068:                     "dev-master": "1.0.x-dev"
3069:                 }
3070:             },
3071:             "autoload": {
3072:                 "psr-4": {
3073:                     "Psr\\Http\\Message\\": "src/"
3074:                 }
3075:             },
3076:             "notification-url": "https://packagist.org/downloads/",
3077:             "license": [
3078:                 "MIT"
3079:             ],
3080:             "authors": [
3081:                 {
3082:                     "name": "PHP-FIG",
3083:                     "homepage": "https://www.php-fig.org/"
3084:                 }
3085:             ],
3086:             "description": "PSR-17: Common interfaces for PSR-7 HTTP message factories",
3087:             "keywords": [
3088:                 "factory",
3089:                 "http",
3090:                 "message",
3091:                 "psr",
3092:                 "psr-17",
3093:                 "psr-7",
3094:                 "request",
3095:                 "response"
3096:             ],
3097:             "support": {
3098:                 "source": "https://github.com/php-fig/http-factory"
3099:             },
3100:             "time": "2024-04-15T12:06:14+00:00"
3101:         },
3102:         {
3103:             "name": "psr/http-message",
3104:             "version": "2.0",
3105:             "source": {
3106:                 "type": "git",
3107:                 "url": "https://github.com/php-fig/http-message.git",
3108:                 "reference": "402d35bcb92c70c026d1a6a9883f06b2ead23d71"
3109:             },
3110:             "dist": {
3111:                 "type": "zip",
3112:                 "url": "https://api.github.com/repos/php-fig/http-message/zipball/402d35bcb92c70c026d1a6a9883f06b2ead23d71",
3113:                 "reference": "402d35bcb92c70c026d1a6a9883f06b2ead23d71",
3114:                 "shasum": ""
3115:             },
3116:             "require": {
3117:                 "php": "^7.2 || ^8.0"
3118:             },
3119:             "type": "library",
3120:             "extra": {
3121:                 "branch-alias": {
3122:                     "dev-master": "2.0.x-dev"
3123:                 }
3124:             },
3125:             "autoload": {
3126:                 "psr-4": {
3127:                     "Psr\\Http\\Message\\": "src/"
3128:                 }
3129:             },
3130:             "notification-url": "https://packagist.org/downloads/",
3131:             "license": [
3132:                 "MIT"
3133:             ],
3134:             "authors": [
3135:                 {
3136:                     "name": "PHP-FIG",
3137:                     "homepage": "https://www.php-fig.org/"
3138:                 }
3139:             ],
3140:             "description": "Common interface for HTTP messages",
3141:             "homepage": "https://github.com/php-fig/http-message",
3142:             "keywords": [
3143:                 "http",
3144:                 "http-message",
3145:                 "psr",
3146:                 "psr-7",
3147:                 "request",
3148:                 "response"
3149:             ],
3150:             "support": {
3151:                 "source": "https://github.com/php-fig/http-message/tree/2.0"
3152:             },
3153:             "time": "2023-04-04T09:54:51+00:00"
3154:         },
3155:         {
3156:             "name": "psr/log",
3157:             "version": "3.0.2",
3158:             "source": {
3159:                 "type": "git",
3160:                 "url": "https://github.com/php-fig/log.git",
3161:                 "reference": "f16e1d5863e37f8d8c2a01719f5b34baa2b714d3"
3162:             },
3163:             "dist": {
3164:                 "type": "zip",
3165:                 "url": "https://api.github.com/repos/php-fig/log/zipball/f16e1d5863e37f8d8c2a01719f5b34baa2b714d3",
3166:                 "reference": "f16e1d5863e37f8d8c2a01719f5b34baa2b714d3",
3167:                 "shasum": ""
3168:             },
3169:             "require": {
3170:                 "php": ">=8.0.0"
3171:             },
3172:             "type": "library",
3173:             "extra": {
3174:                 "branch-alias": {
3175:                     "dev-master": "3.x-dev"
3176:                 }
3177:             },
3178:             "autoload": {
3179:                 "psr-4": {
3180:                     "Psr\\Log\\": "src"
3181:                 }
3182:             },
3183:             "notification-url": "https://packagist.org/downloads/",
3184:             "license": [
3185:                 "MIT"
3186:             ],
3187:             "authors": [
3188:                 {
3189:                     "name": "PHP-FIG",
3190:                     "homepage": "https://www.php-fig.org/"
3191:                 }
3192:             ],
3193:             "description": "Common interface for logging libraries",
3194:             "homepage": "https://github.com/php-fig/log",
3195:             "keywords": [
3196:                 "log",
3197:                 "psr",
3198:                 "psr-3"
3199:             ],
3200:             "support": {
3201:                 "source": "https://github.com/php-fig/log/tree/3.0.2"
3202:             },
3203:             "time": "2024-09-11T13:17:53+00:00"
3204:         },
3205:         {
3206:             "name": "psr/simple-cache",
3207:             "version": "3.0.0",
3208:             "source": {
3209:                 "type": "git",
3210:                 "url": "https://github.com/php-fig/simple-cache.git",
3211:                 "reference": "764e0b3939f5ca87cb904f570ef9be2d78a07865"
3212:             },
3213:             "dist": {
3214:                 "type": "zip",
3215:                 "url": "https://api.github.com/repos/php-fig/simple-cache/zipball/764e0b3939f5ca87cb904f570ef9be2d78a07865",
3216:                 "reference": "764e0b3939f5ca87cb904f570ef9be2d78a07865",
3217:                 "shasum": ""
3218:             },
3219:             "require": {
3220:                 "php": ">=8.0.0"
3221:             },
3222:             "type": "library",
3223:             "extra": {
3224:                 "branch-alias": {
3225:                     "dev-master": "3.0.x-dev"
3226:                 }
3227:             },
3228:             "autoload": {
3229:                 "psr-4": {
3230:                     "Psr\\SimpleCache\\": "src/"
3231:                 }
3232:             },
3233:             "notification-url": "https://packagist.org/downloads/",
3234:             "license": [
3235:                 "MIT"
3236:             ],
3237:             "authors": [
3238:                 {
3239:                     "name": "PHP-FIG",
3240:                     "homepage": "https://www.php-fig.org/"
3241:                 }
3242:             ],
3243:             "description": "Common interfaces for simple caching",
3244:             "keywords": [
3245:                 "cache",
3246:                 "caching",
3247:                 "psr",
3248:                 "psr-16",
3249:                 "simple-cache"
3250:             ],
3251:             "support": {
3252:                 "source": "https://github.com/php-fig/simple-cache/tree/3.0.0"
3253:             },
3254:             "time": "2021-10-29T13:26:27+00:00"
3255:         },
3256:         {
3257:             "name": "psy/psysh",
3258:             "version": "v0.12.24",
3259:             "source": {
3260:                 "type": "git",
3261:                 "url": "https://github.com/bobthecow/psysh.git",
3262:                 "reference": "ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1"
3263:             },
3264:             "dist": {
3265:                 "type": "zip",
3266:                 "url": "https://api.github.com/repos/bobthecow/psysh/zipball/ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1",
3267:                 "reference": "ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1",
3268:                 "shasum": ""
3269:             },
3270:             "require": {
3271:                 "ext-json": "*",
3272:                 "ext-tokenizer": "*",
3273:                 "nikic/php-parser": "^5.0 || ^4.0",
3274:                 "php": "^8.0 || ^7.4",
3275:                 "symfony/console": "^8.0 || ^7.0 || ^6.0 || ^5.0 || ^4.0 || ^3.4",
3276:                 "symfony/var-dumper": "^8.0 || ^7.0 || ^6.0 || ^5.0 || ^4.0 || ^3.4"
3277:             },
3278:             "conflict": {
3279:                 "symfony/console": "4.4.37 || 5.3.14 || 5.3.15 || 5.4.3 || 5.4.4 || 6.0.3 || 6.0.4"
3280:             },
3281:             "require-dev": {
3282:                 "bamarni/composer-bin-plugin": "^1.2",
3283:                 "composer/class-map-generator": "^1.6"
3284:             },
3285:             "suggest": {
3286:                 "composer/class-map-generator": "Improved tab completion performance with better class discovery.",
3287:                 "ext-pcntl": "Enabling the PCNTL extension makes PsySH a lot happier :)",
3288:                 "ext-posix": "If you have PCNTL, you'll want the POSIX extension as well."
3289:             },
3290:             "bin": [
3291:                 "bin/psysh"
3292:             ],
3293:             "type": "library",
3294:             "extra": {
3295:                 "bamarni-bin": {
3296:                     "bin-links": false,
3297:                     "forward-command": false
3298:                 },
3299:                 "branch-alias": {
3300:                     "dev-main": "0.12.x-dev"
3301:                 }
3302:             },
3303:             "autoload": {
3304:                 "files": [
3305:                     "src/functions.php"
3306:                 ],
3307:                 "psr-4": {
3308:                     "Psy\\": "src/"
3309:                 }
3310:             },
3311:             "notification-url": "https://packagist.org/downloads/",
3312:             "license": [
3313:                 "MIT"
3314:             ],
3315:             "authors": [
3316:                 {
3317:                     "name": "Justin Hileman",
3318:                     "email": "justin@justinhileman.info"
3319:                 }
3320:             ],
3321:             "description": "An interactive shell for modern PHP.",
3322:             "homepage": "https://psysh.org",
3323:             "keywords": [
3324:                 "REPL",
3325:                 "console",
3326:                 "interactive",
3327:                 "shell"
3328:             ],
3329:             "support": {
3330:                 "issues": "https://github.com/bobthecow/psysh/issues",
3331:                 "source": "https://github.com/bobthecow/psysh/tree/v0.12.24"
3332:             },
3333:             "time": "2026-06-29T15:41:09+00:00"
3334:         },
3335:         {
3336:             "name": "ramsey/collection",
3337:             "version": "2.1.1",
3338:             "source": {
3339:                 "type": "git",
3340:                 "url": "https://github.com/ramsey/collection.git",
3341:                 "reference": "344572933ad0181accbf4ba763e85a0306a8c5e2"
3342:             },
3343:             "dist": {
3344:                 "type": "zip",
3345:                 "url": "https://api.github.com/repos/ramsey/collection/zipball/344572933ad0181accbf4ba763e85a0306a8c5e2",
3346:                 "reference": "344572933ad0181accbf4ba763e85a0306a8c5e2",
3347:                 "shasum": ""
3348:             },
3349:             "require": {
3350:                 "php": "^8.1"
3351:             },
3352:             "require-dev": {
3353:                 "captainhook/plugin-composer": "^5.3",
3354:                 "ergebnis/composer-normalize": "^2.45",
3355:                 "fakerphp/faker": "^1.24",
3356:                 "hamcrest/hamcrest-php": "^2.0",
3357:                 "jangregor/phpstan-prophecy": "^2.1",
3358:                 "mockery/mockery": "^1.6",
3359:                 "php-parallel-lint/php-console-highlighter": "^1.0",
3360:                 "php-parallel-lint/php-parallel-lint": "^1.4",
3361:                 "phpspec/prophecy-phpunit": "^2.3",
3362:                 "phpstan/extension-installer": "^1.4",
3363:                 "phpstan/phpstan": "^2.1",
3364:                 "phpstan/phpstan-mockery": "^2.0",
3365:                 "phpstan/phpstan-phpunit": "^2.0",
3366:                 "phpunit/phpunit": "^10.5",
3367:                 "ramsey/coding-standard": "^2.3",
3368:                 "ramsey/conventional-commits": "^1.6",
3369:                 "roave/security-advisories": "dev-latest"
3370:             },
3371:             "type": "library",
3372:             "extra": {
3373:                 "captainhook": {
3374:                     "force-install": true
3375:                 },
3376:                 "ramsey/conventional-commits": {
3377:                     "configFile": "conventional-commits.json"
3378:                 }
3379:             },
3380:             "autoload": {
3381:                 "psr-4": {
3382:                     "Ramsey\\Collection\\": "src/"
3383:                 }
3384:             },
3385:             "notification-url": "https://packagist.org/downloads/",
3386:             "license": [
3387:                 "MIT"
3388:             ],
3389:             "authors": [
3390:                 {
3391:                     "name": "Ben Ramsey",
3392:                     "email": "ben@benramsey.com",
3393:                     "homepage": "https://benramsey.com"
3394:                 }
3395:             ],
3396:             "description": "A PHP library for representing and manipulating collections.",
3397:             "keywords": [
3398:                 "array",
3399:                 "collection",
3400:                 "hash",
3401:                 "map",
3402:                 "queue",
3403:                 "set"
3404:             ],
3405:             "support": {
3406:                 "issues": "https://github.com/ramsey/collection/issues",
3407:                 "source": "https://github.com/ramsey/collection/tree/2.1.1"
3408:             },
3409:             "time": "2025-03-22T05:38:12+00:00"
3410:         },
3411:         {
3412:             "name": "ramsey/uuid",
3413:             "version": "4.9.4",
3414:             "source": {
3415:                 "type": "git",
3416:                 "url": "https://github.com/ramsey/uuid.git",
3417:                 "reference": "75d73f48d02797c2c285a7e9f348fadc0102ffe2"
3418:             },
3419:             "dist": {
3420:                 "type": "zip",
3421:                 "url": "https://api.github.com/repos/ramsey/uuid/zipball/75d73f48d02797c2c285a7e9f348fadc0102ffe2",
3422:                 "reference": "75d73f48d02797c2c285a7e9f348fadc0102ffe2",
3423:                 "shasum": ""
3424:             },
3425:             "require": {
3426:                 "brick/math": "^0.8.16 || ^0.9 || ^0.10 || ^0.11 || ^0.12 || ^0.13 || ^0.14 || ^0.15 || ^0.16 || ^0.17 || ^0.18 || ^0.19 || ^0.20 || ^1.0",
3427:                 "php": "^8.0",
3428:                 "ramsey/collection": "^1.2 || ^2.0"
3429:             },
3430:             "replace": {
3431:                 "rhumsaa/uuid": "self.version"
3432:             },
3433:             "require-dev": {
3434:                 "captainhook/captainhook": "^5.25",
3435:                 "captainhook/plugin-composer": "^5.3",
3436:                 "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
3437:                 "ergebnis/composer-normalize": "^2.47",
3438:                 "mockery/mockery": "^1.6",
3439:                 "paragonie/random-lib": "^2",
3440:                 "php-mock/php-mock": "^2.6",
3441:                 "php-mock/php-mock-mockery": "^1.5",
3442:                 "php-parallel-lint/php-parallel-lint": "^1.4.0",
3443:                 "phpbench/phpbench": "^1.2.14",
3444:                 "phpstan/extension-installer": "^1.4",
3445:                 "phpstan/phpstan": "^2.1",
3446:                 "phpstan/phpstan-mockery": "^2.0",
3447:                 "phpstan/phpstan-phpunit": "^2.0",
3448:                 "phpunit/phpunit": "^9.6",
3449:                 "slevomat/coding-standard": "^8.18",
3450:                 "squizlabs/php_codesniffer": "^3.13"
3451:             },
3452:             "suggest": {
3453:                 "ext-bcmath": "Enables faster math with arbitrary-precision integers using BCMath.",
3454:                 "ext-gmp": "Enables faster math with arbitrary-precision integers using GMP.",
3455:                 "ext-uuid": "Enables the use of PeclUuidTimeGenerator and PeclUuidRandomGenerator.",
3456:                 "paragonie/random-lib": "Provides RandomLib for use with the RandomLibAdapter",
3457:                 "ramsey/uuid-doctrine": "Allows the use of Ramsey\\Uuid\\Uuid as Doctrine field type."
3458:             },
3459:             "type": "library",
3460:             "extra": {
3461:                 "captainhook": {
3462:                     "force-install": true
3463:                 }
3464:             },
3465:             "autoload": {
3466:                 "files": [
3467:                     "src/functions.php"
3468:                 ],
3469:                 "psr-4": {
3470:                     "Ramsey\\Uuid\\": "src/"
3471:                 }
3472:             },
3473:             "notification-url": "https://packagist.org/downloads/",
3474:             "license": [
3475:                 "MIT"
3476:             ],
3477:             "description": "A PHP library for generating and working with universally unique identifiers (UUIDs).",
3478:             "keywords": [
3479:                 "guid",
3480:                 "identifier",
3481:                 "uuid"
3482:             ],
3483:             "support": {
3484:                 "issues": "https://github.com/ramsey/uuid/issues",
3485:                 "source": "https://github.com/ramsey/uuid/tree/4.9.4"
3486:             },
3487:             "time": "2026-09-16T11:39:30+00:00"
3488:         },
3489:         {
3490:             "name": "spatie/image",
3491:             "version": "3.9.7",
3492:             "source": {
3493:                 "type": "git",
3494:                 "url": "https://github.com/spatie/image.git",
3495:                 "reference": "5450a2f63f315d56453fbbf58f5dacaecbf48dd4"
3496:             },
3497:             "dist": {
3498:                 "type": "zip",
3499:                 "url": "https://api.github.com/repos/spatie/image/zipball/5450a2f63f315d56453fbbf58f5dacaecbf48dd4",
3500:                 "reference": "5450a2f63f315d56453fbbf58f5dacaecbf48dd4",
3501:                 "shasum": ""
3502:             },
3503:             "require": {
3504:                 "ext-exif": "*",
3505:                 "ext-json": "*",
3506:                 "ext-mbstring": "*",
3507:                 "php": "^8.2",
3508:                 "spatie/image-optimizer": "^1.7.5",
3509:                 "spatie/temporary-directory": "^2.2",
3510:                 "symfony/process": "^6.4|^7.0|^8.0"
3511:             },
3512:             "require-dev": {
3513:                 "ext-ffi": "*",
3514:                 "ext-gd": "*",
3515:                 "ext-imagick": "*",
3516:                 "jcupitt/vips": "^2.4",
3517:                 "laravel/sail": "^1.34",
3518:                 "pestphp/pest": "^3.0|^4.0",
3519:                 "phpstan/phpstan": "^1.10.50",
3520:                 "spatie/pest-plugin-snapshots": "^2.1",
3521:                 "spatie/pixelmatch-php": "^1.0",
3522:                 "spatie/ray": "^1.40.1",
3523:                 "symfony/var-dumper": "^6.4|^7.0|^8.0"
3524:             },
3525:             "type": "library",
3526:             "autoload": {
3527:                 "psr-4": {
3528:                     "Spatie\\Image\\": "src"
3529:                 }
3530:             },
3531:             "notification-url": "https://packagist.org/downloads/",
3532:             "license": [
3533:                 "MIT"
3534:             ],
3535:             "authors": [
3536:                 {
3537:                     "name": "Freek Van der Herten",
3538:                     "email": "freek@spatie.be",
3539:                     "homepage": "https://spatie.be",
3540:                     "role": "Developer"
3541:                 }
3542:             ],
3543:             "description": "Manipulate images with an expressive API",
3544:             "homepage": "https://github.com/spatie/image",
3545:             "keywords": [
3546:                 "image",
3547:                 "spatie"
3548:             ],
3549:             "support": {
3550:                 "source": "https://github.com/spatie/image/tree/3.9.7"
3551:             },
3552:             "funding": [
3553:                 {
3554:                     "url": "https://spatie.be/open-source/support-us",
3555:                     "type": "custom"
3556:                 },
3557:                 {
3558:                     "url": "https://github.com/spatie",
3559:                     "type": "github"
3560:                 }
3561:             ],
3562:             "time": "2026-09-30T07:06:27+00:00"
3563:         },
3564:         {
3565:             "name": "spatie/image-optimizer",
3566:             "version": "1.10.0",
3567:             "source": {
3568:                 "type": "git",
3569:                 "url": "https://github.com/spatie/image-optimizer.git",
3570:                 "reference": "333c03952289dc2df0a91874636a0dffeb5b6aec"
3571:             },
3572:             "dist": {
3573:                 "type": "zip",
3574:                 "url": "https://api.github.com/repos/spatie/image-optimizer/zipball/333c03952289dc2df0a91874636a0dffeb5b6aec",
3575:                 "reference": "333c03952289dc2df0a91874636a0dffeb5b6aec",
3576:                 "shasum": ""
3577:             },
3578:             "require": {
3579:                 "ext-fileinfo": "*",
3580:                 "php": "^7.4|^8.0",
3581:                 "psr/log": "^1.0 | ^2.0 | ^3.0",
3582:                 "symfony/process": "^4.2|^5.0|^6.0|^7.0|^8.0"
3583:             },
3584:             "require-dev": {
3585:                 "pestphp/pest": "^1.21|^2.0|^3.0|^4.0",
3586:                 "phpunit/phpunit": "^8.5.21|^9.4.4|^10.0|^11.0|^12.0",
3587:                 "symfony/var-dumper": "^4.2|^5.0|^6.0|^7.0|^8.0"
3588:             },
3589:             "type": "library",
3590:             "autoload": {
3591:                 "psr-4": {
3592:                     "Spatie\\ImageOptimizer\\": "src"
3593:                 }
3594:             },
3595:             "notification-url": "https://packagist.org/downloads/",
3596:             "license": [
3597:                 "MIT"
3598:             ],
3599:             "authors": [
3600:                 {
3601:                     "name": "Freek Van der Herten",
3602:                     "email": "freek@spatie.be",
3603:                     "homepage": "https://spatie.be",
3604:                     "role": "Developer"
3605:                 }
3606:             ],
3607:             "description": "Easily optimize images using PHP",
3608:             "homepage": "https://github.com/spatie/image-optimizer",
3609:             "keywords": [
3610:                 "image-optimizer",
3611:                 "spatie"
3612:             ],
3613:             "support": {
3614:                 "issues": "https://github.com/spatie/image-optimizer/issues",
3615:                 "source": "https://github.com/spatie/image-optimizer/tree/1.10.0"
3616:             },
3617:             "time": "2026-06-29T08:28:30+00:00"
3618:         },
3619:         {
3620:             "name": "spatie/laravel-medialibrary",
3621:             "version": "11.23.9",
3622:             "source": {
3623:                 "type": "git",
3624:                 "url": "https://github.com/spatie/laravel-medialibrary.git",
3625:                 "reference": "afe6e389994ada7f33b731a837ea79301a7ec6c1"
3626:             },
3627:             "dist": {
3628:                 "type": "zip",
3629:                 "url": "https://api.github.com/repos/spatie/laravel-medialibrary/zipball/afe6e389994ada7f33b731a837ea79301a7ec6c1",
3630:                 "reference": "afe6e389994ada7f33b731a837ea79301a7ec6c1",
3631:                 "shasum": ""
3632:             },
3633:             "require": {
3634:                 "composer/semver": "^3.4",
3635:                 "ext-exif": "*",
3636:                 "ext-fileinfo": "*",
3637:                 "ext-json": "*",
3638:                 "illuminate/bus": "^10.2|^11.0|^12.0|^13.0",
3639:                 "illuminate/conditionable": "^10.2|^11.0|^12.0|^13.0",
3640:                 "illuminate/console": "^10.2|^11.0|^12.0|^13.0",
3641:                 "illuminate/database": "^10.2|^11.0|^12.0|^13.0",
3642:                 "illuminate/pipeline": "^10.2|^11.0|^12.0|^13.0",
3643:                 "illuminate/support": "^10.2|^11.0|^12.0|^13.0",
3644:                 "maennchen/zipstream-php": "^3.1",
3645:                 "php": "^8.2",
3646:                 "spatie/image": "^3.3.2",
3647:                 "spatie/laravel-package-tools": "^1.16.1",
3648:                 "spatie/temporary-directory": "^2.2",
3649:                 "symfony/console": "^6.4.1|^7.0|^8.0"
3650:             },
3651:             "conflict": {
3652:                 "php-ffmpeg/php-ffmpeg": "<0.6.1"
3653:             },
3654:             "require-dev": {
3655:                 "aws/aws-sdk-php": "^3.293.10",
3656:                 "ext-imagick": "*",
3657:                 "ext-pdo_sqlite": "*",
3658:                 "ext-zip": "*",
3659:                 "guzzlehttp/guzzle": "^7.8.1",
3660:                 "larastan/larastan": "^2.7|^3.0",
3661:                 "league/flysystem-aws-s3-v3": "^3.22",
3662:                 "mockery/mockery": "^1.6.7",
3663:                 "orchestra/testbench": "^8.36|^9.15|^10.8|^11.0",
3664:                 "pestphp/pest": "^2.36|^3.0|^4.0",
3665:                 "phpstan/extension-installer": "^1.3.1",
3666:                 "spatie/laravel-ray": "^1.33",
3667:                 "spatie/pdf-to-image": "^2.2|^3.0",
3668:                 "spatie/pest-expectations": "^1.13",
3669:                 "spatie/pest-plugin-snapshots": "^2.1"
3670:             },
3671:             "suggest": {
3672:                 "league/flysystem-aws-s3-v3": "Required to use AWS S3 file storage",
3673:                 "php-ffmpeg/php-ffmpeg": "Required for generating video thumbnails",
3674:                 "spatie/pdf-to-image": "Required for generating thumbnails of PDFs and SVGs"
3675:             },
3676:             "type": "library",
3677:             "extra": {
3678:                 "laravel": {
3679:                     "providers": [
3680:                         "Spatie\\MediaLibrary\\MediaLibraryServiceProvider"
3681:                     ]
3682:                 }
3683:             },
3684:             "autoload": {
3685:                 "psr-4": {
3686:                     "Spatie\\MediaLibrary\\": "src"
3687:                 }
3688:             },
3689:             "notification-url": "https://packagist.org/downloads/",
3690:             "license": [
3691:                 "MIT"
3692:             ],
3693:             "authors": [
3694:                 {
3695:                     "name": "Freek Van der Herten",
3696:                     "email": "freek@spatie.be",
3697:                     "homepage": "https://spatie.be",
3698:                     "role": "Developer"
3699:                 }
3700:             ],
3701:             "description": "Associate files with Eloquent models",
3702:             "homepage": "https://github.com/spatie/laravel-medialibrary",
3703:             "keywords": [
3704:                 "cms",
3705:                 "conversion",
3706:                 "downloads",
3707:                 "images",
3708:                 "laravel",
3709:                 "laravel-medialibrary",
3710:                 "media",
3711:                 "spatie"
3712:             ],
3713:             "support": {
3714:                 "issues": "https://github.com/spatie/laravel-medialibrary/issues",
3715:                 "source": "https://github.com/spatie/laravel-medialibrary/tree/11.23.9"
3716:             },
3717:             "funding": [
3718:                 {
3719:                     "url": "https://spatie.be/open-source/support-us",
3720:                     "type": "custom"
3721:                 },
3722:                 {
3723:                     "url": "https://github.com/spatie",
3724:                     "type": "github"
3725:                 }
3726:             ],
3727:             "time": "2026-10-05T08:08:06+00:00"
3728:         },
3729:         {
3730:             "name": "spatie/laravel-package-tools",
3731:             "version": "1.93.3",
3732:             "source": {
3733:                 "type": "git",
3734:                 "url": "https://github.com/spatie/laravel-package-tools.git",
3735:                 "reference": "7aeef26bd8d4909bec744dbf47aee7d1395588c1"
3736:             },
3737:             "dist": {
3738:                 "type": "zip",
3739:                 "url": "https://api.github.com/repos/spatie/laravel-package-tools/zipball/7aeef26bd8d4909bec744dbf47aee7d1395588c1",
3740:                 "reference": "7aeef26bd8d4909bec744dbf47aee7d1395588c1",
3741:                 "shasum": ""
3742:             },
3743:             "require": {
3744:                 "illuminate/contracts": "^10.0|^11.0|^12.0|^13.0",
3745:                 "php": "^8.1"
3746:             },
3747:             "require-dev": {
3748:                 "mockery/mockery": "^1.5",
3749:                 "orchestra/testbench": "^8.0|^9.2|^10.0|^11.0",
3750:                 "pestphp/pest": "^2.1|^3.1|^4.0",
3751:                 "phpunit/php-code-coverage": "^10.0|^11.0|^12.0",
3752:                 "phpunit/phpunit": "^10.5|^11.5|^12.5",
3753:                 "spatie/pest-plugin-test-time": "^2.2|^3.0"
3754:             },
3755:             "type": "library",
3756:             "autoload": {
3757:                 "psr-4": {
3758:                     "Spatie\\LaravelPackageTools\\": "src"
3759:                 }
3760:             },
3761:             "notification-url": "https://packagist.org/downloads/",
3762:             "license": [
3763:                 "MIT"
3764:             ],
3765:             "authors": [
3766:                 {
3767:                     "name": "Freek Van der Herten",
3768:                     "email": "freek@spatie.be",
3769:                     "role": "Developer"
3770:                 }
3771:             ],
3772:             "description": "Tools for creating Laravel packages",
3773:             "homepage": "https://github.com/spatie/laravel-package-tools",
3774:             "keywords": [
3775:                 "laravel-package-tools",
3776:                 "spatie"
3777:             ],
3778:             "support": {
3779:                 "issues": "https://github.com/spatie/laravel-package-tools/issues",
3780:                 "source": "https://github.com/spatie/laravel-package-tools/tree/1.93.3"
3781:             },
3782:             "funding": [
3783:                 {
3784:                     "url": "https://github.com/spatie",
3785:                     "type": "github"
3786:                 }
3787:             ],
3788:             "time": "2026-09-28T07:16:50+00:00"
3789:         },
3790:         {
3791:             "name": "spatie/laravel-permission",
3792:             "version": "8.3.0",
3793:             "source": {
3794:                 "type": "git",
3795:                 "url": "https://github.com/spatie/laravel-permission.git",
3796:                 "reference": "60e8ed5b2fbf043c2264433fc2680c76b8b66aa6"
3797:             },
3798:             "dist": {
3799:                 "type": "zip",
3800:                 "url": "https://api.github.com/repos/spatie/laravel-permission/zipball/60e8ed5b2fbf043c2264433fc2680c76b8b66aa6",
3801:                 "reference": "60e8ed5b2fbf043c2264433fc2680c76b8b66aa6",
3802:                 "shasum": ""
3803:             },
3804:             "require": {
3805:                 "illuminate/auth": "^12.0|^13.0",
3806:                 "illuminate/container": "^12.0|^13.0",
3807:                 "illuminate/contracts": "^12.0|^13.0",
3808:                 "illuminate/database": "^12.0|^13.0",
3809:                 "php": "^8.3",
3810:                 "spatie/laravel-package-tools": "^1.0"
3811:             },
3812:             "require-dev": {
3813:                 "larastan/larastan": "^3.9",
3814:                 "laravel/octane": "^2.0",
3815:                 "laravel/passport": "^13.0",
3816:                 "laravel/pint": "^1.0",
3817:                 "orchestra/testbench": "^10.0|^11.0",
3818:                 "pestphp/pest": "^3.0|^4.0",
3819:                 "pestphp/pest-plugin-laravel": "^3.0|^4.1",
3820:                 "phpstan/phpstan": "^2.1"
3821:             },
3822:             "type": "library",
3823:             "extra": {
3824:                 "laravel": {
3825:                     "providers": [
3826:                         "Spatie\\Permission\\PermissionServiceProvider"
3827:                     ]
3828:                 },
3829:                 "branch-alias": {
3830:                     "dev-main": "8.x-dev",
3831:                     "dev-master": "8.x-dev"
3832:                 }
3833:             },
3834:             "autoload": {
3835:                 "files": [
3836:                     "src/helpers.php"
3837:                 ],
3838:                 "psr-4": {
3839:                     "Spatie\\Permission\\": "src"
3840:                 }
3841:             },
3842:             "notification-url": "https://packagist.org/downloads/",
3843:             "license": [
3844:                 "MIT"
3845:             ],
3846:             "authors": [
3847:                 {
3848:                     "name": "Freek Van der Herten",
3849:                     "email": "freek@spatie.be",
3850:                     "homepage": "https://spatie.be",
3851:                     "role": "Developer"
3852:                 }
3853:             ],
3854:             "description": "Permission handling for Laravel 12 and up",
3855:             "homepage": "https://github.com/spatie/laravel-permission",
3856:             "keywords": [
3857:                 "acl",
3858:                 "laravel",
3859:                 "permission",
3860:                 "permissions",
3861:                 "rbac",
3862:                 "roles",
3863:                 "security",
3864:                 "spatie"
3865:             ],
3866:             "support": {
3867:                 "issues": "https://github.com/spatie/laravel-permission/issues",
3868:                 "source": "https://github.com/spatie/laravel-permission/tree/8.3.0"
3869:             },
3870:             "funding": [
3871:                 {
3872:                     "url": "https://github.com/spatie",
3873:                     "type": "github"
3874:                 }
3875:             ],
3876:             "time": "2026-07-03T15:36:01+00:00"
3877:         },
3878:         {
3879:             "name": "spatie/temporary-directory",
3880:             "version": "2.4.0",
3881:             "source": {
3882:                 "type": "git",
3883:                 "url": "https://github.com/spatie/temporary-directory.git",
3884:                 "reference": "32cbb9645b28839cf4f476708e99a2c70e6802c9"
3885:             },
3886:             "dist": {
3887:                 "type": "zip",
3888:                 "url": "https://api.github.com/repos/spatie/temporary-directory/zipball/32cbb9645b28839cf4f476708e99a2c70e6802c9",
3889:                 "reference": "32cbb9645b28839cf4f476708e99a2c70e6802c9",
3890:                 "shasum": ""
3891:             },
3892:             "require": {
3893:                 "php": "^8.0"
3894:             },
3895:             "require-dev": {
3896:                 "phpunit/phpunit": "^9.5"
3897:             },
3898:             "type": "library",
3899:             "autoload": {
3900:                 "psr-4": {
3901:                     "Spatie\\TemporaryDirectory\\": "src"
3902:                 }
3903:             },
3904:             "notification-url": "https://packagist.org/downloads/",
3905:             "license": [
3906:                 "MIT"
3907:             ],
3908:             "authors": [
3909:                 {
3910:                     "name": "Alex Vanderbist",
3911:                     "email": "alex@spatie.be",
3912:                     "homepage": "https://spatie.be",
3913:                     "role": "Developer"
3914:                 }
3915:             ],
3916:             "description": "Easily create, use and destroy temporary directories",
3917:             "homepage": "https://github.com/spatie/temporary-directory",
3918:             "keywords": [
3919:                 "php",
3920:                 "spatie",
3921:                 "temporary-directory"
3922:             ],
3923:             "support": {
3924:                 "issues": "https://github.com/spatie/temporary-directory/issues",
3925:                 "source": "https://github.com/spatie/temporary-directory/tree/2.4.0"
3926:             },
3927:             "funding": [
3928:                 {
3929:                     "url": "https://spatie.be/open-source/support-us",
3930:                     "type": "custom"
3931:                 },
3932:                 {
3933:                     "url": "https://github.com/spatie",
3934:                     "type": "github"
3935:                 }
3936:             ],
3937:             "time": "2026-06-22T07:55:44+00:00"
3938:         },
3939:         {
3940:             "name": "symfony/clock",
3941:             "version": "v7.4.21",
3942:             "source": {
3943:                 "type": "git",
3944:                 "url": "https://github.com/symfony/clock.git",
3945:                 "reference": "07437f612dca89c1894c1db04491002b1e798f03"
3946:             },
3947:             "dist": {
3948:                 "type": "zip",
3949:                 "url": "https://api.github.com/repos/symfony/clock/zipball/07437f612dca89c1894c1db04491002b1e798f03",
3950:                 "reference": "07437f612dca89c1894c1db04491002b1e798f03",
3951:                 "shasum": ""
3952:             },
3953:             "require": {
3954:                 "php": ">=8.2",
3955:                 "psr/clock": "^1.0",
3956:                 "symfony/polyfill-php83": "^1.28"
3957:             },
3958:             "provide": {
3959:                 "psr/clock-implementation": "1.0"
3960:             },
3961:             "type": "library",
3962:             "autoload": {
3963:                 "files": [
3964:                     "Resources/now.php"
3965:                 ],
3966:                 "psr-4": {
3967:                     "Symfony\\Component\\Clock\\": ""
3968:                 },
3969:                 "exclude-from-classmap": [
3970:                     "/Tests/"
3971:                 ]
3972:             },
3973:             "notification-url": "https://packagist.org/downloads/",
3974:             "license": [
3975:                 "MIT"
3976:             ],
3977:             "authors": [
3978:                 {
3979:                     "name": "Nicolas Grekas",
3980:                     "email": "p@tchwork.com"
3981:                 },
3982:                 {
3983:                     "name": "Symfony Community",
3984:                     "homepage": "https://symfony.com/contributors"
3985:                 }
3986:             ],
3987:             "description": "Decouples applications from the system clock",
3988:             "homepage": "https://symfony.com",
3989:             "keywords": [
3990:                 "clock",
3991:                 "psr20",
3992:                 "time"
3993:             ],
3994:             "support": {
3995:                 "source": "https://github.com/symfony/clock/tree/v7.4.21"
3996:             },
3997:             "funding": [
3998:                 {
3999:                     "url": "https://symfony.com/sponsor",
4000:                     "type": "custom"
4001:                 },
4002:                 {
4003:                     "url": "https://github.com/fabpot",
4004:                     "type": "github"
4005:                 },
4006:                 {
4007:                     "url": "https://github.com/nicolas-grekas",
4008:                     "type": "github"
4009:                 },
4010:                 {
4011:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4012:                     "type": "tidelift"
4013:                 }
4014:             ],
4015:             "time": "2026-10-12T14:31:36+00:00"
4016:         },
4017:         {
4018:             "name": "symfony/console",
4019:             "version": "v7.4.21",
4020:             "source": {
4021:                 "type": "git",
4022:                 "url": "https://github.com/symfony/console.git",
4023:                 "reference": "21f1bc2296f7ff6043241a8dcbc09559b3732820"
4024:             },
4025:             "dist": {
4026:                 "type": "zip",
4027:                 "url": "https://api.github.com/repos/symfony/console/zipball/21f1bc2296f7ff6043241a8dcbc09559b3732820",
4028:                 "reference": "21f1bc2296f7ff6043241a8dcbc09559b3732820",
4029:                 "shasum": ""
4030:             },
4031:             "require": {
4032:                 "php": ">=8.2",
4033:                 "symfony/deprecation-contracts": "^2.5|^3",
4034:                 "symfony/polyfill-mbstring": "~1.0",
4035:                 "symfony/service-contracts": "^2.5|^3",
4036:                 "symfony/string": "^7.2|^8.0"
4037:             },
4038:             "conflict": {
4039:                 "symfony/dependency-injection": "<6.4",
4040:                 "symfony/dotenv": "<6.4",
4041:                 "symfony/event-dispatcher": "<6.4",
4042:                 "symfony/lock": "<6.4",
4043:                 "symfony/process": "<6.4"
4044:             },
4045:             "provide": {
4046:                 "psr/log-implementation": "1.0|2.0|3.0"
4047:             },
4048:             "require-dev": {
4049:                 "psr/log": "^1|^2|^3",
4050:                 "symfony/config": "^6.4|^7.0|^8.0",
4051:                 "symfony/dependency-injection": "^6.4|^7.0|^8.0",
4052:                 "symfony/event-dispatcher": "^6.4|^7.0|^8.0",
4053:                 "symfony/http-foundation": "^6.4|^7.0|^8.0",
4054:                 "symfony/http-kernel": "^6.4|^7.0|^8.0",
4055:                 "symfony/lock": "^6.4|^7.0|^8.0",
4056:                 "symfony/messenger": "^6.4|^7.0|^8.0",
4057:                 "symfony/process": "^6.4|^7.0|^8.0",
4058:                 "symfony/stopwatch": "^6.4|^7.0|^8.0",
4059:                 "symfony/var-dumper": "^6.4|^7.0|^8.0"
4060:             },
4061:             "type": "library",
4062:             "autoload": {
4063:                 "psr-4": {
4064:                     "Symfony\\Component\\Console\\": ""
4065:                 },
4066:                 "exclude-from-classmap": [
4067:                     "/Tests/"
4068:                 ]
4069:             },
4070:             "notification-url": "https://packagist.org/downloads/",
4071:             "license": [
4072:                 "MIT"
4073:             ],
4074:             "authors": [
4075:                 {
4076:                     "name": "Fabien Potencier",
4077:                     "email": "fabien@symfony.com"
4078:                 },
4079:                 {
4080:                     "name": "Symfony Community",
4081:                     "homepage": "https://symfony.com/contributors"
4082:                 }
4083:             ],
4084:             "description": "Eases the creation of beautiful and testable command line interfaces",
4085:             "homepage": "https://symfony.com",
4086:             "keywords": [
4087:                 "cli",
4088:                 "command-line",
4089:                 "console",
4090:                 "terminal"
4091:             ],
4092:             "support": {
4093:                 "source": "https://github.com/symfony/console/tree/v7.4.21"
4094:             },
4095:             "funding": [
4096:                 {
4097:                     "url": "https://symfony.com/sponsor",
4098:                     "type": "custom"
4099:                 },
4100:                 {
4101:                     "url": "https://github.com/fabpot",
4102:                     "type": "github"
4103:                 },
4104:                 {
4105:                     "url": "https://github.com/nicolas-grekas",
4106:                     "type": "github"
4107:                 },
4108:                 {
4109:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4110:                     "type": "tidelift"
4111:                 }
4112:             ],
4113:             "time": "2026-10-12T18:45:38+00:00"
4114:         },
4115:         {
4116:             "name": "symfony/css-selector",
4117:             "version": "v7.4.18",
4118:             "source": {
4119:                 "type": "git",
4120:                 "url": "https://github.com/symfony/css-selector.git",
4121:                 "reference": "fecf40067fc8d8880ea87b8ac227600b9aadd1d0"
4122:             },
4123:             "dist": {
4124:                 "type": "zip",
4125:                 "url": "https://api.github.com/repos/symfony/css-selector/zipball/fecf40067fc8d8880ea87b8ac227600b9aadd1d0",
4126:                 "reference": "fecf40067fc8d8880ea87b8ac227600b9aadd1d0",
4127:                 "shasum": ""
4128:             },
4129:             "require": {
4130:                 "php": ">=8.2"
4131:             },
4132:             "type": "library",
4133:             "autoload": {
4134:                 "psr-4": {
4135:                     "Symfony\\Component\\CssSelector\\": ""
4136:                 },
4137:                 "exclude-from-classmap": [
4138:                     "/Tests/"
4139:                 ]
4140:             },
4141:             "notification-url": "https://packagist.org/downloads/",
4142:             "license": [
4143:                 "MIT"
4144:             ],
4145:             "authors": [
4146:                 {
4147:                     "name": "Fabien Potencier",
4148:                     "email": "fabien@symfony.com"
4149:                 },
4150:                 {
4151:                     "name": "Jean-François Simon",
4152:                     "email": "jeanfrancois.simon@sensiolabs.com"
4153:                 },
4154:                 {
4155:                     "name": "Symfony Community",
4156:                     "homepage": "https://symfony.com/contributors"
4157:                 }
4158:             ],
4159:             "description": "Converts CSS selectors to XPath expressions",
4160:             "homepage": "https://symfony.com",
4161:             "support": {
4162:                 "source": "https://github.com/symfony/css-selector/tree/v7.4.18"
4163:             },
4164:             "funding": [
4165:                 {
4166:                     "url": "https://symfony.com/sponsor",
4167:                     "type": "custom"
4168:                 },
4169:                 {
4170:                     "url": "https://github.com/fabpot",
4171:                     "type": "github"
4172:                 },
4173:                 {
4174:                     "url": "https://github.com/nicolas-grekas",
4175:                     "type": "github"
4176:                 },
4177:                 {
4178:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4179:                     "type": "tidelift"
4180:                 }
4181:             ],
4182:             "time": "2026-08-23T10:03:40+00:00"
4183:         },
4184:         {
4185:             "name": "symfony/deprecation-contracts",
4186:             "version": "v3.7.1",
4187:             "source": {
4188:                 "type": "git",
4189:                 "url": "https://github.com/symfony/deprecation-contracts.git",
4190:                 "reference": "f3202fa1b5097b0af062dc978b32ecf63404e31d"
4191:             },
4192:             "dist": {
4193:                 "type": "zip",
4194:                 "url": "https://api.github.com/repos/symfony/deprecation-contracts/zipball/f3202fa1b5097b0af062dc978b32ecf63404e31d",
4195:                 "reference": "f3202fa1b5097b0af062dc978b32ecf63404e31d",
4196:                 "shasum": ""
4197:             },
4198:             "require": {
4199:                 "php": ">=8.1"
4200:             },
4201:             "type": "library",
4202:             "extra": {
4203:                 "thanks": {
4204:                     "url": "https://github.com/symfony/contracts",
4205:                     "name": "symfony/contracts"
4206:                 },
4207:                 "branch-alias": {
4208:                     "dev-main": "3.7-dev"
4209:                 }
4210:             },
4211:             "autoload": {
4212:                 "files": [
4213:                     "function.php"
4214:                 ]
4215:             },
4216:             "notification-url": "https://packagist.org/downloads/",
4217:             "license": [
4218:                 "MIT"
4219:             ],
4220:             "authors": [
4221:                 {
4222:                     "name": "Nicolas Grekas",
4223:                     "email": "p@tchwork.com"
4224:                 },
4225:                 {
4226:                     "name": "Symfony Community",
4227:                     "homepage": "https://symfony.com/contributors"
4228:                 }
4229:             ],
4230:             "description": "A generic function and convention to trigger deprecation notices",
4231:             "homepage": "https://symfony.com",
4232:             "support": {
4233:                 "source": "https://github.com/symfony/deprecation-contracts/tree/v3.7.1"
4234:             },
4235:             "funding": [
4236:                 {
4237:                     "url": "https://symfony.com/sponsor",
4238:                     "type": "custom"
4239:                 },
4240:                 {
4241:                     "url": "https://github.com/fabpot",
4242:                     "type": "github"
4243:                 },
4244:                 {
4245:                     "url": "https://github.com/nicolas-grekas",
4246:                     "type": "github"
4247:                 },
4248:                 {
4249:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4250:                     "type": "tidelift"
4251:                 }
4252:             ],
4253:             "time": "2026-06-05T06:23:12+00:00"
4254:         },
4255:         {
4256:             "name": "symfony/error-handler",
4257:             "version": "v7.4.20",
4258:             "source": {
4259:                 "type": "git",
4260:                 "url": "https://github.com/symfony/error-handler.git",
4261:                 "reference": "82239fe00f7cdc9f17c4166fff4ebdc22bd26ec0"
4262:             },
4263:             "dist": {
4264:                 "type": "zip",
4265:                 "url": "https://api.github.com/repos/symfony/error-handler/zipball/82239fe00f7cdc9f17c4166fff4ebdc22bd26ec0",
4266:                 "reference": "82239fe00f7cdc9f17c4166fff4ebdc22bd26ec0",
4267:                 "shasum": ""
4268:             },
4269:             "require": {
4270:                 "php": ">=8.2",
4271:                 "psr/log": "^1|^2|^3",
4272:                 "symfony/polyfill-php85": "^1.32",
4273:                 "symfony/var-dumper": "^6.4|^7.0|^8.0"
4274:             },
4275:             "conflict": {
4276:                 "symfony/deprecation-contracts": "<2.5",
4277:                 "symfony/http-kernel": "<6.4"
4278:             },
4279:             "require-dev": {
4280:                 "symfony/console": "^6.4|^7.0|^8.0",
4281:                 "symfony/deprecation-contracts": "^2.5|^3",
4282:                 "symfony/http-kernel": "^6.4|^7.0|^8.0",
4283:                 "symfony/serializer": "^6.4|^7.0|^8.0",
4284:                 "symfony/webpack-encore-bundle": "^1.0|^2.0"
4285:             },
4286:             "bin": [
4287:                 "Resources/bin/patch-type-declarations"
4288:             ],
4289:             "type": "library",
4290:             "autoload": {
4291:                 "psr-4": {
4292:                     "Symfony\\Component\\ErrorHandler\\": ""
4293:                 },
4294:                 "exclude-from-classmap": [
4295:                     "/Tests/"
4296:                 ]
4297:             },
4298:             "notification-url": "https://packagist.org/downloads/",
4299:             "license": [
4300:                 "MIT"
4301:             ],
4302:             "authors": [
4303:                 {
4304:                     "name": "Fabien Potencier",
4305:                     "email": "fabien@symfony.com"
4306:                 },
4307:                 {
4308:                     "name": "Symfony Community",
4309:                     "homepage": "https://symfony.com/contributors"
4310:                 }
4311:             ],
4312:             "description": "Provides tools to manage errors and ease debugging PHP code",
4313:             "homepage": "https://symfony.com",
4314:             "support": {
4315:                 "source": "https://github.com/symfony/error-handler/tree/v7.4.20"
4316:             },
4317:             "funding": [
4318:                 {
4319:                     "url": "https://symfony.com/sponsor",
4320:                     "type": "custom"
4321:                 },
4322:                 {
4323:                     "url": "https://github.com/fabpot",
4324:                     "type": "github"
4325:                 },
4326:                 {
4327:                     "url": "https://github.com/nicolas-grekas",
4328:                     "type": "github"
4329:                 },
4330:                 {
4331:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4332:                     "type": "tidelift"
4333:                 }
4334:             ],
4335:             "time": "2026-09-22T18:56:40+00:00"
4336:         },
4337:         {
4338:             "name": "symfony/event-dispatcher",
4339:             "version": "v7.4.17",
4340:             "source": {
4341:                 "type": "git",
4342:                 "url": "https://github.com/symfony/event-dispatcher.git",
4343:                 "reference": "d269974ee93c61d03620ffee358355bfdb471d66"
4344:             },
4345:             "dist": {
4346:                 "type": "zip",
4347:                 "url": "https://api.github.com/repos/symfony/event-dispatcher/zipball/d269974ee93c61d03620ffee358355bfdb471d66",
4348:                 "reference": "d269974ee93c61d03620ffee358355bfdb471d66",
4349:                 "shasum": ""
4350:             },
4351:             "require": {
4352:                 "php": ">=8.2",
4353:                 "symfony/event-dispatcher-contracts": "^2.5|^3"
4354:             },
4355:             "conflict": {
4356:                 "symfony/dependency-injection": "<6.4",
4357:                 "symfony/service-contracts": "<2.5"
4358:             },
4359:             "provide": {
4360:                 "psr/event-dispatcher-implementation": "1.0",
4361:                 "symfony/event-dispatcher-implementation": "2.0|3.0"
4362:             },
4363:             "require-dev": {
4364:                 "psr/log": "^1|^2|^3",
4365:                 "symfony/config": "^6.4|^7.0|^8.0",
4366:                 "symfony/dependency-injection": "^6.4|^7.0|^8.0",
4367:                 "symfony/error-handler": "^6.4|^7.0|^8.0",
4368:                 "symfony/expression-language": "^6.4|^7.0|^8.0",
4369:                 "symfony/framework-bundle": "^6.4|^7.0|^8.0",
4370:                 "symfony/http-foundation": "^6.4|^7.0|^8.0",
4371:                 "symfony/service-contracts": "^2.5|^3",
4372:                 "symfony/stopwatch": "^6.4|^7.0|^8.0"
4373:             },
4374:             "type": "library",
4375:             "autoload": {
4376:                 "psr-4": {
4377:                     "Symfony\\Component\\EventDispatcher\\": ""
4378:                 },
4379:                 "exclude-from-classmap": [
4380:                     "/Tests/"
4381:                 ]
4382:             },
4383:             "notification-url": "https://packagist.org/downloads/",
4384:             "license": [
4385:                 "MIT"
4386:             ],
4387:             "authors": [
4388:                 {
4389:                     "name": "Fabien Potencier",
4390:                     "email": "fabien@symfony.com"
4391:                 },
4392:                 {
4393:                     "name": "Symfony Community",
4394:                     "homepage": "https://symfony.com/contributors"
4395:                 }
4396:             ],
4397:             "description": "Provides tools that allow your application components to communicate with each other by dispatching events and listening to them",
4398:             "homepage": "https://symfony.com",
4399:             "support": {
4400:                 "source": "https://github.com/symfony/event-dispatcher/tree/v7.4.17"
4401:             },
4402:             "funding": [
4403:                 {
4404:                     "url": "https://symfony.com/sponsor",
4405:                     "type": "custom"
4406:                 },
4407:                 {
4408:                     "url": "https://github.com/fabpot",
4409:                     "type": "github"
4410:                 },
4411:                 {
4412:                     "url": "https://github.com/nicolas-grekas",
4413:                     "type": "github"
4414:                 },
4415:                 {
4416:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4417:                     "type": "tidelift"
4418:                 }
4419:             ],
4420:             "time": "2026-08-21T17:40:08+00:00"
4421:         },
4422:         {
4423:             "name": "symfony/event-dispatcher-contracts",
4424:             "version": "v3.7.1",
4425:             "source": {
4426:                 "type": "git",
4427:                 "url": "https://github.com/symfony/event-dispatcher-contracts.git",
4428:                 "reference": "c7de7a00ffb67842132da02ea92988a39ccd9f4e"
4429:             },
4430:             "dist": {
4431:                 "type": "zip",
4432:                 "url": "https://api.github.com/repos/symfony/event-dispatcher-contracts/zipball/c7de7a00ffb67842132da02ea92988a39ccd9f4e",
4433:                 "reference": "c7de7a00ffb67842132da02ea92988a39ccd9f4e",
4434:                 "shasum": ""
4435:             },
4436:             "require": {
4437:                 "php": ">=8.1",
4438:                 "psr/event-dispatcher": "^1"
4439:             },
4440:             "type": "library",
4441:             "extra": {
4442:                 "thanks": {
4443:                     "url": "https://github.com/symfony/contracts",
4444:                     "name": "symfony/contracts"
4445:                 },
4446:                 "branch-alias": {
4447:                     "dev-main": "3.7-dev"
4448:                 }
4449:             },
4450:             "autoload": {
4451:                 "psr-4": {
4452:                     "Symfony\\Contracts\\EventDispatcher\\": ""
4453:                 }
4454:             },
4455:             "notification-url": "https://packagist.org/downloads/",
4456:             "license": [
4457:                 "MIT"
4458:             ],
4459:             "authors": [
4460:                 {
4461:                     "name": "Nicolas Grekas",
4462:                     "email": "p@tchwork.com"
4463:                 },
4464:                 {
4465:                     "name": "Symfony Community",
4466:                     "homepage": "https://symfony.com/contributors"
4467:                 }
4468:             ],
4469:             "description": "Generic abstractions related to dispatching event",
4470:             "homepage": "https://symfony.com",
4471:             "keywords": [
4472:                 "abstractions",
4473:                 "contracts",
4474:                 "decoupling",
4475:                 "interfaces",
4476:                 "interoperability",
4477:                 "standards"
4478:             ],
4479:             "support": {
4480:                 "source": "https://github.com/symfony/event-dispatcher-contracts/tree/v3.7.1"
4481:             },
4482:             "funding": [
4483:                 {
4484:                     "url": "https://symfony.com/sponsor",
4485:                     "type": "custom"
4486:                 },
4487:                 {
4488:                     "url": "https://github.com/fabpot",
4489:                     "type": "github"
4490:                 },
4491:                 {
4492:                     "url": "https://github.com/nicolas-grekas",
4493:                     "type": "github"
4494:                 },
4495:                 {
4496:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4497:                     "type": "tidelift"
4498:                 }
4499:             ],
4500:             "time": "2026-06-05T06:23:12+00:00"
4501:         },
4502:         {
4503:             "name": "symfony/finder",
4504:             "version": "v7.4.20",
4505:             "source": {
4506:                 "type": "git",
4507:                 "url": "https://github.com/symfony/finder.git",
4508:                 "reference": "2dd31e5208db7a0fda7bfc998ad727b79c2e4d1e"
4509:             },
4510:             "dist": {
4511:                 "type": "zip",
4512:                 "url": "https://api.github.com/repos/symfony/finder/zipball/2dd31e5208db7a0fda7bfc998ad727b79c2e4d1e",
4513:                 "reference": "2dd31e5208db7a0fda7bfc998ad727b79c2e4d1e",
4514:                 "shasum": ""
4515:             },
4516:             "require": {
4517:                 "php": ">=8.2"
4518:             },
4519:             "require-dev": {
4520:                 "symfony/filesystem": "^6.4|^7.0|^8.0"
4521:             },
4522:             "type": "library",
4523:             "autoload": {
4524:                 "psr-4": {
4525:                     "Symfony\\Component\\Finder\\": ""
4526:                 },
4527:                 "exclude-from-classmap": [
4528:                     "/Tests/"
4529:                 ]
4530:             },
4531:             "notification-url": "https://packagist.org/downloads/",
4532:             "license": [
4533:                 "MIT"
4534:             ],
4535:             "authors": [
4536:                 {
4537:                     "name": "Fabien Potencier",
4538:                     "email": "fabien@symfony.com"
4539:                 },
4540:                 {
4541:                     "name": "Symfony Community",
4542:                     "homepage": "https://symfony.com/contributors"
4543:                 }
4544:             ],
4545:             "description": "Finds files and directories via an intuitive fluent interface",
4546:             "homepage": "https://symfony.com",
4547:             "support": {
4548:                 "source": "https://github.com/symfony/finder/tree/v7.4.20"
4549:             },
4550:             "funding": [
4551:                 {
4552:                     "url": "https://symfony.com/sponsor",
4553:                     "type": "custom"
4554:                 },
4555:                 {
4556:                     "url": "https://github.com/fabpot",
4557:                     "type": "github"
4558:                 },
4559:                 {
4560:                     "url": "https://github.com/nicolas-grekas",
4561:                     "type": "github"
4562:                 },
4563:                 {
4564:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4565:                     "type": "tidelift"
4566:                 }
4567:             ],
4568:             "time": "2026-09-26T06:31:10+00:00"
4569:         },
4570:         {
4571:             "name": "symfony/http-foundation",
4572:             "version": "v7.4.21",
4573:             "source": {
4574:                 "type": "git",
4575:                 "url": "https://github.com/symfony/http-foundation.git",
4576:                 "reference": "58c89144c7a36dd89c3d01917e9fa3a72d8aaf31"
4577:             },
4578:             "dist": {
4579:                 "type": "zip",
4580:                 "url": "https://api.github.com/repos/symfony/http-foundation/zipball/58c89144c7a36dd89c3d01917e9fa3a72d8aaf31",
4581:                 "reference": "58c89144c7a36dd89c3d01917e9fa3a72d8aaf31",
4582:                 "shasum": ""
4583:             },
4584:             "require": {
4585:                 "php": ">=8.2",
4586:                 "symfony/deprecation-contracts": "^2.5|^3",
4587:                 "symfony/polyfill-mbstring": "^1.1"
4588:             },
4589:             "conflict": {
4590:                 "doctrine/dbal": "<3.6",
4591:                 "symfony/cache": "<6.4.12|>=7.0,<7.1.5"
4592:             },
4593:             "require-dev": {
4594:                 "doctrine/dbal": "^3.6|^4",
4595:                 "predis/predis": "^1.1|^2.0",
4596:                 "symfony/cache": "^6.4.12|^7.1.5|^8.0",
4597:                 "symfony/clock": "^6.4|^7.0|^8.0",
4598:                 "symfony/dependency-injection": "^6.4|^7.0|^8.0",
4599:                 "symfony/expression-language": "^6.4|^7.0|^8.0",
4600:                 "symfony/http-kernel": "^6.4|^7.0|^8.0",
4601:                 "symfony/mime": "^6.4|^7.0|^8.0",
4602:                 "symfony/rate-limiter": "^6.4|^7.0|^8.0"
4603:             },
4604:             "type": "library",
4605:             "autoload": {
4606:                 "psr-4": {
4607:                     "Symfony\\Component\\HttpFoundation\\": ""
4608:                 },
4609:                 "exclude-from-classmap": [
4610:                     "/Tests/"
4611:                 ]
4612:             },
4613:             "notification-url": "https://packagist.org/downloads/",
4614:             "license": [
4615:                 "MIT"
4616:             ],
4617:             "authors": [
4618:                 {
4619:                     "name": "Fabien Potencier",
4620:                     "email": "fabien@symfony.com"
4621:                 },
4622:                 {
4623:                     "name": "Symfony Community",
4624:                     "homepage": "https://symfony.com/contributors"
4625:                 }
4626:             ],
4627:             "description": "Defines an object-oriented layer for the HTTP specification",
4628:             "homepage": "https://symfony.com",
4629:             "support": {
4630:                 "source": "https://github.com/symfony/http-foundation/tree/v7.4.21"
4631:             },
4632:             "funding": [
4633:                 {
4634:                     "url": "https://symfony.com/sponsor",
4635:                     "type": "custom"
4636:                 },
4637:                 {
4638:                     "url": "https://github.com/fabpot",
4639:                     "type": "github"
4640:                 },
4641:                 {
4642:                     "url": "https://github.com/nicolas-grekas",
4643:                     "type": "github"
4644:                 },
4645:                 {
4646:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4647:                     "type": "tidelift"
4648:                 }
4649:             ],
4650:             "time": "2026-10-08T16:56:12+00:00"
4651:         },
4652:         {
4653:             "name": "symfony/http-kernel",
4654:             "version": "v7.4.21",
4655:             "source": {
4656:                 "type": "git",
4657:                 "url": "https://github.com/symfony/http-kernel.git",
4658:                 "reference": "4779fce7dedacb8801b2b61c703ef99159502331"
4659:             },
4660:             "dist": {
4661:                 "type": "zip",
4662:                 "url": "https://api.github.com/repos/symfony/http-kernel/zipball/4779fce7dedacb8801b2b61c703ef99159502331",
4663:                 "reference": "4779fce7dedacb8801b2b61c703ef99159502331",
4664:                 "shasum": ""
4665:             },
4666:             "require": {
4667:                 "php": ">=8.2",
4668:                 "psr/log": "^1|^2|^3",
4669:                 "symfony/deprecation-contracts": "^2.5|^3",
4670:                 "symfony/error-handler": "^6.4|^7.0|^8.0",
4671:                 "symfony/event-dispatcher": "^7.3|^8.0",
4672:                 "symfony/http-foundation": "^7.4|^8.0",
4673:                 "symfony/polyfill-ctype": "^1.8"
4674:             },
4675:             "conflict": {
4676:                 "symfony/browser-kit": "<6.4",
4677:                 "symfony/cache": "<6.4",
4678:                 "symfony/config": "<6.4",
4679:                 "symfony/console": "<6.4",
4680:                 "symfony/dependency-injection": "<6.4",
4681:                 "symfony/doctrine-bridge": "<6.4",
4682:                 "symfony/flex": "<2.10",
4683:                 "symfony/form": "<6.4",
4684:                 "symfony/http-client": "<6.4",
4685:                 "symfony/http-client-contracts": "<2.5",
4686:                 "symfony/mailer": "<6.4",
4687:                 "symfony/messenger": "<6.4",
4688:                 "symfony/translation": "<6.4",
4689:                 "symfony/translation-contracts": "<2.5",
4690:                 "symfony/twig-bridge": "<6.4",
4691:                 "symfony/validator": "<6.4",
4692:                 "symfony/var-dumper": "<6.4",
4693:                 "twig/twig": "<3.12"
4694:             },
4695:             "provide": {
4696:                 "psr/log-implementation": "1.0|2.0|3.0"
4697:             },
4698:             "require-dev": {
4699:                 "psr/cache": "^1.0|^2.0|^3.0",
4700:                 "symfony/browser-kit": "^6.4|^7.0|^8.0",
4701:                 "symfony/clock": "^6.4|^7.0|^8.0",
4702:                 "symfony/config": "^6.4|^7.0|^8.0",
4703:                 "symfony/console": "^6.4|^7.0|^8.0",
4704:                 "symfony/css-selector": "^6.4|^7.0|^8.0",
4705:                 "symfony/dependency-injection": "^6.4.1|^7.0.1|^8.0",
4706:                 "symfony/dom-crawler": "^6.4|^7.0|^8.0",
4707:                 "symfony/expression-language": "^6.4|^7.0|^8.0",
4708:                 "symfony/finder": "^6.4|^7.0|^8.0",
4709:                 "symfony/http-client-contracts": "^2.5|^3",
4710:                 "symfony/process": "^6.4|^7.0|^8.0",
4711:                 "symfony/property-access": "^7.1|^8.0",
4712:                 "symfony/routing": "^6.4|^7.0|^8.0",
4713:                 "symfony/serializer": "^7.1|^8.0",
4714:                 "symfony/stopwatch": "^6.4|^7.0|^8.0",
4715:                 "symfony/translation": "^6.4|^7.0|^8.0",
4716:                 "symfony/translation-contracts": "^2.5|^3",
4717:                 "symfony/uid": "^6.4|^7.0|^8.0",
4718:                 "symfony/validator": "^6.4|^7.0|^8.0",
4719:                 "symfony/var-dumper": "^6.4|^7.0|^8.0",
4720:                 "symfony/var-exporter": "^6.4|^7.0|^8.0",
4721:                 "twig/twig": "^3.12|^4.0"
4722:             },
4723:             "type": "library",
4724:             "autoload": {
4725:                 "psr-4": {
4726:                     "Symfony\\Component\\HttpKernel\\": ""
4727:                 },
4728:                 "exclude-from-classmap": [
4729:                     "/Tests/"
4730:                 ]
4731:             },
4732:             "notification-url": "https://packagist.org/downloads/",
4733:             "license": [
4734:                 "MIT"
4735:             ],
4736:             "authors": [
4737:                 {
4738:                     "name": "Fabien Potencier",
4739:                     "email": "fabien@symfony.com"
4740:                 },
4741:                 {
4742:                     "name": "Symfony Community",
4743:                     "homepage": "https://symfony.com/contributors"
4744:                 }
4745:             ],
4746:             "description": "Provides a structured process for converting a Request into a Response",
4747:             "homepage": "https://symfony.com",
4748:             "support": {
4749:                 "source": "https://github.com/symfony/http-kernel/tree/v7.4.21"
4750:             },
4751:             "funding": [
4752:                 {
4753:                     "url": "https://symfony.com/sponsor",
4754:                     "type": "custom"
4755:                 },
4756:                 {
4757:                     "url": "https://github.com/fabpot",
4758:                     "type": "github"
4759:                 },
4760:                 {
4761:                     "url": "https://github.com/nicolas-grekas",
4762:                     "type": "github"
4763:                 },
4764:                 {
4765:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4766:                     "type": "tidelift"
4767:                 }
4768:             ],
4769:             "time": "2026-10-14T06:44:08+00:00"
4770:         },
4771:         {
4772:             "name": "symfony/mailer",
4773:             "version": "v7.4.19",
4774:             "source": {
4775:                 "type": "git",
4776:                 "url": "https://github.com/symfony/mailer.git",
4777:                 "reference": "6f6f2441bef07a42d2617b9b3aadf04a321aa514"
4778:             },
4779:             "dist": {
4780:                 "type": "zip",
4781:                 "url": "https://api.github.com/repos/symfony/mailer/zipball/6f6f2441bef07a42d2617b9b3aadf04a321aa514",
4782:                 "reference": "6f6f2441bef07a42d2617b9b3aadf04a321aa514",
4783:                 "shasum": ""
4784:             },
4785:             "require": {
4786:                 "egulias/email-validator": "^2.1.10|^3|^4",
4787:                 "php": ">=8.2",
4788:                 "psr/event-dispatcher": "^1",
4789:                 "psr/log": "^1|^2|^3",
4790:                 "symfony/event-dispatcher": "^6.4|^7.0|^8.0",
4791:                 "symfony/mime": "^7.2|^8.0",
4792:                 "symfony/service-contracts": "^2.5|^3"
4793:             },
4794:             "conflict": {
4795:                 "symfony/http-client-contracts": "<2.5",
4796:                 "symfony/http-kernel": "<6.4",
4797:                 "symfony/messenger": "<6.4",
4798:                 "symfony/mime": "<6.4",
4799:                 "symfony/twig-bridge": "<6.4"
4800:             },
4801:             "require-dev": {
4802:                 "symfony/console": "^6.4|^7.0|^8.0",
4803:                 "symfony/http-client": "^6.4|^7.0|^8.0",
4804:                 "symfony/messenger": "^6.4|^7.0|^8.0",
4805:                 "symfony/twig-bridge": "^6.4|^7.0|^8.0"
4806:             },
4807:             "type": "library",
4808:             "autoload": {
4809:                 "psr-4": {
4810:                     "Symfony\\Component\\Mailer\\": ""
4811:                 },
4812:                 "exclude-from-classmap": [
4813:                     "/Tests/"
4814:                 ]
4815:             },
4816:             "notification-url": "https://packagist.org/downloads/",
4817:             "license": [
4818:                 "MIT"
4819:             ],
4820:             "authors": [
4821:                 {
4822:                     "name": "Fabien Potencier",
4823:                     "email": "fabien@symfony.com"
4824:                 },
4825:                 {
4826:                     "name": "Symfony Community",
4827:                     "homepage": "https://symfony.com/contributors"
4828:                 }
4829:             ],
4830:             "description": "Helps sending emails",
4831:             "homepage": "https://symfony.com",
4832:             "support": {
4833:                 "source": "https://github.com/symfony/mailer/tree/v7.4.19"
4834:             },
4835:             "funding": [
4836:                 {
4837:                     "url": "https://symfony.com/sponsor",
4838:                     "type": "custom"
4839:                 },
4840:                 {
4841:                     "url": "https://github.com/fabpot",
4842:                     "type": "github"
4843:                 },
4844:                 {
4845:                     "url": "https://github.com/nicolas-grekas",
4846:                     "type": "github"
4847:                 },
4848:                 {
4849:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4850:                     "type": "tidelift"
4851:                 }
4852:             ],
4853:             "time": "2026-09-15T06:01:15+00:00"
4854:         },
4855:         {
4856:             "name": "symfony/mime",
4857:             "version": "v7.4.19",
4858:             "source": {
4859:                 "type": "git",
4860:                 "url": "https://github.com/symfony/mime.git",
4861:                 "reference": "f8ac7d0a800bcabbcd1f7de56594cf05e6cd5693"
4862:             },
4863:             "dist": {
4864:                 "type": "zip",
4865:                 "url": "https://api.github.com/repos/symfony/mime/zipball/f8ac7d0a800bcabbcd1f7de56594cf05e6cd5693",
4866:                 "reference": "f8ac7d0a800bcabbcd1f7de56594cf05e6cd5693",
4867:                 "shasum": ""
4868:             },
4869:             "require": {
4870:                 "php": ">=8.2",
4871:                 "symfony/deprecation-contracts": "^2.5|^3",
4872:                 "symfony/polyfill-intl-idn": "^1.10",
4873:                 "symfony/polyfill-mbstring": "^1.0"
4874:             },
4875:             "conflict": {
4876:                 "egulias/email-validator": "~3.0.0",
4877:                 "phpdocumentor/reflection-docblock": "<5.2|>=7",
4878:                 "phpdocumentor/type-resolver": "<1.5.1",
4879:                 "symfony/mailer": "<6.4",
4880:                 "symfony/serializer": "<6.4.3|>7.0,<7.0.3"
4881:             },
4882:             "require-dev": {
4883:                 "egulias/email-validator": "^2.1.10|^3.1|^4",
4884:                 "league/html-to-markdown": "^5.0",
4885:                 "phpdocumentor/reflection-docblock": "^5.2|^6.0",
4886:                 "symfony/dependency-injection": "^6.4|^7.0|^8.0",
4887:                 "symfony/process": "^6.4|^7.0|^8.0",
4888:                 "symfony/property-access": "^6.4|^7.0|^8.0",
4889:                 "symfony/property-info": "^6.4|^7.0|^8.0",
4890:                 "symfony/serializer": "^6.4.44|^7.4.17|^8.1.5"
4891:             },
4892:             "type": "library",
4893:             "autoload": {
4894:                 "psr-4": {
4895:                     "Symfony\\Component\\Mime\\": ""
4896:                 },
4897:                 "exclude-from-classmap": [
4898:                     "/Tests/"
4899:                 ]
4900:             },
4901:             "notification-url": "https://packagist.org/downloads/",
4902:             "license": [
4903:                 "MIT"
4904:             ],
4905:             "authors": [
4906:                 {
4907:                     "name": "Fabien Potencier",
4908:                     "email": "fabien@symfony.com"
4909:                 },
4910:                 {
4911:                     "name": "Symfony Community",
4912:                     "homepage": "https://symfony.com/contributors"
4913:                 }
4914:             ],
4915:             "description": "Allows manipulating MIME messages",
4916:             "homepage": "https://symfony.com",
4917:             "keywords": [
4918:                 "mime",
4919:                 "mime-type"
4920:             ],
4921:             "support": {
4922:                 "source": "https://github.com/symfony/mime/tree/v7.4.19"
4923:             },
4924:             "funding": [
4925:                 {
4926:                     "url": "https://symfony.com/sponsor",
4927:                     "type": "custom"
4928:                 },
4929:                 {
4930:                     "url": "https://github.com/fabpot",
4931:                     "type": "github"
4932:                 },
4933:                 {
4934:                     "url": "https://github.com/nicolas-grekas",
4935:                     "type": "github"
4936:                 },
4937:                 {
4938:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
4939:                     "type": "tidelift"
4940:                 }
4941:             ],
4942:             "time": "2026-09-04T10:45:44+00:00"
4943:         },
4944:         {
4945:             "name": "symfony/polyfill-ctype",
4946:             "version": "v1.37.0",
4947:             "source": {
4948:                 "type": "git",
4949:                 "url": "https://github.com/symfony/polyfill-ctype.git",
4950:                 "reference": "141046a8f9477948ff284fa65be2095baafb94f2"
4951:             },
4952:             "dist": {
4953:                 "type": "zip",
4954:                 "url": "https://api.github.com/repos/symfony/polyfill-ctype/zipball/141046a8f9477948ff284fa65be2095baafb94f2",
4955:                 "reference": "141046a8f9477948ff284fa65be2095baafb94f2",
4956:                 "shasum": ""
4957:             },
4958:             "require": {
4959:                 "php": ">=7.2"
4960:             },
4961:             "provide": {
4962:                 "ext-ctype": "*"
4963:             },
4964:             "suggest": {
4965:                 "ext-ctype": "For best performance"
4966:             },
4967:             "type": "library",
4968:             "extra": {
4969:                 "thanks": {
4970:                     "url": "https://github.com/symfony/polyfill",
4971:                     "name": "symfony/polyfill"
4972:                 }
4973:             },
4974:             "autoload": {
4975:                 "files": [
4976:                     "bootstrap.php"
4977:                 ],
4978:                 "psr-4": {
4979:                     "Symfony\\Polyfill\\Ctype\\": ""
4980:                 }
4981:             },
4982:             "notification-url": "https://packagist.org/downloads/",
4983:             "license": [
4984:                 "MIT"
4985:             ],
4986:             "authors": [
4987:                 {
4988:                     "name": "Gert de Pagter",
4989:                     "email": "BackEndTea@gmail.com"
4990:                 },
4991:                 {
4992:                     "name": "Symfony Community",
4993:                     "homepage": "https://symfony.com/contributors"
4994:                 }
4995:             ],
4996:             "description": "Symfony polyfill for ctype functions",
4997:             "homepage": "https://symfony.com",
4998:             "keywords": [
4999:                 "compatibility",
5000:                 "ctype",
5001:                 "polyfill",
5002:                 "portable"
5003:             ],
5004:             "support": {
5005:                 "source": "https://github.com/symfony/polyfill-ctype/tree/v1.37.0"
5006:             },
5007:             "funding": [
5008:                 {
5009:                     "url": "https://symfony.com/sponsor",
5010:                     "type": "custom"
5011:                 },
5012:                 {
5013:                     "url": "https://github.com/fabpot",
5014:                     "type": "github"
5015:                 },
5016:                 {
5017:                     "url": "https://github.com/nicolas-grekas",
5018:                     "type": "github"
5019:                 },
5020:                 {
5021:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5022:                     "type": "tidelift"
5023:                 }
5024:             ],
5025:             "time": "2026-04-10T16:19:22+00:00"
5026:         },
5027:         {
5028:             "name": "symfony/polyfill-intl-grapheme",
5029:             "version": "v1.43.0",
5030:             "source": {
5031:                 "type": "git",
5032:                 "url": "https://github.com/symfony/polyfill-intl-grapheme.git",
5033:                 "reference": "5cac8f4db97208823aa353f59a01faad2da52c75"
5034:             },
5035:             "dist": {
5036:                 "type": "zip",
5037:                 "url": "https://api.github.com/repos/symfony/polyfill-intl-grapheme/zipball/5cac8f4db97208823aa353f59a01faad2da52c75",
5038:                 "reference": "5cac8f4db97208823aa353f59a01faad2da52c75",
5039:                 "shasum": ""
5040:             },
5041:             "require": {
5042:                 "php": ">=7.2"
5043:             },
5044:             "suggest": {
5045:                 "ext-intl": "For best performance"
5046:             },
5047:             "type": "library",
5048:             "extra": {
5049:                 "thanks": {
5050:                     "url": "https://github.com/symfony/polyfill",
5051:                     "name": "symfony/polyfill"
5052:                 }
5053:             },
5054:             "autoload": {
5055:                 "files": [
5056:                     "bootstrap.php"
5057:                 ],
5058:                 "psr-4": {
5059:                     "Symfony\\Polyfill\\Intl\\Grapheme\\": ""
5060:                 }
5061:             },
5062:             "notification-url": "https://packagist.org/downloads/",
5063:             "license": [
5064:                 "MIT"
5065:             ],
5066:             "authors": [
5067:                 {
5068:                     "name": "Nicolas Grekas",
5069:                     "email": "p@tchwork.com"
5070:                 },
5071:                 {
5072:                     "name": "Symfony Community",
5073:                     "homepage": "https://symfony.com/contributors"
5074:                 }
5075:             ],
5076:             "description": "Symfony polyfill for intl's grapheme_* functions",
5077:             "homepage": "https://symfony.com",
5078:             "keywords": [
5079:                 "compatibility",
5080:                 "grapheme",
5081:                 "intl",
5082:                 "polyfill",
5083:                 "portable",
5084:                 "shim"
5085:             ],
5086:             "support": {
5087:                 "source": "https://github.com/symfony/polyfill-intl-grapheme/tree/v1.43.0"
5088:             },
5089:             "funding": [
5090:                 {
5091:                     "url": "https://symfony.com/sponsor",
5092:                     "type": "custom"
5093:                 },
5094:                 {
5095:                     "url": "https://github.com/fabpot",
5096:                     "type": "github"
5097:                 },
5098:                 {
5099:                     "url": "https://github.com/nicolas-grekas",
5100:                     "type": "github"
5101:                 },
5102:                 {
5103:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5104:                     "type": "tidelift"
5105:                 }
5106:             ],
5107:             "time": "2026-09-25T15:46:47+00:00"
5108:         },
5109:         {
5110:             "name": "symfony/polyfill-intl-idn",
5111:             "version": "v1.43.0",
5112:             "source": {
5113:                 "type": "git",
5114:                 "url": "https://github.com/symfony/polyfill-intl-idn.git",
5115:                 "reference": "533d68cf99c8f8ee2e8893c59ed59192e92cfd74"
5116:             },
5117:             "dist": {
5118:                 "type": "zip",
5119:                 "url": "https://api.github.com/repos/symfony/polyfill-intl-idn/zipball/533d68cf99c8f8ee2e8893c59ed59192e92cfd74",
5120:                 "reference": "533d68cf99c8f8ee2e8893c59ed59192e92cfd74",
5121:                 "shasum": ""
5122:             },
5123:             "require": {
5124:                 "php": ">=7.2",
5125:                 "symfony/polyfill-intl-normalizer": "^1.10"
5126:             },
5127:             "suggest": {
5128:                 "ext-intl": "For best performance"
5129:             },
5130:             "type": "library",
5131:             "extra": {
5132:                 "thanks": {
5133:                     "url": "https://github.com/symfony/polyfill",
5134:                     "name": "symfony/polyfill"
5135:                 }
5136:             },
5137:             "autoload": {
5138:                 "files": [
5139:                     "bootstrap.php"
5140:                 ],
5141:                 "psr-4": {
5142:                     "Symfony\\Polyfill\\Intl\\Idn\\": ""
5143:                 }
5144:             },
5145:             "notification-url": "https://packagist.org/downloads/",
5146:             "license": [
5147:                 "MIT"
5148:             ],
5149:             "authors": [
5150:                 {
5151:                     "name": "Laurent Bassin",
5152:                     "email": "laurent@bassin.info"
5153:                 },
5154:                 {
5155:                     "name": "Trevor Rowbotham",
5156:                     "email": "trevor.rowbotham@pm.me"
5157:                 },
5158:                 {
5159:                     "name": "Symfony Community",
5160:                     "homepage": "https://symfony.com/contributors"
5161:                 }
5162:             ],
5163:             "description": "Symfony polyfill for intl's idn_to_ascii and idn_to_utf8 functions",
5164:             "homepage": "https://symfony.com",
5165:             "keywords": [
5166:                 "compatibility",
5167:                 "idn",
5168:                 "intl",
5169:                 "polyfill",
5170:                 "portable",
5171:                 "shim"
5172:             ],
5173:             "support": {
5174:                 "source": "https://github.com/symfony/polyfill-intl-idn/tree/v1.43.0"
5175:             },
5176:             "funding": [
5177:                 {
5178:                     "url": "https://symfony.com/sponsor",
5179:                     "type": "custom"
5180:                 },
5181:                 {
5182:                     "url": "https://github.com/fabpot",
5183:                     "type": "github"
5184:                 },
5185:                 {
5186:                     "url": "https://github.com/nicolas-grekas",
5187:                     "type": "github"
5188:                 },
5189:                 {
5190:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5191:                     "type": "tidelift"
5192:                 }
5193:             ],
5194:             "time": "2026-09-23T14:40:04+00:00"
5195:         },
5196:         {
5197:             "name": "symfony/polyfill-intl-normalizer",
5198:             "version": "v1.43.0",
5199:             "source": {
5200:                 "type": "git",
5201:                 "url": "https://github.com/symfony/polyfill-intl-normalizer.git",
5202:                 "reference": "ebd57ccc7edb93d632e184efbe7693d2517bab3b"
5203:             },
5204:             "dist": {
5205:                 "type": "zip",
5206:                 "url": "https://api.github.com/repos/symfony/polyfill-intl-normalizer/zipball/ebd57ccc7edb93d632e184efbe7693d2517bab3b",
5207:                 "reference": "ebd57ccc7edb93d632e184efbe7693d2517bab3b",
5208:                 "shasum": ""
5209:             },
5210:             "require": {
5211:                 "php": ">=7.2"
5212:             },
5213:             "suggest": {
5214:                 "ext-intl": "For best performance"
5215:             },
5216:             "type": "library",
5217:             "extra": {
5218:                 "thanks": {
5219:                     "url": "https://github.com/symfony/polyfill",
5220:                     "name": "symfony/polyfill"
5221:                 }
5222:             },
5223:             "autoload": {
5224:                 "files": [
5225:                     "bootstrap.php"
5226:                 ],
5227:                 "psr-4": {
5228:                     "Symfony\\Polyfill\\Intl\\Normalizer\\": ""
5229:                 },
5230:                 "classmap": [
5231:                     "Resources/stubs"
5232:                 ]
5233:             },
5234:             "notification-url": "https://packagist.org/downloads/",
5235:             "license": [
5236:                 "MIT"
5237:             ],
5238:             "authors": [
5239:                 {
5240:                     "name": "Nicolas Grekas",
5241:                     "email": "p@tchwork.com"
5242:                 },
5243:                 {
5244:                     "name": "Symfony Community",
5245:                     "homepage": "https://symfony.com/contributors"
5246:                 }
5247:             ],
5248:             "description": "Symfony polyfill for intl's Normalizer class and related functions",
5249:             "homepage": "https://symfony.com",
5250:             "keywords": [
5251:                 "compatibility",
5252:                 "intl",
5253:                 "normalizer",
5254:                 "polyfill",
5255:                 "portable",
5256:                 "shim"
5257:             ],
5258:             "support": {
5259:                 "source": "https://github.com/symfony/polyfill-intl-normalizer/tree/v1.43.0"
5260:             },
5261:             "funding": [
5262:                 {
5263:                     "url": "https://symfony.com/sponsor",
5264:                     "type": "custom"
5265:                 },
5266:                 {
5267:                     "url": "https://github.com/fabpot",
5268:                     "type": "github"
5269:                 },
5270:                 {
5271:                     "url": "https://github.com/nicolas-grekas",
5272:                     "type": "github"
5273:                 },
5274:                 {
5275:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5276:                     "type": "tidelift"
5277:                 }
5278:             ],
5279:             "time": "2026-09-23T22:02:09+00:00"
5280:         },
5281:         {
5282:             "name": "symfony/polyfill-mbstring",
5283:             "version": "v1.43.0",
5284:             "source": {
5285:                 "type": "git",
5286:                 "url": "https://github.com/symfony/polyfill-mbstring.git",
5287:                 "reference": "4d5d331bf731e0aedbc6b2f5e61135b7b7996a72"
5288:             },
5289:             "dist": {
5290:                 "type": "zip",
5291:                 "url": "https://api.github.com/repos/symfony/polyfill-mbstring/zipball/4d5d331bf731e0aedbc6b2f5e61135b7b7996a72",
5292:                 "reference": "4d5d331bf731e0aedbc6b2f5e61135b7b7996a72",
5293:                 "shasum": ""
5294:             },
5295:             "require": {
5296:                 "ext-iconv": "*",
5297:                 "php": ">=7.2"
5298:             },
5299:             "provide": {
5300:                 "ext-mbstring": "*"
5301:             },
5302:             "suggest": {
5303:                 "ext-mbstring": "For best performance"
5304:             },
5305:             "type": "library",
5306:             "extra": {
5307:                 "thanks": {
5308:                     "url": "https://github.com/symfony/polyfill",
5309:                     "name": "symfony/polyfill"
5310:                 }
5311:             },
5312:             "autoload": {
5313:                 "files": [
5314:                     "bootstrap.php"
5315:                 ],
5316:                 "psr-4": {
5317:                     "Symfony\\Polyfill\\Mbstring\\": ""
5318:                 }
5319:             },
5320:             "notification-url": "https://packagist.org/downloads/",
5321:             "license": [
5322:                 "MIT"
5323:             ],
5324:             "authors": [
5325:                 {
5326:                     "name": "Nicolas Grekas",
5327:                     "email": "p@tchwork.com"
5328:                 },
5329:                 {
5330:                     "name": "Symfony Community",
5331:                     "homepage": "https://symfony.com/contributors"
5332:                 }
5333:             ],
5334:             "description": "Symfony polyfill for the Mbstring extension",
5335:             "homepage": "https://symfony.com",
5336:             "keywords": [
5337:                 "compatibility",
5338:                 "mbstring",
5339:                 "polyfill",
5340:                 "portable",
5341:                 "shim"
5342:             ],
5343:             "support": {
5344:                 "source": "https://github.com/symfony/polyfill-mbstring/tree/v1.43.0"
5345:             },
5346:             "funding": [
5347:                 {
5348:                     "url": "https://symfony.com/sponsor",
5349:                     "type": "custom"
5350:                 },
5351:                 {
5352:                     "url": "https://github.com/fabpot",
5353:                     "type": "github"
5354:                 },
5355:                 {
5356:                     "url": "https://github.com/nicolas-grekas",
5357:                     "type": "github"
5358:                 },
5359:                 {
5360:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5361:                     "type": "tidelift"
5362:                 }
5363:             ],
5364:             "time": "2026-09-24T15:19:04+00:00"
5365:         },
5366:         {
5367:             "name": "symfony/polyfill-php80",
5368:             "version": "v1.43.0",
5369:             "source": {
5370:                 "type": "git",
5371:                 "url": "https://github.com/symfony/polyfill-php80.git",
5372:                 "reference": "9c6a5d6b01ca51d486c813e9a9c0ed55f208bb74"
5373:             },
5374:             "dist": {
5375:                 "type": "zip",
5376:                 "url": "https://api.github.com/repos/symfony/polyfill-php80/zipball/9c6a5d6b01ca51d486c813e9a9c0ed55f208bb74",
5377:                 "reference": "9c6a5d6b01ca51d486c813e9a9c0ed55f208bb74",
5378:                 "shasum": ""
5379:             },
5380:             "require": {
5381:                 "php": ">=7.2"
5382:             },
5383:             "type": "library",
5384:             "extra": {
5385:                 "thanks": {
5386:                     "url": "https://github.com/symfony/polyfill",
5387:                     "name": "symfony/polyfill"
5388:                 }
5389:             },
5390:             "autoload": {
5391:                 "files": [
5392:                     "bootstrap.php"
5393:                 ],
5394:                 "psr-4": {
5395:                     "Symfony\\Polyfill\\Php80\\": ""
5396:                 },
5397:                 "classmap": [
5398:                     "Resources/stubs"
5399:                 ]
5400:             },
5401:             "notification-url": "https://packagist.org/downloads/",
5402:             "license": [
5403:                 "MIT"
5404:             ],
5405:             "authors": [
5406:                 {
5407:                     "name": "Ion Bazan",
5408:                     "email": "ion.bazan@gmail.com"
5409:                 },
5410:                 {
5411:                     "name": "Nicolas Grekas",
5412:                     "email": "p@tchwork.com"
5413:                 },
5414:                 {
5415:                     "name": "Symfony Community",
5416:                     "homepage": "https://symfony.com/contributors"
5417:                 }
5418:             ],
5419:             "description": "Symfony polyfill backporting some PHP 8.0+ features to lower PHP versions",
5420:             "homepage": "https://symfony.com",
5421:             "keywords": [
5422:                 "compatibility",
5423:                 "polyfill",
5424:                 "portable",
5425:                 "shim"
5426:             ],
5427:             "support": {
5428:                 "source": "https://github.com/symfony/polyfill-php80/tree/v1.43.0"
5429:             },
5430:             "funding": [
5431:                 {
5432:                     "url": "https://symfony.com/sponsor",
5433:                     "type": "custom"
5434:                 },
5435:                 {
5436:                     "url": "https://github.com/fabpot",
5437:                     "type": "github"
5438:                 },
5439:                 {
5440:                     "url": "https://github.com/nicolas-grekas",
5441:                     "type": "github"
5442:                 },
5443:                 {
5444:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5445:                     "type": "tidelift"
5446:                 }
5447:             ],
5448:             "time": "2026-09-25T15:50:05+00:00"
5449:         },
5450:         {
5451:             "name": "symfony/polyfill-php82",
5452:             "version": "v1.43.0",
5453:             "source": {
5454:                 "type": "git",
5455:                 "url": "https://github.com/symfony/polyfill-php82.git",
5456:                 "reference": "843bbe8b2a7934307b749751eede0f111f5d1cfc"
5457:             },
5458:             "dist": {
5459:                 "type": "zip",
5460:                 "url": "https://api.github.com/repos/symfony/polyfill-php82/zipball/843bbe8b2a7934307b749751eede0f111f5d1cfc",
5461:                 "reference": "843bbe8b2a7934307b749751eede0f111f5d1cfc",
5462:                 "shasum": ""
5463:             },
5464:             "require": {
5465:                 "php": ">=7.2"
5466:             },
5467:             "type": "library",
5468:             "extra": {
5469:                 "thanks": {
5470:                     "url": "https://github.com/symfony/polyfill",
5471:                     "name": "symfony/polyfill"
5472:                 }
5473:             },
5474:             "autoload": {
5475:                 "files": [
5476:                     "bootstrap.php"
5477:                 ],
5478:                 "psr-4": {
5479:                     "Symfony\\Polyfill\\Php82\\": ""
5480:                 },
5481:                 "classmap": [
5482:                     "Resources/stubs"
5483:                 ]
5484:             },
5485:             "notification-url": "https://packagist.org/downloads/",
5486:             "license": [
5487:                 "MIT"
5488:             ],
5489:             "authors": [
5490:                 {
5491:                     "name": "Nicolas Grekas",
5492:                     "email": "p@tchwork.com"
5493:                 },
5494:                 {
5495:                     "name": "Symfony Community",
5496:                     "homepage": "https://symfony.com/contributors"
5497:                 }
5498:             ],
5499:             "description": "Symfony polyfill backporting some PHP 8.2+ features to lower PHP versions",
5500:             "homepage": "https://symfony.com",
5501:             "keywords": [
5502:                 "compatibility",
5503:                 "polyfill",
5504:                 "portable",
5505:                 "shim"
5506:             ],
5507:             "support": {
5508:                 "source": "https://github.com/symfony/polyfill-php82/tree/v1.43.0"
5509:             },
5510:             "funding": [
5511:                 {
5512:                     "url": "https://symfony.com/sponsor",
5513:                     "type": "custom"
5514:                 },
5515:                 {
5516:                     "url": "https://github.com/fabpot",
5517:                     "type": "github"
5518:                 },
5519:                 {
5520:                     "url": "https://github.com/nicolas-grekas",
5521:                     "type": "github"
5522:                 },
5523:                 {
5524:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5525:                     "type": "tidelift"
5526:                 }
5527:             ],
5528:             "time": "2026-09-25T15:50:05+00:00"
5529:         },
5530:         {
5531:             "name": "symfony/polyfill-php83",
5532:             "version": "v1.43.0",
5533:             "source": {
5534:                 "type": "git",
5535:                 "url": "https://github.com/symfony/polyfill-php83.git",
5536:                 "reference": "80ccff923a8d61f73ebe9da0aa94e25232f004ce"
5537:             },
5538:             "dist": {
5539:                 "type": "zip",
5540:                 "url": "https://api.github.com/repos/symfony/polyfill-php83/zipball/80ccff923a8d61f73ebe9da0aa94e25232f004ce",
5541:                 "reference": "80ccff923a8d61f73ebe9da0aa94e25232f004ce",
5542:                 "shasum": ""
5543:             },
5544:             "require": {
5545:                 "php": ">=7.2"
5546:             },
5547:             "type": "library",
5548:             "extra": {
5549:                 "thanks": {
5550:                     "url": "https://github.com/symfony/polyfill",
5551:                     "name": "symfony/polyfill"
5552:                 }
5553:             },
5554:             "autoload": {
5555:                 "files": [
5556:                     "bootstrap.php"
5557:                 ],
5558:                 "psr-4": {
5559:                     "Symfony\\Polyfill\\Php83\\": ""
5560:                 },
5561:                 "classmap": [
5562:                     "Resources/stubs"
5563:                 ]
5564:             },
5565:             "notification-url": "https://packagist.org/downloads/",
5566:             "license": [
5567:                 "MIT"
5568:             ],
5569:             "authors": [
5570:                 {
5571:                     "name": "Nicolas Grekas",
5572:                     "email": "p@tchwork.com"
5573:                 },
5574:                 {
5575:                     "name": "Symfony Community",
5576:                     "homepage": "https://symfony.com/contributors"
5577:                 }
5578:             ],
5579:             "description": "Symfony polyfill backporting some PHP 8.3+ features to lower PHP versions",
5580:             "homepage": "https://symfony.com",
5581:             "keywords": [
5582:                 "compatibility",
5583:                 "polyfill",
5584:                 "portable",
5585:                 "shim"
5586:             ],
5587:             "support": {
5588:                 "source": "https://github.com/symfony/polyfill-php83/tree/v1.43.0"
5589:             },
5590:             "funding": [
5591:                 {
5592:                     "url": "https://symfony.com/sponsor",
5593:                     "type": "custom"
5594:                 },
5595:                 {
5596:                     "url": "https://github.com/fabpot",
5597:                     "type": "github"
5598:                 },
5599:                 {
5600:                     "url": "https://github.com/nicolas-grekas",
5601:                     "type": "github"
5602:                 },
5603:                 {
5604:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5605:                     "type": "tidelift"
5606:                 }
5607:             ],
5608:             "time": "2026-09-25T15:50:05+00:00"
5609:         },
5610:         {
5611:             "name": "symfony/polyfill-php84",
5612:             "version": "v1.43.0",
5613:             "source": {
5614:                 "type": "git",
5615:                 "url": "https://github.com/symfony/polyfill-php84.git",
5616:                 "reference": "22c6548499cbe5c5bb072fc0c42312dff062a015"
5617:             },
5618:             "dist": {
5619:                 "type": "zip",
5620:                 "url": "https://api.github.com/repos/symfony/polyfill-php84/zipball/22c6548499cbe5c5bb072fc0c42312dff062a015",
5621:                 "reference": "22c6548499cbe5c5bb072fc0c42312dff062a015",
5622:                 "shasum": ""
5623:             },
5624:             "require": {
5625:                 "php": ">=7.2"
5626:             },
5627:             "type": "library",
5628:             "extra": {
5629:                 "thanks": {
5630:                     "url": "https://github.com/symfony/polyfill",
5631:                     "name": "symfony/polyfill"
5632:                 }
5633:             },
5634:             "autoload": {
5635:                 "files": [
5636:                     "bootstrap.php"
5637:                 ],
5638:                 "psr-4": {
5639:                     "Symfony\\Polyfill\\Php84\\": ""
5640:                 },
5641:                 "classmap": [
5642:                     "Resources/stubs"
5643:                 ]
5644:             },
5645:             "notification-url": "https://packagist.org/downloads/",
5646:             "license": [
5647:                 "MIT"
5648:             ],
5649:             "authors": [
5650:                 {
5651:                     "name": "Nicolas Grekas",
5652:                     "email": "p@tchwork.com"
5653:                 },
5654:                 {
5655:                     "name": "Symfony Community",
5656:                     "homepage": "https://symfony.com/contributors"
5657:                 }
5658:             ],
5659:             "description": "Symfony polyfill backporting some PHP 8.4+ features to lower PHP versions",
5660:             "homepage": "https://symfony.com",
5661:             "keywords": [
5662:                 "compatibility",
5663:                 "polyfill",
5664:                 "portable",
5665:                 "shim"
5666:             ],
5667:             "support": {
5668:                 "source": "https://github.com/symfony/polyfill-php84/tree/v1.43.0"
5669:             },
5670:             "funding": [
5671:                 {
5672:                     "url": "https://symfony.com/sponsor",
5673:                     "type": "custom"
5674:                 },
5675:                 {
5676:                     "url": "https://github.com/fabpot",
5677:                     "type": "github"
5678:                 },
5679:                 {
5680:                     "url": "https://github.com/nicolas-grekas",
5681:                     "type": "github"
5682:                 },
5683:                 {
5684:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5685:                     "type": "tidelift"
5686:                 }
5687:             ],
5688:             "time": "2026-09-23T22:01:09+00:00"
5689:         },
5690:         {
5691:             "name": "symfony/polyfill-php85",
5692:             "version": "v1.43.0",
5693:             "source": {
5694:                 "type": "git",
5695:                 "url": "https://github.com/symfony/polyfill-php85.git",
5696:                 "reference": "86a42cbcc07adc331481a5ab310a4024bfc227f6"
5697:             },
5698:             "dist": {
5699:                 "type": "zip",
5700:                 "url": "https://api.github.com/repos/symfony/polyfill-php85/zipball/86a42cbcc07adc331481a5ab310a4024bfc227f6",
5701:                 "reference": "86a42cbcc07adc331481a5ab310a4024bfc227f6",
5702:                 "shasum": ""
5703:             },
5704:             "require": {
5705:                 "php": ">=7.2"
5706:             },
5707:             "type": "library",
5708:             "extra": {
5709:                 "thanks": {
5710:                     "url": "https://github.com/symfony/polyfill",
5711:                     "name": "symfony/polyfill"
5712:                 }
5713:             },
5714:             "autoload": {
5715:                 "files": [
5716:                     "bootstrap.php"
5717:                 ],
5718:                 "psr-4": {
5719:                     "Symfony\\Polyfill\\Php85\\": ""
5720:                 },
5721:                 "classmap": [
5722:                     "Resources/stubs"
5723:                 ]
5724:             },
5725:             "notification-url": "https://packagist.org/downloads/",
5726:             "license": [
5727:                 "MIT"
5728:             ],
5729:             "authors": [
5730:                 {
5731:                     "name": "Nicolas Grekas",
5732:                     "email": "p@tchwork.com"
5733:                 },
5734:                 {
5735:                     "name": "Symfony Community",
5736:                     "homepage": "https://symfony.com/contributors"
5737:                 }
5738:             ],
5739:             "description": "Symfony polyfill backporting some PHP 8.5+ features to lower PHP versions",
5740:             "homepage": "https://symfony.com",
5741:             "keywords": [
5742:                 "compatibility",
5743:                 "polyfill",
5744:                 "portable",
5745:                 "shim"
5746:             ],
5747:             "support": {
5748:                 "source": "https://github.com/symfony/polyfill-php85/tree/v1.43.0"
5749:             },
5750:             "funding": [
5751:                 {
5752:                     "url": "https://symfony.com/sponsor",
5753:                     "type": "custom"
5754:                 },
5755:                 {
5756:                     "url": "https://github.com/fabpot",
5757:                     "type": "github"
5758:                 },
5759:                 {
5760:                     "url": "https://github.com/nicolas-grekas",
5761:                     "type": "github"
5762:                 },
5763:                 {
5764:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5765:                     "type": "tidelift"
5766:                 }
5767:             ],
5768:             "time": "2026-09-25T15:46:47+00:00"
5769:         },
5770:         {
5771:             "name": "symfony/polyfill-php86",
5772:             "version": "v1.43.0",
5773:             "source": {
5774:                 "type": "git",
5775:                 "url": "https://github.com/symfony/polyfill-php86.git",
5776:                 "reference": "8840df45b24f33a49dc419fa18dc0057231ae1d8"
5777:             },
5778:             "dist": {
5779:                 "type": "zip",
5780:                 "url": "https://api.github.com/repos/symfony/polyfill-php86/zipball/8840df45b24f33a49dc419fa18dc0057231ae1d8",
5781:                 "reference": "8840df45b24f33a49dc419fa18dc0057231ae1d8",
5782:                 "shasum": ""
5783:             },
5784:             "require": {
5785:                 "php": ">=7.2"
5786:             },
5787:             "type": "library",
5788:             "extra": {
5789:                 "thanks": {
5790:                     "url": "https://github.com/symfony/polyfill",
5791:                     "name": "symfony/polyfill"
5792:                 }
5793:             },
5794:             "autoload": {
5795:                 "files": [
5796:                     "bootstrap.php"
5797:                 ],
5798:                 "psr-4": {
5799:                     "Symfony\\Polyfill\\Php86\\": ""
5800:                 },
5801:                 "classmap": [
5802:                     "Resources/stubs"
5803:                 ]
5804:             },
5805:             "notification-url": "https://packagist.org/downloads/",
5806:             "license": [
5807:                 "MIT"
5808:             ],
5809:             "authors": [
5810:                 {
5811:                     "name": "Nicolas Grekas",
5812:                     "email": "p@tchwork.com"
5813:                 },
5814:                 {
5815:                     "name": "Symfony Community",
5816:                     "homepage": "https://symfony.com/contributors"
5817:                 }
5818:             ],
5819:             "description": "Symfony polyfill backporting some PHP 8.6+ features to lower PHP versions",
5820:             "homepage": "https://symfony.com",
5821:             "keywords": [
5822:                 "compatibility",
5823:                 "polyfill",
5824:                 "portable",
5825:                 "shim"
5826:             ],
5827:             "support": {
5828:                 "source": "https://github.com/symfony/polyfill-php86/tree/v1.43.0"
5829:             },
5830:             "funding": [
5831:                 {
5832:                     "url": "https://symfony.com/sponsor",
5833:                     "type": "custom"
5834:                 },
5835:                 {
5836:                     "url": "https://github.com/fabpot",
5837:                     "type": "github"
5838:                 },
5839:                 {
5840:                     "url": "https://github.com/nicolas-grekas",
5841:                     "type": "github"
5842:                 },
5843:                 {
5844:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5845:                     "type": "tidelift"
5846:                 }
5847:             ],
5848:             "time": "2026-09-25T15:46:47+00:00"
5849:         },
5850:         {
5851:             "name": "symfony/polyfill-uuid",
5852:             "version": "v1.43.0",
5853:             "source": {
5854:                 "type": "git",
5855:                 "url": "https://github.com/symfony/polyfill-uuid.git",
5856:                 "reference": "b23ff8245cc5c4479ea2be3d0132d2e6dd433993"
5857:             },
5858:             "dist": {
5859:                 "type": "zip",
5860:                 "url": "https://api.github.com/repos/symfony/polyfill-uuid/zipball/b23ff8245cc5c4479ea2be3d0132d2e6dd433993",
5861:                 "reference": "b23ff8245cc5c4479ea2be3d0132d2e6dd433993",
5862:                 "shasum": ""
5863:             },
5864:             "require": {
5865:                 "php": ">=7.2"
5866:             },
5867:             "provide": {
5868:                 "ext-uuid": "*"
5869:             },
5870:             "suggest": {
5871:                 "ext-uuid": "For best performance"
5872:             },
5873:             "type": "library",
5874:             "extra": {
5875:                 "thanks": {
5876:                     "url": "https://github.com/symfony/polyfill",
5877:                     "name": "symfony/polyfill"
5878:                 }
5879:             },
5880:             "autoload": {
5881:                 "files": [
5882:                     "bootstrap.php"
5883:                 ],
5884:                 "psr-4": {
5885:                     "Symfony\\Polyfill\\Uuid\\": ""
5886:                 }
5887:             },
5888:             "notification-url": "https://packagist.org/downloads/",
5889:             "license": [
5890:                 "MIT"
5891:             ],
5892:             "authors": [
5893:                 {
5894:                     "name": "Grégoire Pineau",
5895:                     "email": "lyrixx@lyrixx.info"
5896:                 },
5897:                 {
5898:                     "name": "Symfony Community",
5899:                     "homepage": "https://symfony.com/contributors"
5900:                 }
5901:             ],
5902:             "description": "Symfony polyfill for uuid functions",
5903:             "homepage": "https://symfony.com",
5904:             "keywords": [
5905:                 "compatibility",
5906:                 "polyfill",
5907:                 "portable",
5908:                 "uuid"
5909:             ],
5910:             "support": {
5911:                 "source": "https://github.com/symfony/polyfill-uuid/tree/v1.43.0"
5912:             },
5913:             "funding": [
5914:                 {
5915:                     "url": "https://symfony.com/sponsor",
5916:                     "type": "custom"
5917:                 },
5918:                 {
5919:                     "url": "https://github.com/fabpot",
5920:                     "type": "github"
5921:                 },
5922:                 {
5923:                     "url": "https://github.com/nicolas-grekas",
5924:                     "type": "github"
5925:                 },
5926:                 {
5927:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5928:                     "type": "tidelift"
5929:                 }
5930:             ],
5931:             "time": "2026-09-24T05:20:52+00:00"
5932:         },
5933:         {
5934:             "name": "symfony/process",
5935:             "version": "v7.4.21",
5936:             "source": {
5937:                 "type": "git",
5938:                 "url": "https://github.com/symfony/process.git",
5939:                 "reference": "e5247f4a5e0797d232ad8e8e6803aeb341843c83"
5940:             },
5941:             "dist": {
5942:                 "type": "zip",
5943:                 "url": "https://api.github.com/repos/symfony/process/zipball/e5247f4a5e0797d232ad8e8e6803aeb341843c83",
5944:                 "reference": "e5247f4a5e0797d232ad8e8e6803aeb341843c83",
5945:                 "shasum": ""
5946:             },
5947:             "require": {
5948:                 "php": ">=8.2"
5949:             },
5950:             "type": "library",
5951:             "autoload": {
5952:                 "psr-4": {
5953:                     "Symfony\\Component\\Process\\": ""
5954:                 },
5955:                 "exclude-from-classmap": [
5956:                     "/Tests/"
5957:                 ]
5958:             },
5959:             "notification-url": "https://packagist.org/downloads/",
5960:             "license": [
5961:                 "MIT"
5962:             ],
5963:             "authors": [
5964:                 {
5965:                     "name": "Fabien Potencier",
5966:                     "email": "fabien@symfony.com"
5967:                 },
5968:                 {
5969:                     "name": "Symfony Community",
5970:                     "homepage": "https://symfony.com/contributors"
5971:                 }
5972:             ],
5973:             "description": "Executes commands in sub-processes",
5974:             "homepage": "https://symfony.com",
5975:             "support": {
5976:                 "source": "https://github.com/symfony/process/tree/v7.4.21"
5977:             },
5978:             "funding": [
5979:                 {
5980:                     "url": "https://symfony.com/sponsor",
5981:                     "type": "custom"
5982:                 },
5983:                 {
5984:                     "url": "https://github.com/fabpot",
5985:                     "type": "github"
5986:                 },
5987:                 {
5988:                     "url": "https://github.com/nicolas-grekas",
5989:                     "type": "github"
5990:                 },
5991:                 {
5992:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
5993:                     "type": "tidelift"
5994:                 }
5995:             ],
5996:             "time": "2026-10-08T16:56:12+00:00"
5997:         },
5998:         {
5999:             "name": "symfony/routing",
6000:             "version": "v7.4.21",
6001:             "source": {
6002:                 "type": "git",
6003:                 "url": "https://github.com/symfony/routing.git",
6004:                 "reference": "7a344cf871532d081875bfdd18f3dff8dca36c5b"
6005:             },
6006:             "dist": {
6007:                 "type": "zip",
6008:                 "url": "https://api.github.com/repos/symfony/routing/zipball/7a344cf871532d081875bfdd18f3dff8dca36c5b",
6009:                 "reference": "7a344cf871532d081875bfdd18f3dff8dca36c5b",
6010:                 "shasum": ""
6011:             },
6012:             "require": {
6013:                 "php": ">=8.2",
6014:                 "symfony/deprecation-contracts": "^2.5|^3"
6015:             },
6016:             "conflict": {
6017:                 "symfony/config": "<6.4",
6018:                 "symfony/dependency-injection": "<6.4",
6019:                 "symfony/yaml": "<6.4"
6020:             },
6021:             "require-dev": {
6022:                 "psr/log": "^1|^2|^3",
6023:                 "symfony/config": "^6.4|^7.0|^8.0",
6024:                 "symfony/dependency-injection": "^6.4|^7.0|^8.0",
6025:                 "symfony/expression-language": "^6.4|^7.0|^8.0",
6026:                 "symfony/http-foundation": "^6.4|^7.0|^8.0",
6027:                 "symfony/yaml": "^6.4|^7.0|^8.0"
6028:             },
6029:             "type": "library",
6030:             "autoload": {
6031:                 "psr-4": {
6032:                     "Symfony\\Component\\Routing\\": ""
6033:                 },
6034:                 "exclude-from-classmap": [
6035:                     "/Tests/"
6036:                 ]
6037:             },
6038:             "notification-url": "https://packagist.org/downloads/",
6039:             "license": [
6040:                 "MIT"
6041:             ],
6042:             "authors": [
6043:                 {
6044:                     "name": "Fabien Potencier",
6045:                     "email": "fabien@symfony.com"
6046:                 },
6047:                 {
6048:                     "name": "Symfony Community",
6049:                     "homepage": "https://symfony.com/contributors"
6050:                 }
6051:             ],
6052:             "description": "Maps an HTTP request to a set of configuration variables",
6053:             "homepage": "https://symfony.com",
6054:             "keywords": [
6055:                 "router",
6056:                 "routing",
6057:                 "uri",
6058:                 "url"
6059:             ],
6060:             "support": {
6061:                 "source": "https://github.com/symfony/routing/tree/v7.4.21"
6062:             },
6063:             "funding": [
6064:                 {
6065:                     "url": "https://symfony.com/sponsor",
6066:                     "type": "custom"
6067:                 },
6068:                 {
6069:                     "url": "https://github.com/fabpot",
6070:                     "type": "github"
6071:                 },
6072:                 {
6073:                     "url": "https://github.com/nicolas-grekas",
6074:                     "type": "github"
6075:                 },
6076:                 {
6077:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6078:                     "type": "tidelift"
6079:                 }
6080:             ],
6081:             "time": "2026-10-11T18:40:59+00:00"
6082:         },
6083:         {
6084:             "name": "symfony/service-contracts",
6085:             "version": "v3.7.3",
6086:             "source": {
6087:                 "type": "git",
6088:                 "url": "https://github.com/symfony/service-contracts.git",
6089:                 "reference": "15e6a07ec2a2c75ceb1b21dd98105ee8456d2257"
6090:             },
6091:             "dist": {
6092:                 "type": "zip",
6093:                 "url": "https://api.github.com/repos/symfony/service-contracts/zipball/15e6a07ec2a2c75ceb1b21dd98105ee8456d2257",
6094:                 "reference": "15e6a07ec2a2c75ceb1b21dd98105ee8456d2257",
6095:                 "shasum": ""
6096:             },
6097:             "require": {
6098:                 "php": ">=8.1",
6099:                 "psr/container": "^1.1|^2.0",
6100:                 "symfony/deprecation-contracts": "^2.5|^3"
6101:             },
6102:             "conflict": {
6103:                 "ext-psr": "<1.1|>=2"
6104:             },
6105:             "type": "library",
6106:             "extra": {
6107:                 "thanks": {
6108:                     "url": "https://github.com/symfony/contracts",
6109:                     "name": "symfony/contracts"
6110:                 },
6111:                 "branch-alias": {
6112:                     "dev-main": "3.7-dev"
6113:                 }
6114:             },
6115:             "autoload": {
6116:                 "psr-4": {
6117:                     "Symfony\\Contracts\\Service\\": ""
6118:                 },
6119:                 "exclude-from-classmap": [
6120:                     "/Test/"
6121:                 ]
6122:             },
6123:             "notification-url": "https://packagist.org/downloads/",
6124:             "license": [
6125:                 "MIT"
6126:             ],
6127:             "authors": [
6128:                 {
6129:                     "name": "Nicolas Grekas",
6130:                     "email": "p@tchwork.com"
6131:                 },
6132:                 {
6133:                     "name": "Symfony Community",
6134:                     "homepage": "https://symfony.com/contributors"
6135:                 }
6136:             ],
6137:             "description": "Generic abstractions related to writing services",
6138:             "homepage": "https://symfony.com",
6139:             "keywords": [
6140:                 "abstractions",
6141:                 "contracts",
6142:                 "decoupling",
6143:                 "interfaces",
6144:                 "interoperability",
6145:                 "standards"
6146:             ],
6147:             "support": {
6148:                 "source": "https://github.com/symfony/service-contracts/tree/v3.7.3"
6149:             },
6150:             "funding": [
6151:                 {
6152:                     "url": "https://symfony.com/sponsor",
6153:                     "type": "custom"
6154:                 },
6155:                 {
6156:                     "url": "https://github.com/fabpot",
6157:                     "type": "github"
6158:                 },
6159:                 {
6160:                     "url": "https://github.com/nicolas-grekas",
6161:                     "type": "github"
6162:                 },
6163:                 {
6164:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6165:                     "type": "tidelift"
6166:                 }
6167:             ],
6168:             "time": "2026-07-27T15:39:01+00:00"
6169:         },
6170:         {
6171:             "name": "symfony/string",
6172:             "version": "v7.4.21",
6173:             "source": {
6174:                 "type": "git",
6175:                 "url": "https://github.com/symfony/string.git",
6176:                 "reference": "9bc35d074ad16a58c21cd52b18c5cacc4f4c3981"
6177:             },
6178:             "dist": {
6179:                 "type": "zip",
6180:                 "url": "https://api.github.com/repos/symfony/string/zipball/9bc35d074ad16a58c21cd52b18c5cacc4f4c3981",
6181:                 "reference": "9bc35d074ad16a58c21cd52b18c5cacc4f4c3981",
6182:                 "shasum": ""
6183:             },
6184:             "require": {
6185:                 "php": ">=8.2",
6186:                 "symfony/deprecation-contracts": "^2.5|^3.0",
6187:                 "symfony/polyfill-ctype": "~1.8",
6188:                 "symfony/polyfill-intl-grapheme": "~1.33",
6189:                 "symfony/polyfill-intl-normalizer": "~1.0",
6190:                 "symfony/polyfill-mbstring": "~1.0"
6191:             },
6192:             "conflict": {
6193:                 "symfony/translation-contracts": "<2.5"
6194:             },
6195:             "require-dev": {
6196:                 "symfony/emoji": "^7.1|^8.0",
6197:                 "symfony/http-client": "^6.4|^7.0|^8.0",
6198:                 "symfony/intl": "^6.4|^7.0|^8.0",
6199:                 "symfony/translation-contracts": "^2.5|^3.0",
6200:                 "symfony/var-exporter": "^6.4|^7.0|^8.0"
6201:             },
6202:             "type": "library",
6203:             "autoload": {
6204:                 "files": [
6205:                     "Resources/functions.php"
6206:                 ],
6207:                 "psr-4": {
6208:                     "Symfony\\Component\\String\\": ""
6209:                 },
6210:                 "exclude-from-classmap": [
6211:                     "/Tests/"
6212:                 ]
6213:             },
6214:             "notification-url": "https://packagist.org/downloads/",
6215:             "license": [
6216:                 "MIT"
6217:             ],
6218:             "authors": [
6219:                 {
6220:                     "name": "Nicolas Grekas",
6221:                     "email": "p@tchwork.com"
6222:                 },
6223:                 {
6224:                     "name": "Symfony Community",
6225:                     "homepage": "https://symfony.com/contributors"
6226:                 }
6227:             ],
6228:             "description": "Provides an object-oriented API to strings and deals with bytes, UTF-8 code points and grapheme clusters in a unified way",
6229:             "homepage": "https://symfony.com",
6230:             "keywords": [
6231:                 "grapheme",
6232:                 "i18n",
6233:                 "string",
6234:                 "unicode",
6235:                 "utf-8",
6236:                 "utf8"
6237:             ],
6238:             "support": {
6239:                 "source": "https://github.com/symfony/string/tree/v7.4.21"
6240:             },
6241:             "funding": [
6242:                 {
6243:                     "url": "https://symfony.com/sponsor",
6244:                     "type": "custom"
6245:                 },
6246:                 {
6247:                     "url": "https://github.com/fabpot",
6248:                     "type": "github"
6249:                 },
6250:                 {
6251:                     "url": "https://github.com/nicolas-grekas",
6252:                     "type": "github"
6253:                 },
6254:                 {
6255:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6256:                     "type": "tidelift"
6257:                 }
6258:             ],
6259:             "time": "2026-10-03T15:15:30+00:00"
6260:         },
6261:         {
6262:             "name": "symfony/translation",
6263:             "version": "v7.4.17",
6264:             "source": {
6265:                 "type": "git",
6266:                 "url": "https://github.com/symfony/translation.git",
6267:                 "reference": "2ee1e4a3b32a528a642babe041ff7c440b213b4b"
6268:             },
6269:             "dist": {
6270:                 "type": "zip",
6271:                 "url": "https://api.github.com/repos/symfony/translation/zipball/2ee1e4a3b32a528a642babe041ff7c440b213b4b",
6272:                 "reference": "2ee1e4a3b32a528a642babe041ff7c440b213b4b",
6273:                 "shasum": ""
6274:             },
6275:             "require": {
6276:                 "php": ">=8.2",
6277:                 "symfony/deprecation-contracts": "^2.5|^3",
6278:                 "symfony/polyfill-mbstring": "~1.0",
6279:                 "symfony/translation-contracts": "^2.5.3|^3.3"
6280:             },
6281:             "conflict": {
6282:                 "nikic/php-parser": "<5.0",
6283:                 "symfony/config": "<6.4",
6284:                 "symfony/console": "<6.4",
6285:                 "symfony/dependency-injection": "<6.4",
6286:                 "symfony/http-client-contracts": "<2.5",
6287:                 "symfony/http-kernel": "<6.4",
6288:                 "symfony/service-contracts": "<2.5",
6289:                 "symfony/twig-bundle": "<6.4",
6290:                 "symfony/yaml": "<6.4"
6291:             },
6292:             "provide": {
6293:                 "symfony/translation-implementation": "2.3|3.0"
6294:             },
6295:             "require-dev": {
6296:                 "nikic/php-parser": "^5.0",
6297:                 "psr/log": "^1|^2|^3",
6298:                 "symfony/config": "^6.4|^7.0|^8.0",
6299:                 "symfony/console": "^6.4|^7.0|^8.0",
6300:                 "symfony/dependency-injection": "^6.4|^7.0|^8.0",
6301:                 "symfony/finder": "^6.4|^7.0|^8.0",
6302:                 "symfony/http-client-contracts": "^2.5|^3.0",
6303:                 "symfony/http-kernel": "^6.4|^7.0|^8.0",
6304:                 "symfony/intl": "^6.4|^7.0|^8.0",
6305:                 "symfony/polyfill-intl-icu": "^1.21",
6306:                 "symfony/routing": "^6.4|^7.0|^8.0",
6307:                 "symfony/service-contracts": "^2.5|^3",
6308:                 "symfony/yaml": "^6.4|^7.0|^8.0"
6309:             },
6310:             "type": "library",
6311:             "autoload": {
6312:                 "files": [
6313:                     "Resources/functions.php"
6314:                 ],
6315:                 "psr-4": {
6316:                     "Symfony\\Component\\Translation\\": ""
6317:                 },
6318:                 "exclude-from-classmap": [
6319:                     "/Tests/"
6320:                 ]
6321:             },
6322:             "notification-url": "https://packagist.org/downloads/",
6323:             "license": [
6324:                 "MIT"
6325:             ],
6326:             "authors": [
6327:                 {
6328:                     "name": "Fabien Potencier",
6329:                     "email": "fabien@symfony.com"
6330:                 },
6331:                 {
6332:                     "name": "Symfony Community",
6333:                     "homepage": "https://symfony.com/contributors"
6334:                 }
6335:             ],
6336:             "description": "Provides tools to internationalize your application",
6337:             "homepage": "https://symfony.com",
6338:             "support": {
6339:                 "source": "https://github.com/symfony/translation/tree/v7.4.17"
6340:             },
6341:             "funding": [
6342:                 {
6343:                     "url": "https://symfony.com/sponsor",
6344:                     "type": "custom"
6345:                 },
6346:                 {
6347:                     "url": "https://github.com/fabpot",
6348:                     "type": "github"
6349:                 },
6350:                 {
6351:                     "url": "https://github.com/nicolas-grekas",
6352:                     "type": "github"
6353:                 },
6354:                 {
6355:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6356:                     "type": "tidelift"
6357:                 }
6358:             ],
6359:             "time": "2026-08-21T17:40:08+00:00"
6360:         },
6361:         {
6362:             "name": "symfony/translation-contracts",
6363:             "version": "v3.7.1",
6364:             "source": {
6365:                 "type": "git",
6366:                 "url": "https://github.com/symfony/translation-contracts.git",
6367:                 "reference": "ccb206b98faccc511ebae8e5fad50f2dc0b30621"
6368:             },
6369:             "dist": {
6370:                 "type": "zip",
6371:                 "url": "https://api.github.com/repos/symfony/translation-contracts/zipball/ccb206b98faccc511ebae8e5fad50f2dc0b30621",
6372:                 "reference": "ccb206b98faccc511ebae8e5fad50f2dc0b30621",
6373:                 "shasum": ""
6374:             },
6375:             "require": {
6376:                 "php": ">=8.1"
6377:             },
6378:             "type": "library",
6379:             "extra": {
6380:                 "thanks": {
6381:                     "url": "https://github.com/symfony/contracts",
6382:                     "name": "symfony/contracts"
6383:                 },
6384:                 "branch-alias": {
6385:                     "dev-main": "3.7-dev"
6386:                 }
6387:             },
6388:             "autoload": {
6389:                 "psr-4": {
6390:                     "Symfony\\Contracts\\Translation\\": ""
6391:                 },
6392:                 "exclude-from-classmap": [
6393:                     "/Test/"
6394:                 ]
6395:             },
6396:             "notification-url": "https://packagist.org/downloads/",
6397:             "license": [
6398:                 "MIT"
6399:             ],
6400:             "authors": [
6401:                 {
6402:                     "name": "Nicolas Grekas",
6403:                     "email": "p@tchwork.com"
6404:                 },
6405:                 {
6406:                     "name": "Symfony Community",
6407:                     "homepage": "https://symfony.com/contributors"
6408:                 }
6409:             ],
6410:             "description": "Generic abstractions related to translation",
6411:             "homepage": "https://symfony.com",
6412:             "keywords": [
6413:                 "abstractions",
6414:                 "contracts",
6415:                 "decoupling",
6416:                 "interfaces",
6417:                 "interoperability",
6418:                 "standards"
6419:             ],
6420:             "support": {
6421:                 "source": "https://github.com/symfony/translation-contracts/tree/v3.7.1"
6422:             },
6423:             "funding": [
6424:                 {
6425:                     "url": "https://symfony.com/sponsor",
6426:                     "type": "custom"
6427:                 },
6428:                 {
6429:                     "url": "https://github.com/fabpot",
6430:                     "type": "github"
6431:                 },
6432:                 {
6433:                     "url": "https://github.com/nicolas-grekas",
6434:                     "type": "github"
6435:                 },
6436:                 {
6437:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6438:                     "type": "tidelift"
6439:                 }
6440:             ],
6441:             "time": "2026-06-05T06:23:12+00:00"
6442:         },
6443:         {
6444:             "name": "symfony/uid",
6445:             "version": "v7.4.21",
6446:             "source": {
6447:                 "type": "git",
6448:                 "url": "https://github.com/symfony/uid.git",
6449:                 "reference": "3e706a245b5343ea93be9cafe84525bfb54e60fc"
6450:             },
6451:             "dist": {
6452:                 "type": "zip",
6453:                 "url": "https://api.github.com/repos/symfony/uid/zipball/3e706a245b5343ea93be9cafe84525bfb54e60fc",
6454:                 "reference": "3e706a245b5343ea93be9cafe84525bfb54e60fc",
6455:                 "shasum": ""
6456:             },
6457:             "require": {
6458:                 "php": ">=8.2",
6459:                 "symfony/polyfill-uuid": "^1.15"
6460:             },
6461:             "require-dev": {
6462:                 "symfony/console": "^6.4|^7.0|^8.0"
6463:             },
6464:             "type": "library",
6465:             "autoload": {
6466:                 "psr-4": {
6467:                     "Symfony\\Component\\Uid\\": ""
6468:                 },
6469:                 "exclude-from-classmap": [
6470:                     "/Tests/"
6471:                 ]
6472:             },
6473:             "notification-url": "https://packagist.org/downloads/",
6474:             "license": [
6475:                 "MIT"
6476:             ],
6477:             "authors": [
6478:                 {
6479:                     "name": "Grégoire Pineau",
6480:                     "email": "lyrixx@lyrixx.info"
6481:                 },
6482:                 {
6483:                     "name": "Nicolas Grekas",
6484:                     "email": "p@tchwork.com"
6485:                 },
6486:                 {
6487:                     "name": "Symfony Community",
6488:                     "homepage": "https://symfony.com/contributors"
6489:                 }
6490:             ],
6491:             "description": "Provides an object-oriented API to generate and represent UIDs",
6492:             "homepage": "https://symfony.com",
6493:             "keywords": [
6494:                 "UID",
6495:                 "ulid",
6496:                 "uuid"
6497:             ],
6498:             "support": {
6499:                 "source": "https://github.com/symfony/uid/tree/v7.4.21"
6500:             },
6501:             "funding": [
6502:                 {
6503:                     "url": "https://symfony.com/sponsor",
6504:                     "type": "custom"
6505:                 },
6506:                 {
6507:                     "url": "https://github.com/fabpot",
6508:                     "type": "github"
6509:                 },
6510:                 {
6511:                     "url": "https://github.com/nicolas-grekas",
6512:                     "type": "github"
6513:                 },
6514:                 {
6515:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6516:                     "type": "tidelift"
6517:                 }
6518:             ],
6519:             "time": "2026-10-12T14:31:36+00:00"
6520:         },
6521:         {
6522:             "name": "symfony/var-dumper",
6523:             "version": "v7.4.21",
6524:             "source": {
6525:                 "type": "git",
6526:                 "url": "https://github.com/symfony/var-dumper.git",
6527:                 "reference": "55d06b02f49eca9bff61828548988e6f8763e8a6"
6528:             },
6529:             "dist": {
6530:                 "type": "zip",
6531:                 "url": "https://api.github.com/repos/symfony/var-dumper/zipball/55d06b02f49eca9bff61828548988e6f8763e8a6",
6532:                 "reference": "55d06b02f49eca9bff61828548988e6f8763e8a6",
6533:                 "shasum": ""
6534:             },
6535:             "require": {
6536:                 "php": ">=8.2",
6537:                 "symfony/deprecation-contracts": "^2.5|^3",
6538:                 "symfony/polyfill-mbstring": "~1.0"
6539:             },
6540:             "conflict": {
6541:                 "symfony/console": "<6.4"
6542:             },
6543:             "require-dev": {
6544:                 "symfony/console": "^6.4|^7.0|^8.0",
6545:                 "symfony/http-kernel": "^6.4|^7.0|^8.0",
6546:                 "symfony/process": "^6.4|^7.0|^8.0",
6547:                 "symfony/uid": "^6.4|^7.0|^8.0",
6548:                 "twig/twig": "^3.12|^4.0"
6549:             },
6550:             "bin": [
6551:                 "Resources/bin/var-dump-server"
6552:             ],
6553:             "type": "library",
6554:             "autoload": {
6555:                 "files": [
6556:                     "Resources/functions/dump.php"
6557:                 ],
6558:                 "psr-4": {
6559:                     "Symfony\\Component\\VarDumper\\": ""
6560:                 },
6561:                 "exclude-from-classmap": [
6562:                     "/Tests/"
6563:                 ]
6564:             },
6565:             "notification-url": "https://packagist.org/downloads/",
6566:             "license": [
6567:                 "MIT"
6568:             ],
6569:             "authors": [
6570:                 {
6571:                     "name": "Nicolas Grekas",
6572:                     "email": "p@tchwork.com"
6573:                 },
6574:                 {
6575:                     "name": "Symfony Community",
6576:                     "homepage": "https://symfony.com/contributors"
6577:                 }
6578:             ],
6579:             "description": "Provides mechanisms for walking through any arbitrary PHP variable",
6580:             "homepage": "https://symfony.com",
6581:             "keywords": [
6582:                 "debug",
6583:                 "dump"
6584:             ],
6585:             "support": {
6586:                 "source": "https://github.com/symfony/var-dumper/tree/v7.4.21"
6587:             },
6588:             "funding": [
6589:                 {
6590:                     "url": "https://symfony.com/sponsor",
6591:                     "type": "custom"
6592:                 },
6593:                 {
6594:                     "url": "https://github.com/fabpot",
6595:                     "type": "github"
6596:                 },
6597:                 {
6598:                     "url": "https://github.com/nicolas-grekas",
6599:                     "type": "github"
6600:                 },
6601:                 {
6602:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
6603:                     "type": "tidelift"
6604:                 }
6605:             ],
6606:             "time": "2026-10-13T05:48:33+00:00"
6607:         },
6608:         {
6609:             "name": "tijsverkoyen/css-to-inline-styles",
6610:             "version": "v2.4.0",
6611:             "source": {
6612:                 "type": "git",
6613:                 "url": "https://github.com/tijsverkoyen/CssToInlineStyles.git",
6614:                 "reference": "f0292ccf0ec75843d65027214426b6b163b48b41"
6615:             },
6616:             "dist": {
6617:                 "type": "zip",
6618:                 "url": "https://api.github.com/repos/tijsverkoyen/CssToInlineStyles/zipball/f0292ccf0ec75843d65027214426b6b163b48b41",
6619:                 "reference": "f0292ccf0ec75843d65027214426b6b163b48b41",
6620:                 "shasum": ""
6621:             },
6622:             "require": {
6623:                 "ext-dom": "*",
6624:                 "ext-libxml": "*",
6625:                 "php": "^7.4 || ^8.0",
6626:                 "symfony/css-selector": "^5.4 || ^6.0 || ^7.0 || ^8.0"
6627:             },
6628:             "require-dev": {
6629:                 "phpstan/phpstan": "^2.0",
6630:                 "phpstan/phpstan-phpunit": "^2.0",
6631:                 "phpunit/phpunit": "^8.5.21 || ^9.5.10"
6632:             },
6633:             "type": "library",
6634:             "extra": {
6635:                 "branch-alias": {
6636:                     "dev-master": "2.x-dev"
6637:                 }
6638:             },
6639:             "autoload": {
6640:                 "psr-4": {
6641:                     "TijsVerkoyen\\CssToInlineStyles\\": "src"
6642:                 }
6643:             },
6644:             "notification-url": "https://packagist.org/downloads/",
6645:             "license": [
6646:                 "BSD-3-Clause"
6647:             ],
6648:             "authors": [
6649:                 {
6650:                     "name": "Tijs Verkoyen",
6651:                     "email": "css_to_inline_styles@verkoyen.eu",
6652:                     "role": "Developer"
6653:                 }
6654:             ],
6655:             "description": "CssToInlineStyles is a class that enables you to convert HTML-pages/files into HTML-pages/files with inline styles. This is very useful when you're sending emails.",
6656:             "homepage": "https://github.com/tijsverkoyen/CssToInlineStyles",
6657:             "support": {
6658:                 "issues": "https://github.com/tijsverkoyen/CssToInlineStyles/issues",
6659:                 "source": "https://github.com/tijsverkoyen/CssToInlineStyles/tree/v2.4.0"
6660:             },
6661:             "time": "2025-12-02T11:56:42+00:00"
6662:         },
6663:         {
6664:             "name": "vlucas/phpdotenv",
6665:             "version": "v5.7.0",
6666:             "source": {
6667:                 "type": "git",
6668:                 "url": "https://github.com/phpdotenv/phpdotenv.git",
6669:                 "reference": "301c07936b16d88628b126b01d082ba153cf4c40"
6670:             },
6671:             "dist": {
6672:                 "type": "zip",
6673:                 "url": "https://api.github.com/repos/phpdotenv/phpdotenv/zipball/301c07936b16d88628b126b01d082ba153cf4c40",
6674:                 "reference": "301c07936b16d88628b126b01d082ba153cf4c40",
6675:                 "shasum": ""
6676:             },
6677:             "require": {
6678:                 "ext-pcre": "*",
6679:                 "graham-campbell/result-type": "^1.2",
6680:                 "php": "^7.2.5 || ^8.0",
6681:                 "phpoption/phpoption": "^1.10",
6682:                 "symfony/polyfill-ctype": "^1.26",
6683:                 "symfony/polyfill-mbstring": "^1.26",
6684:                 "symfony/polyfill-php80": "^1.26"
6685:             },
6686:             "require-dev": {
6687:                 "bamarni/composer-bin-plugin": "^1.8.2",
6688:                 "ext-filter": "*",
6689:                 "phpunit/phpunit": "^8.5.34 || ^9.6.13 || ^10.4.2"
6690:             },
6691:             "suggest": {
6692:                 "ext-filter": "Required to use the boolean validator."
6693:             },
6694:             "type": "library",
6695:             "extra": {
6696:                 "bamarni-bin": {
6697:                     "bin-links": true,
6698:                     "forward-command": false
6699:                 },
6700:                 "branch-alias": {
6701:                     "dev-master": "5.6-dev"
6702:                 }
6703:             },
6704:             "autoload": {
6705:                 "psr-4": {
6706:                     "Dotenv\\": "src/"
6707:                 }
6708:             },
6709:             "notification-url": "https://packagist.org/downloads/",
6710:             "license": [
6711:                 "BSD-3-Clause"
6712:             ],
6713:             "authors": [
6714:                 {
6715:                     "name": "Graham Campbell",
6716:                     "email": "hello@gjcampbell.co.uk",
6717:                     "homepage": "https://github.com/GrahamCampbell"
6718:                 },
6719:                 {
6720:                     "name": "Vance Lucas",
6721:                     "email": "vance@vancelucas.com",
6722:                     "homepage": "https://github.com/vlucas"
6723:                 }
6724:             ],
6725:             "description": "Loads environment variables from `.env` to `$_ENV` and `$_SERVER` automagically, and optionally to `getenv()`.",
6726:             "keywords": [
6727:                 "dotenv",
6728:                 "env",
6729:                 "environment"
6730:             ],
6731:             "support": {
6732:                 "issues": "https://github.com/vlucas/phpdotenv/issues",
6733:                 "source": "https://github.com/vlucas/phpdotenv/tree/v5.7.0"
6734:             },
6735:             "funding": [
6736:                 {
6737:                     "url": "https://github.com/GrahamCampbell",
6738:                     "type": "github"
6739:                 },
6740:                 {
6741:                     "url": "https://tidelift.com/funding/github/packagist/vlucas/phpdotenv",
6742:                     "type": "tidelift"
6743:                 }
6744:             ],
6745:             "time": "2026-08-24T18:07:49+00:00"
6746:         },
6747:         {
6748:             "name": "voku/portable-ascii",
6749:             "version": "2.1.1",
6750:             "source": {
6751:                 "type": "git",
6752:                 "url": "https://github.com/voku/portable-ascii.git",
6753:                 "reference": "8e1051fe39379367aecf014f41744ce7539a856f"
6754:             },
6755:             "dist": {
6756:                 "type": "zip",
6757:                 "url": "https://api.github.com/repos/voku/portable-ascii/zipball/8e1051fe39379367aecf014f41744ce7539a856f",
6758:                 "reference": "8e1051fe39379367aecf014f41744ce7539a856f",
6759:                 "shasum": ""
6760:             },
6761:             "require": {
6762:                 "php": ">=7.1.0"
6763:             },
6764:             "require-dev": {
6765:                 "phpunit/phpunit": "~8.5 || ~9.6 || ~10.5 || ~11.5"
6766:             },
6767:             "suggest": {
6768:                 "ext-intl": "Use Intl for transliterator_transliterate() support"
6769:             },
6770:             "type": "library",
6771:             "autoload": {
6772:                 "psr-4": {
6773:                     "voku\\": "src/voku/"
6774:                 }
6775:             },
6776:             "notification-url": "https://packagist.org/downloads/",
6777:             "license": [
6778:                 "MIT"
6779:             ],
6780:             "authors": [
6781:                 {
6782:                     "name": "Lars Moelleken",
6783:                     "homepage": "https://www.moelleken.org/"
6784:                 }
6785:             ],
6786:             "description": "Portable ASCII library - performance optimized (ascii) string functions for php.",
6787:             "homepage": "https://github.com/voku/portable-ascii",
6788:             "keywords": [
6789:                 "ascii",
6790:                 "clean",
6791:                 "php"
6792:             ],
6793:             "support": {
6794:                 "issues": "https://github.com/voku/portable-ascii/issues",
6795:                 "source": "https://github.com/voku/portable-ascii/tree/2.1.1"
6796:             },
6797:             "funding": [
6798:                 {
6799:                     "url": "https://www.paypal.me/moelleken",
6800:                     "type": "custom"
6801:                 },
6802:                 {
6803:                     "url": "https://github.com/voku",
6804:                     "type": "github"
6805:                 },
6806:                 {
6807:                     "url": "https://opencollective.com/portable-ascii",
6808:                     "type": "open_collective"
6809:                 },
6810:                 {
6811:                     "url": "https://www.patreon.com/voku",
6812:                     "type": "patreon"
6813:                 },
6814:                 {
6815:                     "url": "https://tidelift.com/funding/github/packagist/voku/portable-ascii",
6816:                     "type": "tidelift"
6817:                 }
6818:             ],
6819:             "time": "2026-04-26T05:33:54+00:00"
6820:         }
6821:     ],
6822:     "packages-dev": [
6823:         {
6824:             "name": "fakerphp/faker",
6825:             "version": "v1.24.1",
6826:             "source": {
6827:                 "type": "git",
6828:                 "url": "https://github.com/FakerPHP/Faker.git",
6829:                 "reference": "e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5"
6830:             },
6831:             "dist": {
6832:                 "type": "zip",
6833:                 "url": "https://api.github.com/repos/FakerPHP/Faker/zipball/e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5",
6834:                 "reference": "e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5",
6835:                 "shasum": ""
6836:             },
6837:             "require": {
6838:                 "php": "^7.4 || ^8.0",
6839:                 "psr/container": "^1.0 || ^2.0",
6840:                 "symfony/deprecation-contracts": "^2.2 || ^3.0"
6841:             },
6842:             "conflict": {
6843:                 "fzaninotto/faker": "*"
6844:             },
6845:             "require-dev": {
6846:                 "bamarni/composer-bin-plugin": "^1.4.1",
6847:                 "doctrine/persistence": "^1.3 || ^2.0",
6848:                 "ext-intl": "*",
6849:                 "phpunit/phpunit": "^9.5.26",
6850:                 "symfony/phpunit-bridge": "^5.4.16"
6851:             },
6852:             "suggest": {
6853:                 "doctrine/orm": "Required to use Faker\\ORM\\Doctrine",
6854:                 "ext-curl": "Required by Faker\\Provider\\Image to download images.",
6855:                 "ext-dom": "Required by Faker\\Provider\\HtmlLorem for generating random HTML.",
6856:                 "ext-iconv": "Required by Faker\\Provider\\ru_RU\\Text::realText() for generating real Russian text.",
6857:                 "ext-mbstring": "Required for multibyte Unicode string functionality."
6858:             },
6859:             "type": "library",
6860:             "autoload": {
6861:                 "psr-4": {
6862:                     "Faker\\": "src/Faker/"
6863:                 }
6864:             },
6865:             "notification-url": "https://packagist.org/downloads/",
6866:             "license": [
6867:                 "MIT"
6868:             ],
6869:             "authors": [
6870:                 {
6871:                     "name": "François Zaninotto"
6872:                 }
6873:             ],
6874:             "description": "Faker is a PHP library that generates fake data for you.",
6875:             "keywords": [
6876:                 "data",
6877:                 "faker",
6878:                 "fixtures"
6879:             ],
6880:             "support": {
6881:                 "issues": "https://github.com/FakerPHP/Faker/issues",
6882:                 "source": "https://github.com/FakerPHP/Faker/tree/v1.24.1"
6883:             },
6884:             "time": "2024-11-21T13:46:39+00:00"
6885:         },
6886:         {
6887:             "name": "filp/whoops",
6888:             "version": "2.18.6",
6889:             "source": {
6890:                 "type": "git",
6891:                 "url": "https://github.com/filp/whoops.git",
6892:                 "reference": "7d0a2cde892089bb09372f4366e9b960eb3001a9"
6893:             },
6894:             "dist": {
6895:                 "type": "zip",
6896:                 "url": "https://api.github.com/repos/filp/whoops/zipball/7d0a2cde892089bb09372f4366e9b960eb3001a9",
6897:                 "reference": "7d0a2cde892089bb09372f4366e9b960eb3001a9",
6898:                 "shasum": ""
6899:             },
6900:             "require": {
6901:                 "php": "^7.1 || ^8.0",
6902:                 "psr/log": "^1.0.1 || ^2.0 || ^3.0"
6903:             },
6904:             "require-dev": {
6905:                 "mockery/mockery": "^1.0",
6906:                 "phpunit/phpunit": "^7.5.20 || ^8.5.8 || ^9.3.3 || ^10.5.58",
6907:                 "symfony/var-dumper": "^4.0 || ^5.0 || ^6.0 || ^7.0 || ^8.0"
6908:             },
6909:             "suggest": {
6910:                 "symfony/var-dumper": "Pretty print complex values better with var-dumper available",
6911:                 "whoops/soap": "Formats errors as SOAP responses"
6912:             },
6913:             "type": "library",
6914:             "extra": {
6915:                 "branch-alias": {
6916:                     "dev-master": "2.7-dev"
6917:                 }
6918:             },
6919:             "autoload": {
6920:                 "psr-4": {
6921:                     "Whoops\\": "src/Whoops/"
6922:                 }
6923:             },
6924:             "notification-url": "https://packagist.org/downloads/",
6925:             "license": [
6926:                 "MIT"
6927:             ],
6928:             "authors": [
6929:                 {
6930:                     "name": "Filipe Dobreira",
6931:                     "homepage": "https://github.com/filp",
6932:                     "role": "Developer"
6933:                 }
6934:             ],
6935:             "description": "php error handling for cool kids",
6936:             "homepage": "https://filp.github.io/whoops/",
6937:             "keywords": [
6938:                 "error",
6939:                 "exception",
6940:                 "handling",
6941:                 "library",
6942:                 "throwable",
6943:                 "whoops"
6944:             ],
6945:             "support": {
6946:                 "issues": "https://github.com/filp/whoops/issues",
6947:                 "source": "https://github.com/filp/whoops/tree/2.18.6"
6948:             },
6949:             "funding": [
6950:                 {
6951:                     "url": "https://github.com/denis-sokolov",
6952:                     "type": "github"
6953:                 }
6954:             ],
6955:             "time": "2026-10-03T17:03:57+00:00"
6956:         },
6957:         {
6958:             "name": "hamcrest/hamcrest-php",
6959:             "version": "v3.0.0",
6960:             "source": {
6961:                 "type": "git",
6962:                 "url": "https://github.com/hamcrest/hamcrest-php.git",
6963:                 "reference": "b61cd040da1a4925bc90a51c074f5297e7c0fa52"
6964:             },
6965:             "dist": {
6966:                 "type": "zip",
6967:                 "url": "https://api.github.com/repos/hamcrest/hamcrest-php/zipball/b61cd040da1a4925bc90a51c074f5297e7c0fa52",
6968:                 "reference": "b61cd040da1a4925bc90a51c074f5297e7c0fa52",
6969:                 "shasum": ""
6970:             },
6971:             "require": {
6972:                 "ext-ctype": "*",
6973:                 "ext-dom": "*",
6974:                 "php": "^7.4|^8.0"
6975:             },
6976:             "replace": {
6977:                 "cordoval/hamcrest-php": "*",
6978:                 "davedevelopment/hamcrest-php": "*",
6979:                 "kodova/hamcrest-php": "*"
6980:             },
6981:             "require-dev": {
6982:                 "phpstan/phpstan": "^2.1",
6983:                 "phpstan/phpstan-phpunit": "^2.0",
6984:                 "phpunit/php-file-iterator": "^1.4 || ^2.0 || ^3.0",
6985:                 "phpunit/phpunit": "^4.8.36 || ^5.7 || ^6.5 || ^7.0 || ^8.0 || ^9.0"
6986:             },
6987:             "type": "library",
6988:             "extra": {
6989:                 "branch-alias": {
6990:                     "dev-master": "3.0-dev"
6991:                 }
6992:             },
6993:             "autoload": {
6994:                 "classmap": [
6995:                     "hamcrest"
6996:                 ]
6997:             },
6998:             "notification-url": "https://packagist.org/downloads/",
6999:             "license": [
7000:                 "BSD-3-Clause"
7001:             ],
7002:             "description": "This is the PHP port of Hamcrest Matchers",
7003:             "keywords": [
7004:                 "test"
7005:             ],
7006:             "support": {
7007:                 "issues": "https://github.com/hamcrest/hamcrest-php/issues",
7008:                 "source": "https://github.com/hamcrest/hamcrest-php/tree/v3.0.0"
7009:             },
7010:             "time": "2026-03-17T11:56:53+00:00"
7011:         },
7012:         {
7013:             "name": "laravel/agent-detector",
7014:             "version": "v2.0.4",
7015:             "source": {
7016:                 "type": "git",
7017:                 "url": "https://github.com/laravel/agent-detector.git",
7018:                 "reference": "2e4f1e00f75afa82ce2ade999c6e72b3c81d03c5"
7019:             },
7020:             "dist": {
7021:                 "type": "zip",
7022:                 "url": "https://api.github.com/repos/laravel/agent-detector/zipball/2e4f1e00f75afa82ce2ade999c6e72b3c81d03c5",
7023:                 "reference": "2e4f1e00f75afa82ce2ade999c6e72b3c81d03c5",
7024:                 "shasum": ""
7025:             },
7026:             "require": {
7027:                 "php": "^8.2.0"
7028:             },
7029:             "require-dev": {
7030:                 "laravel/pint": "^1.24.0",
7031:                 "pestphp/pest": "^3.8.5|^4.1.0",
7032:                 "pestphp/pest-plugin-type-coverage": "^3.0|^4.0.2",
7033:                 "phpstan/phpstan": "^2.1.26",
7034:                 "rector/rector": "^2.1.7",
7035:                 "symfony/var-dumper": "^7.3.3"
7036:             },
7037:             "type": "library",
7038:             "autoload": {
7039:                 "files": [
7040:                     "src/functions.php"
7041:                 ],
7042:                 "psr-4": {
7043:                     "Laravel\\AgentDetector\\": "src/"
7044:                 }
7045:             },
7046:             "notification-url": "https://packagist.org/downloads/",
7047:             "license": [
7048:                 "MIT"
7049:             ],
7050:             "authors": [
7051:                 {
7052:                     "name": "Taylor Otwell",
7053:                     "email": "taylor@laravel.com"
7054:                 }
7055:             ],
7056:             "description": "Detect if code is running in an AI agent or automated development environment",
7057:             "homepage": "https://github.com/laravel/agent-detector",
7058:             "keywords": [
7059:                 "Agent",
7060:                 "ai",
7061:                 "automation",
7062:                 "claude",
7063:                 "cursor",
7064:                 "detection",
7065:                 "devin",
7066:                 "php"
7067:             ],
7068:             "support": {
7069:                 "issues": "https://github.com/laravel/agent-detector/issues",
7070:                 "source": "https://github.com/laravel/agent-detector"
7071:             },
7072:             "time": "2026-10-05T14:40:01+00:00"
7073:         },
7074:         {
7075:             "name": "laravel/boost",
7076:             "version": "v2.10.3",
7077:             "source": {
7078:                 "type": "git",
7079:                 "url": "https://github.com/laravel/boost.git",
7080:                 "reference": "75c269a8a53e931c7252e7cd562e1a209356a7c6"
7081:             },
7082:             "dist": {
7083:                 "type": "zip",
7084:                 "url": "https://api.github.com/repos/laravel/boost/zipball/75c269a8a53e931c7252e7cd562e1a209356a7c6",
7085:                 "reference": "75c269a8a53e931c7252e7cd562e1a209356a7c6",
7086:                 "shasum": ""
7087:             },
7088:             "require": {
7089:                 "guzzlehttp/guzzle": "^7.9|^8.0",
7090:                 "illuminate/console": "^11.45.3|^12.41.1|^13.0",
7091:                 "illuminate/contracts": "^11.45.3|^12.41.1|^13.0",
7092:                 "illuminate/routing": "^11.45.3|^12.41.1|^13.0",
7093:                 "illuminate/support": "^11.45.3|^12.41.1|^13.0",
7094:                 "laravel/mcp": "^0.7.1|^0.8.0|^0.9.0|^1.0",
7095:                 "laravel/prompts": "^0.3.10",
7096:                 "laravel/roster": "^1.0.0",
7097:                 "php": "^8.2"
7098:             },
7099:             "require-dev": {
7100:                 "jasonmccreary/double": "^1.0",
7101:                 "laravel/pint": "^1.27.0",
7102:                 "mockery/mockery": "^1.6.12",
7103:                 "orchestra/testbench": "^9.15.0|^10.6|^11.0",
7104:                 "pestphp/pest": "^2.36.0|^3.8.4|^4.1.5",
7105:                 "phpstan/phpstan": "^2.1.27",
7106:                 "rector/rector": "^2.1"
7107:             },
7108:             "type": "library",
7109:             "extra": {
7110:                 "laravel": {
7111:                     "providers": [
7112:                         "Laravel\\Boost\\BoostServiceProvider"
7113:                     ]
7114:                 },
7115:                 "branch-alias": {
7116:                     "dev-master": "1.x-dev"
7117:                 }
7118:             },
7119:             "autoload": {
7120:                 "psr-4": {
7121:                     "Laravel\\Boost\\": "src/"
7122:                 }
7123:             },
7124:             "notification-url": "https://packagist.org/downloads/",
7125:             "license": [
7126:                 "MIT"
7127:             ],
7128:             "description": "Laravel Boost accelerates AI-assisted development by providing the essential context and structure that AI needs to generate high-quality, Laravel-specific code.",
7129:             "homepage": "https://github.com/laravel/boost",
7130:             "keywords": [
7131:                 "ai",
7132:                 "dev",
7133:                 "laravel"
7134:             ],
7135:             "support": {
7136:                 "issues": "https://github.com/laravel/boost/issues",
7137:                 "source": "https://github.com/laravel/boost"
7138:             },
7139:             "time": "2026-10-07T19:07:19+00:00"
7140:         },
7141:         {
7142:             "name": "laravel/mcp",
7143:             "version": "v1.0.2",
7144:             "source": {
7145:                 "type": "git",
7146:                 "url": "https://github.com/laravel/mcp.git",
7147:                 "reference": "26c7803551028653beab49af0e672ae38607b772"
7148:             },
7149:             "dist": {
7150:                 "type": "zip",
7151:                 "url": "https://api.github.com/repos/laravel/mcp/zipball/26c7803551028653beab49af0e672ae38607b772",
7152:                 "reference": "26c7803551028653beab49af0e672ae38607b772",
7153:                 "shasum": ""
7154:             },
7155:             "require": {
7156:                 "ext-json": "*",
7157:                 "ext-mbstring": "*",
7158:                 "illuminate/console": "^11.45.3|^12.41.1|^13.0",
7159:                 "illuminate/container": "^11.45.3|^12.41.1|^13.0",
7160:                 "illuminate/contracts": "^11.45.3|^12.41.1|^13.0",
7161:                 "illuminate/http": "^11.45.3|^12.41.1|^13.0",
7162:                 "illuminate/json-schema": "^12.41.1|^13.0",
7163:                 "illuminate/routing": "^11.45.3|^12.41.1|^13.0",
7164:                 "illuminate/support": "^11.45.3|^12.41.1|^13.0",
7165:                 "illuminate/validation": "^11.45.3|^12.41.1|^13.0",
7166:                 "php": "^8.2",
7167:                 "symfony/process": "^7.4.5|^8.0.5"
7168:             },
7169:             "require-dev": {
7170:                 "jasonmccreary/double": "^1.0",
7171:                 "laravel/pint": "^1.20",
7172:                 "orchestra/testbench": "^9.15|^10.8|^11.0",
7173:                 "pestphp/pest": "^3.8.5|^4.3.2",
7174:                 "phpstan/phpstan": "^2.1.27",
7175:                 "rector/rector": "^2.2.4"
7176:             },
7177:             "type": "library",
7178:             "extra": {
7179:                 "laravel": {
7180:                     "aliases": {
7181:                         "Mcp": "Laravel\\Mcp\\Facades\\Mcp"
7182:                     },
7183:                     "providers": [
7184:                         "Laravel\\Mcp\\Server\\McpServiceProvider"
7185:                     ]
7186:                 }
7187:             },
7188:             "autoload": {
7189:                 "psr-4": {
7190:                     "Laravel\\Mcp\\": "src/",
7191:                     "Laravel\\Mcp\\Server\\": "src/Server/"
7192:                 }
7193:             },
7194:             "notification-url": "https://packagist.org/downloads/",
7195:             "license": [
7196:                 "MIT"
7197:             ],
7198:             "authors": [
7199:                 {
7200:                     "name": "Taylor Otwell",
7201:                     "email": "taylor@laravel.com"
7202:                 }
7203:             ],
7204:             "description": "Rapidly build MCP servers for your Laravel applications.",
7205:             "homepage": "https://github.com/laravel/mcp",
7206:             "keywords": [
7207:                 "laravel",
7208:                 "mcp"
7209:             ],
7210:             "support": {
7211:                 "issues": "https://github.com/laravel/mcp/issues",
7212:                 "source": "https://github.com/laravel/mcp"
7213:             },
7214:             "time": "2026-10-11T20:52:46+00:00"
7215:         },
7216:         {
7217:             "name": "laravel/pail",
7218:             "version": "v1.2.7",
7219:             "source": {
7220:                 "type": "git",
7221:                 "url": "https://github.com/laravel/pail.git",
7222:                 "reference": "2f7d27dada8effc48b8c424445a69cca7007daaa"
7223:             },
7224:             "dist": {
7225:                 "type": "zip",
7226:                 "url": "https://api.github.com/repos/laravel/pail/zipball/2f7d27dada8effc48b8c424445a69cca7007daaa",
7227:                 "reference": "2f7d27dada8effc48b8c424445a69cca7007daaa",
7228:                 "shasum": ""
7229:             },
7230:             "require": {
7231:                 "ext-mbstring": "*",
7232:                 "illuminate/console": "^10.24|^11.0|^12.0|^13.0",
7233:                 "illuminate/contracts": "^10.24|^11.0|^12.0|^13.0",
7234:                 "illuminate/log": "^10.24|^11.0|^12.0|^13.0",
7235:                 "illuminate/process": "^10.24|^11.0|^12.0|^13.0",
7236:                 "illuminate/support": "^10.24|^11.0|^12.0|^13.0",
7237:                 "nunomaduro/termwind": "^1.15|^2.0",
7238:                 "php": "^8.2",
7239:                 "symfony/console": "^6.0|^7.0|^8.0"
7240:             },
7241:             "require-dev": {
7242:                 "laravel/framework": "^10.24|^11.0|^12.0|^13.0",
7243:                 "laravel/pint": "^1.13",
7244:                 "orchestra/testbench-core": "^8.13|^9.17|^10.8|^11.0",
7245:                 "pestphp/pest": "^2.20|^3.0|^4.0",
7246:                 "pestphp/pest-plugin-type-coverage": "^2.3|^3.0|^4.0",
7247:                 "phpstan/phpstan": "^1.12.27",
7248:                 "symfony/var-dumper": "^6.3|^7.0|^8.0",
7249:                 "symfony/yaml": "^6.3|^7.0|^8.0"
7250:             },
7251:             "type": "library",
7252:             "extra": {
7253:                 "laravel": {
7254:                     "providers": [
7255:                         "Laravel\\Pail\\PailServiceProvider"
7256:                     ]
7257:                 },
7258:                 "branch-alias": {
7259:                     "dev-main": "1.x-dev"
7260:                 }
7261:             },
7262:             "autoload": {
7263:                 "psr-4": {
7264:                     "Laravel\\Pail\\": "src/"
7265:                 }
7266:             },
7267:             "notification-url": "https://packagist.org/downloads/",
7268:             "license": [
7269:                 "MIT"
7270:             ],
7271:             "authors": [
7272:                 {
7273:                     "name": "Taylor Otwell",
7274:                     "email": "taylor@laravel.com"
7275:                 },
7276:                 {
7277:                     "name": "Nuno Maduro",
7278:                     "email": "enunomaduro@gmail.com"
7279:                 }
7280:             ],
7281:             "description": "Easily delve into your Laravel application's log files directly from the command line.",
7282:             "homepage": "https://github.com/laravel/pail",
7283:             "keywords": [
7284:                 "dev",
7285:                 "laravel",
7286:                 "logs",
7287:                 "php",
7288:                 "tail"
7289:             ],
7290:             "support": {
7291:                 "issues": "https://github.com/laravel/pail/issues",
7292:                 "source": "https://github.com/laravel/pail"
7293:             },
7294:             "time": "2026-05-20T22:24:57+00:00"
7295:         },
7296:         {
7297:             "name": "laravel/pao",
7298:             "version": "v1.1.5",
7299:             "source": {
7300:                 "type": "git",
7301:                 "url": "https://github.com/laravel/pao.git",
7302:                 "reference": "620424f1ce744d96937fb6be60a94793e79b67ad"
7303:             },
7304:             "dist": {
7305:                 "type": "zip",
7306:                 "url": "https://api.github.com/repos/laravel/pao/zipball/620424f1ce744d96937fb6be60a94793e79b67ad",
7307:                 "reference": "620424f1ce744d96937fb6be60a94793e79b67ad",
7308:                 "shasum": ""
7309:             },
7310:             "require": {
7311:                 "laravel/agent-detector": "^2.0.2",
7312:                 "php": "^8.3"
7313:             },
7314:             "conflict": {
7315:                 "laravel/framework": "<12.0.0",
7316:                 "nunomaduro/collision": "<8.9.3",
7317:                 "pestphp/pest": "<4.6.3 || >=6.0.0",
7318:                 "phpunit/phpunit": "<12.5.23 || >=13.0.0 <13.1.7 || >=14.0.0"
7319:             },
7320:             "require-dev": {
7321:                 "brianium/paratest": "^7.20.0",
7322:                 "laravel/pint": "^1.30.5",
7323:                 "orchestra/testbench": "^10.11.0 || ^11.2.0",
7324:                 "pestphp/pest": "^4.7.8 || ^5.1.4",
7325:                 "pestphp/pest-plugin-type-coverage": "^4.0.4 || ^5.0.2",
7326:                 "phpstan/phpstan": "^2.2.13",
7327:                 "rector/rector": "^2.6.6",
7328:                 "symfony/process": "^7.4.13 || ^8.1.6",
7329:                 "symfony/var-dumper": "^7.4.15 || ^8.1.6"
7330:             },
7331:             "type": "library",
7332:             "extra": {
7333:                 "pest": {
7334:                     "plugins": [
7335:                         "Laravel\\Pao\\Drivers\\Pest\\Plugin"
7336:                     ]
7337:                 },
7338:                 "laravel": {
7339:                     "providers": [
7340:                         "Laravel\\Pao\\Laravel\\ServiceProvider"
7341:                     ]
7342:                 }
7343:             },
7344:             "autoload": {
7345:                 "files": [
7346:                     "src/Autoload.php"
7347:                 ],
7348:                 "psr-4": {
7349:                     "Laravel\\Pao\\": "src/"
7350:                 }
7351:             },
7352:             "notification-url": "https://packagist.org/downloads/",
7353:             "license": [
7354:                 "MIT"
7355:             ],
7356:             "authors": [
7357:                 {
7358:                     "name": "Taylor Otwell",
7359:                     "email": "taylor@laravel.com"
7360:                 }
7361:             ],
7362:             "description": "Agent-optimized output for PHP testing tools",
7363:             "keywords": [
7364:                 "Agent",
7365:                 "PHPStan",
7366:                 "ai",
7367:                 "dev",
7368:                 "paratest",
7369:                 "pest",
7370:                 "php",
7371:                 "phpunit",
7372:                 "rector",
7373:                 "testing"
7374:             ],
7375:             "support": {
7376:                 "issues": "https://github.com/laravel/pao/issues",
7377:                 "source": "https://github.com/laravel/pao"
7378:             },
7379:             "time": "2026-09-07T14:48:06+00:00"
7380:         },
7381:         {
7382:             "name": "laravel/pint",
7383:             "version": "v1.32.1",
7384:             "source": {
7385:                 "type": "git",
7386:                 "url": "https://github.com/laravel/pint.git",
7387:                 "reference": "006bd7825eb01daf92959d060b89cd9514fe4253"
7388:             },
7389:             "dist": {
7390:                 "type": "zip",
7391:                 "url": "https://api.github.com/repos/laravel/pint/zipball/006bd7825eb01daf92959d060b89cd9514fe4253",
7392:                 "reference": "006bd7825eb01daf92959d060b89cd9514fe4253",
7393:                 "shasum": ""
7394:             },
7395:             "require": {
7396:                 "ext-json": "*",
7397:                 "ext-mbstring": "*",
7398:                 "ext-tokenizer": "*",
7399:                 "ext-xml": "*",
7400:                 "php": "^8.3.0"
7401:             },
7402:             "require-dev": {
7403:                 "composer/semver": "^3.4.4",
7404:                 "friendsofphp/php-cs-fixer": "^3.95.25",
7405:                 "illuminate/view": "^13.31.0",
7406:                 "larastan/larastan": "^3.11.0",
7407:                 "laravel-zero/framework": "^13.0.0",
7408:                 "laravel/agent-detector": "^2.0.2",
7409:                 "laravel/prompts": "^0.3.24",
7410:                 "mockery/mockery": "^1.6.15",
7411:                 "nunomaduro/termwind": "^2.4.0",
7412:                 "pestphp/pest": "^4.7.8"
7413:             },
7414:             "bin": [
7415:                 "builds/pint"
7416:             ],
7417:             "type": "project",
7418:             "autoload": {
7419:                 "psr-4": {
7420:                     "App\\": "app/",
7421:                     "Database\\Seeders\\": "database/seeders/",
7422:                     "Database\\Factories\\": "database/factories/"
7423:                 }
7424:             },
7425:             "notification-url": "https://packagist.org/downloads/",
7426:             "license": [
7427:                 "MIT"
7428:             ],
7429:             "authors": [
7430:                 {
7431:                     "name": "Nuno Maduro",
7432:                     "email": "enunomaduro@gmail.com"
7433:                 }
7434:             ],
7435:             "description": "An opinionated code formatter for PHP.",
7436:             "homepage": "https://laravel.com",
7437:             "keywords": [
7438:                 "dev",
7439:                 "format",
7440:                 "formatter",
7441:                 "lint",
7442:                 "linter",
7443:                 "php"
7444:             ],
7445:             "support": {
7446:                 "issues": "https://github.com/laravel/pint/issues",
7447:                 "source": "https://github.com/laravel/pint"
7448:             },
7449:             "time": "2026-09-10T10:47:55+00:00"
7450:         },
7451:         {
7452:             "name": "laravel/roster",
7453:             "version": "v1.0.0",
7454:             "source": {
7455:                 "type": "git",
7456:                 "url": "https://github.com/laravel/roster.git",
7457:                 "reference": "89e518bd88ae98ff50f6082f6b517c8d8e8245fa"
7458:             },
7459:             "dist": {
7460:                 "type": "zip",
7461:                 "url": "https://api.github.com/repos/laravel/roster/zipball/89e518bd88ae98ff50f6082f6b517c8d8e8245fa",
7462:                 "reference": "89e518bd88ae98ff50f6082f6b517c8d8e8245fa",
7463:                 "shasum": ""
7464:             },
7465:             "require": {
7466:                 "composer/semver": "^3.0",
7467:                 "illuminate/console": "^11.0|^12.0|^13.0",
7468:                 "illuminate/contracts": "^11.0|^12.0|^13.0",
7469:                 "illuminate/support": "^11.0|^12.0|^13.0",
7470:                 "php": "^8.2",
7471:                 "symfony/yaml": "^7.2|^8.0"
7472:             },
7473:             "require-dev": {
7474:                 "laravel/pint": "^1.29",
7475:                 "mockery/mockery": "^1.6",
7476:                 "orchestra/testbench": "^9.0|^10.0|^11.0",
7477:                 "pestphp/pest": "^3.0|^4.1",
7478:                 "phpstan/phpstan": "^2.0",
7479:                 "rector/rector": "^2.0"
7480:             },
7481:             "type": "library",
7482:             "extra": {
7483:                 "laravel": {
7484:                     "providers": [
7485:                         "Laravel\\Roster\\RosterServiceProvider"
7486:                     ]
7487:                 },
7488:                 "branch-alias": {
7489:                     "dev-master": "1.x-dev"
7490:                 }
7491:             },
7492:             "autoload": {
7493:                 "psr-4": {
7494:                     "Laravel\\Roster\\": "src/"
7495:                 }
7496:             },
7497:             "notification-url": "https://packagist.org/downloads/",
7498:             "license": [
7499:                 "MIT"
7500:             ],
7501:             "description": "Detect packages & approaches in use within a Laravel project",
7502:             "homepage": "https://github.com/laravel/roster",
7503:             "keywords": [
7504:                 "dev",
7505:                 "laravel"
7506:             ],
7507:             "support": {
7508:                 "issues": "https://github.com/laravel/roster/issues",
7509:                 "source": "https://github.com/laravel/roster"
7510:             },
7511:             "time": "2026-07-18T17:53:15+00:00"
7512:         },
7513:         {
7514:             "name": "mockery/mockery",
7515:             "version": "1.6.15",
7516:             "source": {
7517:                 "type": "git",
7518:                 "url": "https://github.com/mockery/mockery.git",
7519:                 "reference": "967a801bd188989a5669bd280f252d51c0fdc9ee"
7520:             },
7521:             "dist": {
7522:                 "type": "zip",
7523:                 "url": "https://api.github.com/repos/mockery/mockery/zipball/967a801bd188989a5669bd280f252d51c0fdc9ee",
7524:                 "reference": "967a801bd188989a5669bd280f252d51c0fdc9ee",
7525:                 "shasum": ""
7526:             },
7527:             "require": {
7528:                 "hamcrest/hamcrest-php": "^2.0 || ^3.0",
7529:                 "php": ">=7.3"
7530:             },
7531:             "conflict": {
7532:                 "phpunit/phpunit": "<8.0"
7533:             },
7534:             "require-dev": {
7535:                 "phpunit/phpunit": "^9.6.36",
7536:                 "symplify/easy-coding-standard": "^13.2.17"
7537:             },
7538:             "type": "library",
7539:             "autoload": {
7540:                 "files": [
7541:                     "library/helpers.php",
7542:                     "library/Mockery.php"
7543:                 ],
7544:                 "psr-4": {
7545:                     "Mockery\\": "library/Mockery"
7546:                 }
7547:             },
7548:             "notification-url": "https://packagist.org/downloads/",
7549:             "license": [
7550:                 "BSD-3-Clause"
7551:             ],
7552:             "authors": [
7553:                 {
7554:                     "name": "Pádraic Brady",
7555:                     "email": "padraic.brady@gmail.com",
7556:                     "homepage": "https://github.com/padraic",
7557:                     "role": "Author"
7558:                 },
7559:                 {
7560:                     "name": "Dave Marshall",
7561:                     "email": "dave.marshall@atstsolutions.co.uk",
7562:                     "homepage": "https://davedevelopment.co.uk",
7563:                     "role": "Developer"
7564:                 },
7565:                 {
7566:                     "name": "Nathanael Esayeas",
7567:                     "email": "nathanael.esayeas@protonmail.com",
7568:                     "homepage": "https://github.com/ghostwriter",
7569:                     "role": "Lead Developer"
7570:                 }
7571:             ],
7572:             "description": "Mockery is a simple yet flexible PHP mock object framework",
7573:             "homepage": "https://github.com/mockery/mockery",
7574:             "keywords": [
7575:                 "BDD",
7576:                 "TDD",
7577:                 "library",
7578:                 "mock",
7579:                 "mock objects",
7580:                 "mockery",
7581:                 "stub",
7582:                 "test",
7583:                 "test double",
7584:                 "testing"
7585:             ],
7586:             "support": {
7587:                 "docs": "https://docs.mockery.io/",
7588:                 "issues": "https://github.com/mockery/mockery/issues",
7589:                 "rss": "https://github.com/mockery/mockery/releases.atom",
7590:                 "security": "https://github.com/mockery/mockery/security/advisories",
7591:                 "source": "https://github.com/mockery/mockery"
7592:             },
7593:             "time": "2026-08-19T19:37:52+00:00"
7594:         },
7595:         {
7596:             "name": "myclabs/deep-copy",
7597:             "version": "1.14.0",
7598:             "source": {
7599:                 "type": "git",
7600:                 "url": "https://github.com/myclabs/DeepCopy.git",
7601:                 "reference": "8680aa248f8e07bc8fb43f56f0f5fc77a0c96aae"
7602:             },
7603:             "dist": {
7604:                 "type": "zip",
7605:                 "url": "https://api.github.com/repos/myclabs/DeepCopy/zipball/8680aa248f8e07bc8fb43f56f0f5fc77a0c96aae",
7606:                 "reference": "8680aa248f8e07bc8fb43f56f0f5fc77a0c96aae",
7607:                 "shasum": ""
7608:             },
7609:             "require": {
7610:                 "php": "^8.0"
7611:             },
7612:             "conflict": {
7613:                 "doctrine/collections": "<1.6.8",
7614:                 "doctrine/common": "<2.13.3 || >=3 <3.2.2"
7615:             },
7616:             "require-dev": {
7617:                 "doctrine/collections": "^1.6.8",
7618:                 "doctrine/common": "^2.13.3 || ^3.2.2",
7619:                 "phpspec/prophecy": "^1.10",
7620:                 "phpunit/phpunit": "^7.5.20 || ^8.5.23 || ^9.5.13"
7621:             },
7622:             "type": "library",
7623:             "autoload": {
7624:                 "files": [
7625:                     "src/DeepCopy/deep_copy.php"
7626:                 ],
7627:                 "psr-4": {
7628:                     "DeepCopy\\": "src/DeepCopy/"
7629:                 }
7630:             },
7631:             "notification-url": "https://packagist.org/downloads/",
7632:             "license": [
7633:                 "MIT"
7634:             ],
7635:             "description": "Create deep copies (clones) of your objects",
7636:             "keywords": [
7637:                 "clone",
7638:                 "copy",
7639:                 "duplicate",
7640:                 "object",
7641:                 "object graph"
7642:             ],
7643:             "support": {
7644:                 "issues": "https://github.com/myclabs/DeepCopy/issues",
7645:                 "source": "https://github.com/myclabs/DeepCopy/tree/1.14.0"
7646:             },
7647:             "funding": [
7648:                 {
7649:                     "url": "https://github.com/mnapoli",
7650:                     "type": "github"
7651:                 }
7652:             ],
7653:             "time": "2026-08-11T10:17:44+00:00"
7654:         },
7655:         {
7656:             "name": "nunomaduro/collision",
7657:             "version": "v8.9.5",
7658:             "source": {
7659:                 "type": "git",
7660:                 "url": "https://github.com/nunomaduro/collision.git",
7661:                 "reference": "fb53eacd509a1d303858e2d20cfebf2d630254ec"
7662:             },
7663:             "dist": {
7664:                 "type": "zip",
7665:                 "url": "https://api.github.com/repos/nunomaduro/collision/zipball/fb53eacd509a1d303858e2d20cfebf2d630254ec",
7666:                 "reference": "fb53eacd509a1d303858e2d20cfebf2d630254ec",
7667:                 "shasum": ""
7668:             },
7669:             "require": {
7670:                 "filp/whoops": "^2.18.4",
7671:                 "nunomaduro/termwind": "^2.4.0",
7672:                 "php": "^8.2.0",
7673:                 "symfony/console": "^7.4.14 || ^8.1.1"
7674:             },
7675:             "conflict": {
7676:                 "laravel/framework": "<11.48.0 || >=14.0.0",
7677:                 "phpunit/phpunit": "<11.5.50 || >=14.0.0"
7678:             },
7679:             "require-dev": {
7680:                 "brianium/paratest": "^7.8.5",
7681:                 "larastan/larastan": "^3.10.0",
7682:                 "laravel/framework": "^11.48.0 || ^12.56.0 || ^13.20.0",
7683:                 "laravel/pint": "^1.29.3",
7684:                 "orchestra/testbench-core": "^9.12.0 || ^10.12.1 || ^11.3.5",
7685:                 "pestphp/pest": "^3.8.5 || ^4.7.5 || ^5.0.0",
7686:                 "sebastian/environment": "^7.2.1 || ^8.1.2 || ^9.3.2"
7687:             },
7688:             "type": "library",
7689:             "extra": {
7690:                 "laravel": {
7691:                     "providers": [
7692:                         "NunoMaduro\\Collision\\Adapters\\Laravel\\CollisionServiceProvider"
7693:                     ]
7694:                 },
7695:                 "branch-alias": {
7696:                     "dev-8.x": "8.x-dev"
7697:                 }
7698:             },
7699:             "autoload": {
7700:                 "files": [
7701:                     "./src/Adapters/Phpunit/Autoload.php"
7702:                 ],
7703:                 "psr-4": {
7704:                     "NunoMaduro\\Collision\\": "src/"
7705:                 }
7706:             },
7707:             "notification-url": "https://packagist.org/downloads/",
7708:             "license": [
7709:                 "MIT"
7710:             ],
7711:             "authors": [
7712:                 {
7713:                     "name": "Nuno Maduro",
7714:                     "email": "enunomaduro@gmail.com"
7715:                 }
7716:             ],
7717:             "description": "Cli error handling for console/command-line PHP applications.",
7718:             "keywords": [
7719:                 "artisan",
7720:                 "cli",
7721:                 "command-line",
7722:                 "console",
7723:                 "dev",
7724:                 "error",
7725:                 "handling",
7726:                 "laravel",
7727:                 "laravel-zero",
7728:                 "php",
7729:                 "symfony"
7730:             ],
7731:             "support": {
7732:                 "issues": "https://github.com/nunomaduro/collision/issues",
7733:                 "source": "https://github.com/nunomaduro/collision"
7734:             },
7735:             "funding": [
7736:                 {
7737:                     "url": "https://www.paypal.com/paypalme/enunomaduro",
7738:                     "type": "custom"
7739:                 },
7740:                 {
7741:                     "url": "https://github.com/nunomaduro",
7742:                     "type": "github"
7743:                 },
7744:                 {
7745:                     "url": "https://www.patreon.com/nunomaduro",
7746:                     "type": "patreon"
7747:                 }
7748:             ],
7749:             "time": "2026-07-15T19:09:14+00:00"
7750:         },
7751:         {
7752:             "name": "phar-io/manifest",
7753:             "version": "2.0.4",
7754:             "source": {
7755:                 "type": "git",
7756:                 "url": "https://github.com/phar-io/manifest.git",
7757:                 "reference": "54750ef60c58e43759730615a392c31c80e23176"
7758:             },
7759:             "dist": {
7760:                 "type": "zip",
7761:                 "url": "https://api.github.com/repos/phar-io/manifest/zipball/54750ef60c58e43759730615a392c31c80e23176",
7762:                 "reference": "54750ef60c58e43759730615a392c31c80e23176",
7763:                 "shasum": ""
7764:             },
7765:             "require": {
7766:                 "ext-dom": "*",
7767:                 "ext-libxml": "*",
7768:                 "ext-phar": "*",
7769:                 "ext-xmlwriter": "*",
7770:                 "phar-io/version": "^3.0.1",
7771:                 "php": "^7.2 || ^8.0"
7772:             },
7773:             "type": "library",
7774:             "extra": {
7775:                 "branch-alias": {
7776:                     "dev-master": "2.0.x-dev"
7777:                 }
7778:             },
7779:             "autoload": {
7780:                 "classmap": [
7781:                     "src/"
7782:                 ]
7783:             },
7784:             "notification-url": "https://packagist.org/downloads/",
7785:             "license": [
7786:                 "BSD-3-Clause"
7787:             ],
7788:             "authors": [
7789:                 {
7790:                     "name": "Arne Blankerts",
7791:                     "email": "arne@blankerts.de",
7792:                     "role": "Developer"
7793:                 },
7794:                 {
7795:                     "name": "Sebastian Heuer",
7796:                     "email": "sebastian@phpeople.de",
7797:                     "role": "Developer"
7798:                 },
7799:                 {
7800:                     "name": "Sebastian Bergmann",
7801:                     "email": "sebastian@phpunit.de",
7802:                     "role": "Developer"
7803:                 }
7804:             ],
7805:             "description": "Component for reading phar.io manifest information from a PHP Archive (PHAR)",
7806:             "support": {
7807:                 "issues": "https://github.com/phar-io/manifest/issues",
7808:                 "source": "https://github.com/phar-io/manifest/tree/2.0.4"
7809:             },
7810:             "funding": [
7811:                 {
7812:                     "url": "https://github.com/theseer",
7813:                     "type": "github"
7814:                 }
7815:             ],
7816:             "time": "2024-03-03T12:33:53+00:00"
7817:         },
7818:         {
7819:             "name": "phar-io/version",
7820:             "version": "3.2.1",
7821:             "source": {
7822:                 "type": "git",
7823:                 "url": "https://github.com/phar-io/version.git",
7824:                 "reference": "4f7fd7836c6f332bb2933569e566a0d6c4cbed74"
7825:             },
7826:             "dist": {
7827:                 "type": "zip",
7828:                 "url": "https://api.github.com/repos/phar-io/version/zipball/4f7fd7836c6f332bb2933569e566a0d6c4cbed74",
7829:                 "reference": "4f7fd7836c6f332bb2933569e566a0d6c4cbed74",
7830:                 "shasum": ""
7831:             },
7832:             "require": {
7833:                 "php": "^7.2 || ^8.0"
7834:             },
7835:             "type": "library",
7836:             "autoload": {
7837:                 "classmap": [
7838:                     "src/"
7839:                 ]
7840:             },
7841:             "notification-url": "https://packagist.org/downloads/",
7842:             "license": [
7843:                 "BSD-3-Clause"
7844:             ],
7845:             "authors": [
7846:                 {
7847:                     "name": "Arne Blankerts",
7848:                     "email": "arne@blankerts.de",
7849:                     "role": "Developer"
7850:                 },
7851:                 {
7852:                     "name": "Sebastian Heuer",
7853:                     "email": "sebastian@phpeople.de",
7854:                     "role": "Developer"
7855:                 },
7856:                 {
7857:                     "name": "Sebastian Bergmann",
7858:                     "email": "sebastian@phpunit.de",
7859:                     "role": "Developer"
7860:                 }
7861:             ],
7862:             "description": "Library for handling version information and constraints",
7863:             "support": {
7864:                 "issues": "https://github.com/phar-io/version/issues",
7865:                 "source": "https://github.com/phar-io/version/tree/3.2.1"
7866:             },
7867:             "time": "2022-02-21T01:04:05+00:00"
7868:         },
7869:         {
7870:             "name": "phpunit/php-code-coverage",
7871:             "version": "12.5.8",
7872:             "source": {
7873:                 "type": "git",
7874:                 "url": "https://github.com/sebastianbergmann/php-code-coverage.git",
7875:                 "reference": "7440368d94738d54f2c1de24dbd3871793bbb0dd"
7876:             },
7877:             "dist": {
7878:                 "type": "zip",
7879:                 "url": "https://api.github.com/repos/sebastianbergmann/php-code-coverage/zipball/7440368d94738d54f2c1de24dbd3871793bbb0dd",
7880:                 "reference": "7440368d94738d54f2c1de24dbd3871793bbb0dd",
7881:                 "shasum": ""
7882:             },
7883:             "require": {
7884:                 "ext-dom": "*",
7885:                 "ext-libxml": "*",
7886:                 "ext-xmlwriter": "*",
7887:                 "nikic/php-parser": "^5.9.0",
7888:                 "php": ">=8.3",
7889:                 "phpunit/php-text-template": "^5.0.1",
7890:                 "sebastian/complexity": "^5.0",
7891:                 "sebastian/environment": "^8.1.2",
7892:                 "sebastian/lines-of-code": "^4.0.1",
7893:                 "sebastian/version": "^6.0",
7894:                 "theseer/tokenizer": "^2.0.1"
7895:             },
7896:             "require-dev": {
7897:                 "phpunit/phpunit": "^12.5.37"
7898:             },
7899:             "suggest": {
7900:                 "ext-pcov": "PHP extension that provides line coverage",
7901:                 "ext-xdebug": "PHP extension that provides line coverage as well as branch and path coverage"
7902:             },
7903:             "type": "library",
7904:             "extra": {
7905:                 "branch-alias": {
7906:                     "dev-main": "12.5.x-dev"
7907:                 }
7908:             },
7909:             "autoload": {
7910:                 "classmap": [
7911:                     "src/"
7912:                 ]
7913:             },
7914:             "notification-url": "https://packagist.org/downloads/",
7915:             "license": [
7916:                 "BSD-3-Clause"
7917:             ],
7918:             "authors": [
7919:                 {
7920:                     "name": "Sebastian Bergmann",
7921:                     "email": "sebastian@phpunit.de",
7922:                     "role": "lead"
7923:                 }
7924:             ],
7925:             "description": "Library that provides collection, processing, and rendering functionality for PHP code coverage information.",
7926:             "homepage": "https://github.com/sebastianbergmann/php-code-coverage",
7927:             "keywords": [
7928:                 "coverage",
7929:                 "testing",
7930:                 "xunit"
7931:             ],
7932:             "support": {
7933:                 "issues": "https://github.com/sebastianbergmann/php-code-coverage/issues",
7934:                 "security": "https://github.com/sebastianbergmann/php-code-coverage/security/policy",
7935:                 "source": "https://github.com/sebastianbergmann/php-code-coverage/tree/12.5.8"
7936:             },
7937:             "funding": [
7938:                 {
7939:                     "url": "https://github.com/sebastianbergmann",
7940:                     "type": "github"
7941:                 },
7942:                 {
7943:                     "url": "https://liberapay.com/sebastianbergmann",
7944:                     "type": "liberapay"
7945:                 },
7946:                 {
7947:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
7948:                     "type": "thanks_dev"
7949:                 },
7950:                 {
7951:                     "url": "https://tidelift.com/funding/github/packagist/phpunit/php-code-coverage",
7952:                     "type": "tidelift"
7953:                 }
7954:             ],
7955:             "time": "2026-10-03T14:03:50+00:00"
7956:         },
7957:         {
7958:             "name": "phpunit/php-file-iterator",
7959:             "version": "6.0.2",
7960:             "source": {
7961:                 "type": "git",
7962:                 "url": "https://github.com/sebastianbergmann/php-file-iterator.git",
7963:                 "reference": "a248d1640ab059b075f53a2ef0f9856e864e06b5"
7964:             },
7965:             "dist": {
7966:                 "type": "zip",
7967:                 "url": "https://api.github.com/repos/sebastianbergmann/php-file-iterator/zipball/a248d1640ab059b075f53a2ef0f9856e864e06b5",
7968:                 "reference": "a248d1640ab059b075f53a2ef0f9856e864e06b5",
7969:                 "shasum": ""
7970:             },
7971:             "require": {
7972:                 "php": ">=8.3"
7973:             },
7974:             "require-dev": {
7975:                 "phpunit/phpunit": "^12.5.33"
7976:             },
7977:             "type": "library",
7978:             "extra": {
7979:                 "branch-alias": {
7980:                     "dev-main": "6.0-dev"
7981:                 }
7982:             },
7983:             "autoload": {
7984:                 "classmap": [
7985:                     "src/"
7986:                 ]
7987:             },
7988:             "notification-url": "https://packagist.org/downloads/",
7989:             "license": [
7990:                 "BSD-3-Clause"
7991:             ],
7992:             "authors": [
7993:                 {
7994:                     "name": "Sebastian Bergmann",
7995:                     "email": "sebastian@phpunit.de",
7996:                     "role": "lead"
7997:                 }
7998:             ],
7999:             "description": "FilterIterator implementation that filters files based on a list of suffixes.",
8000:             "homepage": "https://github.com/sebastianbergmann/php-file-iterator/",
8001:             "keywords": [
8002:                 "filesystem",
8003:                 "iterator"
8004:             ],
8005:             "support": {
8006:                 "issues": "https://github.com/sebastianbergmann/php-file-iterator/issues",
8007:                 "security": "https://github.com/sebastianbergmann/php-file-iterator/security/policy",
8008:                 "source": "https://github.com/sebastianbergmann/php-file-iterator/tree/6.0.2"
8009:             },
8010:             "funding": [
8011:                 {
8012:                     "url": "https://github.com/sebastianbergmann",
8013:                     "type": "github"
8014:                 },
8015:                 {
8016:                     "url": "https://liberapay.com/sebastianbergmann",
8017:                     "type": "liberapay"
8018:                 },
8019:                 {
8020:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8021:                     "type": "thanks_dev"
8022:                 },
8023:                 {
8024:                     "url": "https://tidelift.com/funding/github/packagist/phpunit/php-file-iterator",
8025:                     "type": "tidelift"
8026:                 }
8027:             ],
8028:             "time": "2026-08-25T14:40:53+00:00"
8029:         },
8030:         {
8031:             "name": "phpunit/php-invoker",
8032:             "version": "6.0.0",
8033:             "source": {
8034:                 "type": "git",
8035:                 "url": "https://github.com/sebastianbergmann/php-invoker.git",
8036:                 "reference": "12b54e689b07a25a9b41e57736dfab6ec9ae5406"
8037:             },
8038:             "dist": {
8039:                 "type": "zip",
8040:                 "url": "https://api.github.com/repos/sebastianbergmann/php-invoker/zipball/12b54e689b07a25a9b41e57736dfab6ec9ae5406",
8041:                 "reference": "12b54e689b07a25a9b41e57736dfab6ec9ae5406",
8042:                 "shasum": ""
8043:             },
8044:             "require": {
8045:                 "php": ">=8.3"
8046:             },
8047:             "require-dev": {
8048:                 "ext-pcntl": "*",
8049:                 "phpunit/phpunit": "^12.0"
8050:             },
8051:             "suggest": {
8052:                 "ext-pcntl": "*"
8053:             },
8054:             "type": "library",
8055:             "extra": {
8056:                 "branch-alias": {
8057:                     "dev-main": "6.0-dev"
8058:                 }
8059:             },
8060:             "autoload": {
8061:                 "classmap": [
8062:                     "src/"
8063:                 ]
8064:             },
8065:             "notification-url": "https://packagist.org/downloads/",
8066:             "license": [
8067:                 "BSD-3-Clause"
8068:             ],
8069:             "authors": [
8070:                 {
8071:                     "name": "Sebastian Bergmann",
8072:                     "email": "sebastian@phpunit.de",
8073:                     "role": "lead"
8074:                 }
8075:             ],
8076:             "description": "Invoke callables with a timeout",
8077:             "homepage": "https://github.com/sebastianbergmann/php-invoker/",
8078:             "keywords": [
8079:                 "process"
8080:             ],
8081:             "support": {
8082:                 "issues": "https://github.com/sebastianbergmann/php-invoker/issues",
8083:                 "security": "https://github.com/sebastianbergmann/php-invoker/security/policy",
8084:                 "source": "https://github.com/sebastianbergmann/php-invoker/tree/6.0.0"
8085:             },
8086:             "funding": [
8087:                 {
8088:                     "url": "https://github.com/sebastianbergmann",
8089:                     "type": "github"
8090:                 }
8091:             ],
8092:             "time": "2025-02-07T04:58:58+00:00"
8093:         },
8094:         {
8095:             "name": "phpunit/php-text-template",
8096:             "version": "5.0.1",
8097:             "source": {
8098:                 "type": "git",
8099:                 "url": "https://github.com/sebastianbergmann/php-text-template.git",
8100:                 "reference": "babab73b7ef9fae406f2eb3ee11323a61caa63d5"
8101:             },
8102:             "dist": {
8103:                 "type": "zip",
8104:                 "url": "https://api.github.com/repos/sebastianbergmann/php-text-template/zipball/babab73b7ef9fae406f2eb3ee11323a61caa63d5",
8105:                 "reference": "babab73b7ef9fae406f2eb3ee11323a61caa63d5",
8106:                 "shasum": ""
8107:             },
8108:             "require": {
8109:                 "php": ">=8.3"
8110:             },
8111:             "require-dev": {
8112:                 "phpunit/phpunit": "^12.5.37"
8113:             },
8114:             "type": "library",
8115:             "extra": {
8116:                 "branch-alias": {
8117:                     "dev-main": "5.0-dev"
8118:                 }
8119:             },
8120:             "autoload": {
8121:                 "classmap": [
8122:                     "src/"
8123:                 ]
8124:             },
8125:             "notification-url": "https://packagist.org/downloads/",
8126:             "license": [
8127:                 "BSD-3-Clause"
8128:             ],
8129:             "authors": [
8130:                 {
8131:                     "name": "Sebastian Bergmann",
8132:                     "email": "sebastian@phpunit.de",
8133:                     "role": "lead"
8134:                 }
8135:             ],
8136:             "description": "Simple template engine.",
8137:             "homepage": "https://github.com/sebastianbergmann/php-text-template/",
8138:             "keywords": [
8139:                 "template"
8140:             ],
8141:             "support": {
8142:                 "issues": "https://github.com/sebastianbergmann/php-text-template/issues",
8143:                 "security": "https://github.com/sebastianbergmann/php-text-template/security/policy",
8144:                 "source": "https://github.com/sebastianbergmann/php-text-template/tree/5.0.1"
8145:             },
8146:             "funding": [
8147:                 {
8148:                     "url": "https://github.com/sebastianbergmann",
8149:                     "type": "github"
8150:                 },
8151:                 {
8152:                     "url": "https://liberapay.com/sebastianbergmann",
8153:                     "type": "liberapay"
8154:                 },
8155:                 {
8156:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8157:                     "type": "thanks_dev"
8158:                 },
8159:                 {
8160:                     "url": "https://tidelift.com/funding/github/packagist/phpunit/php-text-template",
8161:                     "type": "tidelift"
8162:                 }
8163:             ],
8164:             "time": "2026-10-03T04:51:29+00:00"
8165:         },
8166:         {
8167:             "name": "phpunit/php-timer",
8168:             "version": "8.0.0",
8169:             "source": {
8170:                 "type": "git",
8171:                 "url": "https://github.com/sebastianbergmann/php-timer.git",
8172:                 "reference": "f258ce36aa457f3aa3339f9ed4c81fc66dc8c2cc"
8173:             },
8174:             "dist": {
8175:                 "type": "zip",
8176:                 "url": "https://api.github.com/repos/sebastianbergmann/php-timer/zipball/f258ce36aa457f3aa3339f9ed4c81fc66dc8c2cc",
8177:                 "reference": "f258ce36aa457f3aa3339f9ed4c81fc66dc8c2cc",
8178:                 "shasum": ""
8179:             },
8180:             "require": {
8181:                 "php": ">=8.3"
8182:             },
8183:             "require-dev": {
8184:                 "phpunit/phpunit": "^12.0"
8185:             },
8186:             "type": "library",
8187:             "extra": {
8188:                 "branch-alias": {
8189:                     "dev-main": "8.0-dev"
8190:                 }
8191:             },
8192:             "autoload": {
8193:                 "classmap": [
8194:                     "src/"
8195:                 ]
8196:             },
8197:             "notification-url": "https://packagist.org/downloads/",
8198:             "license": [
8199:                 "BSD-3-Clause"
8200:             ],
8201:             "authors": [
8202:                 {
8203:                     "name": "Sebastian Bergmann",
8204:                     "email": "sebastian@phpunit.de",
8205:                     "role": "lead"
8206:                 }
8207:             ],
8208:             "description": "Utility class for timing",
8209:             "homepage": "https://github.com/sebastianbergmann/php-timer/",
8210:             "keywords": [
8211:                 "timer"
8212:             ],
8213:             "support": {
8214:                 "issues": "https://github.com/sebastianbergmann/php-timer/issues",
8215:                 "security": "https://github.com/sebastianbergmann/php-timer/security/policy",
8216:                 "source": "https://github.com/sebastianbergmann/php-timer/tree/8.0.0"
8217:             },
8218:             "funding": [
8219:                 {
8220:                     "url": "https://github.com/sebastianbergmann",
8221:                     "type": "github"
8222:                 }
8223:             ],
8224:             "time": "2025-02-07T04:59:38+00:00"
8225:         },
8226:         {
8227:             "name": "phpunit/phpunit",
8228:             "version": "12.5.39",
8229:             "source": {
8230:                 "type": "git",
8231:                 "url": "https://github.com/sebastianbergmann/phpunit.git",
8232:                 "reference": "4e8cbead3741cc57f16d142f4c5dc73c2747820a"
8233:             },
8234:             "dist": {
8235:                 "type": "zip",
8236:                 "url": "https://api.github.com/repos/sebastianbergmann/phpunit/zipball/4e8cbead3741cc57f16d142f4c5dc73c2747820a",
8237:                 "reference": "4e8cbead3741cc57f16d142f4c5dc73c2747820a",
8238:                 "shasum": ""
8239:             },
8240:             "require": {
8241:                 "ext-dom": "*",
8242:                 "ext-filter": "*",
8243:                 "ext-json": "*",
8244:                 "ext-libxml": "*",
8245:                 "ext-mbstring": "*",
8246:                 "ext-xmlwriter": "*",
8247:                 "myclabs/deep-copy": "^1.14.0",
8248:                 "phar-io/manifest": "^2.0.4",
8249:                 "phar-io/version": "^3.2.1",
8250:                 "php": ">=8.3",
8251:                 "phpunit/php-code-coverage": "^12.5.8",
8252:                 "phpunit/php-file-iterator": "^6.0.2",
8253:                 "phpunit/php-invoker": "^6.0.0",
8254:                 "phpunit/php-text-template": "^5.0.1",
8255:                 "phpunit/php-timer": "^8.0.0",
8256:                 "sebastian/cli-parser": "^4.2.1",
8257:                 "sebastian/comparator": "^7.1.8",
8258:                 "sebastian/diff": "^7.0.1",
8259:                 "sebastian/environment": "^8.1.2",
8260:                 "sebastian/exporter": "^7.0.3",
8261:                 "sebastian/global-state": "^8.0.3",
8262:                 "sebastian/object-enumerator": "^7.0.0",
8263:                 "sebastian/recursion-context": "^7.0.1",
8264:                 "sebastian/type": "^6.0.4",
8265:                 "sebastian/version": "^6.0.1",
8266:                 "staabm/side-effects-detector": "^1.0.5"
8267:             },
8268:             "bin": [
8269:                 "phpunit"
8270:             ],
8271:             "type": "library",
8272:             "extra": {
8273:                 "branch-alias": {
8274:                     "dev-main": "12.5-dev"
8275:                 }
8276:             },
8277:             "autoload": {
8278:                 "files": [
8279:                     "src/Framework/Assert/Functions.php"
8280:                 ],
8281:                 "classmap": [
8282:                     "src/"
8283:                 ]
8284:             },
8285:             "notification-url": "https://packagist.org/downloads/",
8286:             "license": [
8287:                 "BSD-3-Clause"
8288:             ],
8289:             "authors": [
8290:                 {
8291:                     "name": "Sebastian Bergmann",
8292:                     "email": "sebastian@phpunit.de",
8293:                     "role": "lead"
8294:                 }
8295:             ],
8296:             "description": "The PHP Unit Testing framework.",
8297:             "homepage": "https://phpunit.de/",
8298:             "keywords": [
8299:                 "phpunit",
8300:                 "testing",
8301:                 "xunit"
8302:             ],
8303:             "support": {
8304:                 "issues": "https://github.com/sebastianbergmann/phpunit/issues",
8305:                 "security": "https://github.com/sebastianbergmann/phpunit/security/policy",
8306:                 "source": "https://github.com/sebastianbergmann/phpunit/tree/12.5.39"
8307:             },
8308:             "funding": [
8309:                 {
8310:                     "url": "https://phpunit.de/sponsoring.html",
8311:                     "type": "other"
8312:                 }
8313:             ],
8314:             "time": "2026-10-12T15:27:27+00:00"
8315:         },
8316:         {
8317:             "name": "sebastian/cli-parser",
8318:             "version": "4.2.1",
8319:             "source": {
8320:                 "type": "git",
8321:                 "url": "https://github.com/sebastianbergmann/cli-parser.git",
8322:                 "reference": "7d05781b13f7dec9043a629a21d086ed74582a15"
8323:             },
8324:             "dist": {
8325:                 "type": "zip",
8326:                 "url": "https://api.github.com/repos/sebastianbergmann/cli-parser/zipball/7d05781b13f7dec9043a629a21d086ed74582a15",
8327:                 "reference": "7d05781b13f7dec9043a629a21d086ed74582a15",
8328:                 "shasum": ""
8329:             },
8330:             "require": {
8331:                 "php": ">=8.3"
8332:             },
8333:             "require-dev": {
8334:                 "phpunit/phpunit": "^12.5.25"
8335:             },
8336:             "type": "library",
8337:             "extra": {
8338:                 "branch-alias": {
8339:                     "dev-main": "4.2-dev"
8340:                 }
8341:             },
8342:             "autoload": {
8343:                 "classmap": [
8344:                     "src/"
8345:                 ]
8346:             },
8347:             "notification-url": "https://packagist.org/downloads/",
8348:             "license": [
8349:                 "BSD-3-Clause"
8350:             ],
8351:             "authors": [
8352:                 {
8353:                     "name": "Sebastian Bergmann",
8354:                     "email": "sebastian@phpunit.de",
8355:                     "role": "lead"
8356:                 }
8357:             ],
8358:             "description": "Library for parsing CLI options",
8359:             "homepage": "https://github.com/sebastianbergmann/cli-parser",
8360:             "support": {
8361:                 "issues": "https://github.com/sebastianbergmann/cli-parser/issues",
8362:                 "security": "https://github.com/sebastianbergmann/cli-parser/security/policy",
8363:                 "source": "https://github.com/sebastianbergmann/cli-parser/tree/4.2.1"
8364:             },
8365:             "funding": [
8366:                 {
8367:                     "url": "https://github.com/sebastianbergmann",
8368:                     "type": "github"
8369:                 },
8370:                 {
8371:                     "url": "https://liberapay.com/sebastianbergmann",
8372:                     "type": "liberapay"
8373:                 },
8374:                 {
8375:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8376:                     "type": "thanks_dev"
8377:                 },
8378:                 {
8379:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/cli-parser",
8380:                     "type": "tidelift"
8381:                 }
8382:             ],
8383:             "time": "2026-05-17T05:29:34+00:00"
8384:         },
8385:         {
8386:             "name": "sebastian/comparator",
8387:             "version": "7.1.8",
8388:             "source": {
8389:                 "type": "git",
8390:                 "url": "https://github.com/sebastianbergmann/comparator.git",
8391:                 "reference": "7c65c1e79836812819705b473a90c12399542485"
8392:             },
8393:             "dist": {
8394:                 "type": "zip",
8395:                 "url": "https://api.github.com/repos/sebastianbergmann/comparator/zipball/7c65c1e79836812819705b473a90c12399542485",
8396:                 "reference": "7c65c1e79836812819705b473a90c12399542485",
8397:                 "shasum": ""
8398:             },
8399:             "require": {
8400:                 "ext-dom": "*",
8401:                 "ext-mbstring": "*",
8402:                 "php": ">=8.3",
8403:                 "sebastian/diff": "^7.0",
8404:                 "sebastian/exporter": "^7.0.3"
8405:             },
8406:             "require-dev": {
8407:                 "phpunit/phpunit": "^12.5.25"
8408:             },
8409:             "suggest": {
8410:                 "ext-bcmath": "For comparing BcMath\\Number objects"
8411:             },
8412:             "type": "library",
8413:             "extra": {
8414:                 "branch-alias": {
8415:                     "dev-main": "7.1-dev"
8416:                 }
8417:             },
8418:             "autoload": {
8419:                 "classmap": [
8420:                     "src/"
8421:                 ]
8422:             },
8423:             "notification-url": "https://packagist.org/downloads/",
8424:             "license": [
8425:                 "BSD-3-Clause"
8426:             ],
8427:             "authors": [
8428:                 {
8429:                     "name": "Sebastian Bergmann",
8430:                     "email": "sebastian@phpunit.de"
8431:                 },
8432:                 {
8433:                     "name": "Jeff Welch",
8434:                     "email": "whatthejeff@gmail.com"
8435:                 },
8436:                 {
8437:                     "name": "Volker Dusch",
8438:                     "email": "github@wallbash.com"
8439:                 },
8440:                 {
8441:                     "name": "Bernhard Schussek",
8442:                     "email": "bschussek@2bepublished.at"
8443:                 }
8444:             ],
8445:             "description": "Provides the functionality to compare PHP values for equality",
8446:             "homepage": "https://github.com/sebastianbergmann/comparator",
8447:             "keywords": [
8448:                 "comparator",
8449:                 "compare",
8450:                 "equality"
8451:             ],
8452:             "support": {
8453:                 "issues": "https://github.com/sebastianbergmann/comparator/issues",
8454:                 "security": "https://github.com/sebastianbergmann/comparator/security/policy",
8455:                 "source": "https://github.com/sebastianbergmann/comparator/tree/7.1.8"
8456:             },
8457:             "funding": [
8458:                 {
8459:                     "url": "https://github.com/sebastianbergmann",
8460:                     "type": "github"
8461:                 },
8462:                 {
8463:                     "url": "https://liberapay.com/sebastianbergmann",
8464:                     "type": "liberapay"
8465:                 },
8466:                 {
8467:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8468:                     "type": "thanks_dev"
8469:                 },
8470:                 {
8471:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/comparator",
8472:                     "type": "tidelift"
8473:                 }
8474:             ],
8475:             "time": "2026-05-21T04:45:25+00:00"
8476:         },
8477:         {
8478:             "name": "sebastian/complexity",
8479:             "version": "5.0.0",
8480:             "source": {
8481:                 "type": "git",
8482:                 "url": "https://github.com/sebastianbergmann/complexity.git",
8483:                 "reference": "bad4316aba5303d0221f43f8cee37eb58d384bbb"
8484:             },
8485:             "dist": {
8486:                 "type": "zip",
8487:                 "url": "https://api.github.com/repos/sebastianbergmann/complexity/zipball/bad4316aba5303d0221f43f8cee37eb58d384bbb",
8488:                 "reference": "bad4316aba5303d0221f43f8cee37eb58d384bbb",
8489:                 "shasum": ""
8490:             },
8491:             "require": {
8492:                 "nikic/php-parser": "^5.0",
8493:                 "php": ">=8.3"
8494:             },
8495:             "require-dev": {
8496:                 "phpunit/phpunit": "^12.0"
8497:             },
8498:             "type": "library",
8499:             "extra": {
8500:                 "branch-alias": {
8501:                     "dev-main": "5.0-dev"
8502:                 }
8503:             },
8504:             "autoload": {
8505:                 "classmap": [
8506:                     "src/"
8507:                 ]
8508:             },
8509:             "notification-url": "https://packagist.org/downloads/",
8510:             "license": [
8511:                 "BSD-3-Clause"
8512:             ],
8513:             "authors": [
8514:                 {
8515:                     "name": "Sebastian Bergmann",
8516:                     "email": "sebastian@phpunit.de",
8517:                     "role": "lead"
8518:                 }
8519:             ],
8520:             "description": "Library for calculating the complexity of PHP code units",
8521:             "homepage": "https://github.com/sebastianbergmann/complexity",
8522:             "support": {
8523:                 "issues": "https://github.com/sebastianbergmann/complexity/issues",
8524:                 "security": "https://github.com/sebastianbergmann/complexity/security/policy",
8525:                 "source": "https://github.com/sebastianbergmann/complexity/tree/5.0.0"
8526:             },
8527:             "funding": [
8528:                 {
8529:                     "url": "https://github.com/sebastianbergmann",
8530:                     "type": "github"
8531:                 }
8532:             ],
8533:             "time": "2025-02-07T04:55:25+00:00"
8534:         },
8535:         {
8536:             "name": "sebastian/diff",
8537:             "version": "7.0.1",
8538:             "source": {
8539:                 "type": "git",
8540:                 "url": "https://github.com/sebastianbergmann/diff.git",
8541:                 "reference": "cd4cabe39f8a4e8ee6818ba99f10a05561ea4ad6"
8542:             },
8543:             "dist": {
8544:                 "type": "zip",
8545:                 "url": "https://api.github.com/repos/sebastianbergmann/diff/zipball/cd4cabe39f8a4e8ee6818ba99f10a05561ea4ad6",
8546:                 "reference": "cd4cabe39f8a4e8ee6818ba99f10a05561ea4ad6",
8547:                 "shasum": ""
8548:             },
8549:             "require": {
8550:                 "php": ">=8.3"
8551:             },
8552:             "require-dev": {
8553:                 "phpunit/phpunit": "^12.5.33",
8554:                 "symfony/process": "^7.4.17"
8555:             },
8556:             "type": "library",
8557:             "extra": {
8558:                 "branch-alias": {
8559:                     "dev-main": "7.0-dev"
8560:                 }
8561:             },
8562:             "autoload": {
8563:                 "classmap": [
8564:                     "src/"
8565:                 ]
8566:             },
8567:             "notification-url": "https://packagist.org/downloads/",
8568:             "license": [
8569:                 "BSD-3-Clause"
8570:             ],
8571:             "authors": [
8572:                 {
8573:                     "name": "Sebastian Bergmann",
8574:                     "email": "sebastian@phpunit.de"
8575:                 },
8576:                 {
8577:                     "name": "Kore Nordmann",
8578:                     "email": "mail@kore-nordmann.de"
8579:                 }
8580:             ],
8581:             "description": "Diff implementation",
8582:             "homepage": "https://github.com/sebastianbergmann/diff",
8583:             "keywords": [
8584:                 "diff",
8585:                 "udiff",
8586:                 "unidiff",
8587:                 "unified diff"
8588:             ],
8589:             "support": {
8590:                 "issues": "https://github.com/sebastianbergmann/diff/issues",
8591:                 "security": "https://github.com/sebastianbergmann/diff/security/policy",
8592:                 "source": "https://github.com/sebastianbergmann/diff/tree/7.0.1"
8593:             },
8594:             "funding": [
8595:                 {
8596:                     "url": "https://github.com/sebastianbergmann",
8597:                     "type": "github"
8598:                 },
8599:                 {
8600:                     "url": "https://liberapay.com/sebastianbergmann",
8601:                     "type": "liberapay"
8602:                 },
8603:                 {
8604:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8605:                     "type": "thanks_dev"
8606:                 },
8607:                 {
8608:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/diff",
8609:                     "type": "tidelift"
8610:                 }
8611:             ],
8612:             "time": "2026-08-25T15:35:54+00:00"
8613:         },
8614:         {
8615:             "name": "sebastian/environment",
8616:             "version": "8.1.2",
8617:             "source": {
8618:                 "type": "git",
8619:                 "url": "https://github.com/sebastianbergmann/environment.git",
8620:                 "reference": "9d32c685773823b1983e256ae4ecd48a10d6e439"
8621:             },
8622:             "dist": {
8623:                 "type": "zip",
8624:                 "url": "https://api.github.com/repos/sebastianbergmann/environment/zipball/9d32c685773823b1983e256ae4ecd48a10d6e439",
8625:                 "reference": "9d32c685773823b1983e256ae4ecd48a10d6e439",
8626:                 "shasum": ""
8627:             },
8628:             "require": {
8629:                 "php": ">=8.3"
8630:             },
8631:             "require-dev": {
8632:                 "phpunit/phpunit": "^12.5.26"
8633:             },
8634:             "suggest": {
8635:                 "ext-posix": "*"
8636:             },
8637:             "type": "library",
8638:             "extra": {
8639:                 "branch-alias": {
8640:                     "dev-main": "8.1-dev"
8641:                 }
8642:             },
8643:             "autoload": {
8644:                 "classmap": [
8645:                     "src/"
8646:                 ]
8647:             },
8648:             "notification-url": "https://packagist.org/downloads/",
8649:             "license": [
8650:                 "BSD-3-Clause"
8651:             ],
8652:             "authors": [
8653:                 {
8654:                     "name": "Sebastian Bergmann",
8655:                     "email": "sebastian@phpunit.de"
8656:                 }
8657:             ],
8658:             "description": "Provides functionality to handle HHVM/PHP environments",
8659:             "homepage": "https://github.com/sebastianbergmann/environment",
8660:             "keywords": [
8661:                 "Xdebug",
8662:                 "environment",
8663:                 "hhvm"
8664:             ],
8665:             "support": {
8666:                 "issues": "https://github.com/sebastianbergmann/environment/issues",
8667:                 "security": "https://github.com/sebastianbergmann/environment/security/policy",
8668:                 "source": "https://github.com/sebastianbergmann/environment/tree/8.1.2"
8669:             },
8670:             "funding": [
8671:                 {
8672:                     "url": "https://github.com/sebastianbergmann",
8673:                     "type": "github"
8674:                 },
8675:                 {
8676:                     "url": "https://liberapay.com/sebastianbergmann",
8677:                     "type": "liberapay"
8678:                 },
8679:                 {
8680:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8681:                     "type": "thanks_dev"
8682:                 },
8683:                 {
8684:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/environment",
8685:                     "type": "tidelift"
8686:                 }
8687:             ],
8688:             "time": "2026-05-25T13:40:20+00:00"
8689:         },
8690:         {
8691:             "name": "sebastian/exporter",
8692:             "version": "7.0.3",
8693:             "source": {
8694:                 "type": "git",
8695:                 "url": "https://github.com/sebastianbergmann/exporter.git",
8696:                 "reference": "c5e21b5de653ce0a769fb36f5cdfcb5e7a32cf23"
8697:             },
8698:             "dist": {
8699:                 "type": "zip",
8700:                 "url": "https://api.github.com/repos/sebastianbergmann/exporter/zipball/c5e21b5de653ce0a769fb36f5cdfcb5e7a32cf23",
8701:                 "reference": "c5e21b5de653ce0a769fb36f5cdfcb5e7a32cf23",
8702:                 "shasum": ""
8703:             },
8704:             "require": {
8705:                 "ext-mbstring": "*",
8706:                 "php": ">=8.3",
8707:                 "sebastian/recursion-context": "^7.0.1"
8708:             },
8709:             "require-dev": {
8710:                 "phpunit/phpunit": "^12.5.25"
8711:             },
8712:             "type": "library",
8713:             "extra": {
8714:                 "branch-alias": {
8715:                     "dev-main": "7.0-dev"
8716:                 }
8717:             },
8718:             "autoload": {
8719:                 "classmap": [
8720:                     "src/"
8721:                 ]
8722:             },
8723:             "notification-url": "https://packagist.org/downloads/",
8724:             "license": [
8725:                 "BSD-3-Clause"
8726:             ],
8727:             "authors": [
8728:                 {
8729:                     "name": "Sebastian Bergmann",
8730:                     "email": "sebastian@phpunit.de"
8731:                 },
8732:                 {
8733:                     "name": "Jeff Welch",
8734:                     "email": "whatthejeff@gmail.com"
8735:                 },
8736:                 {
8737:                     "name": "Volker Dusch",
8738:                     "email": "github@wallbash.com"
8739:                 },
8740:                 {
8741:                     "name": "Adam Harvey",
8742:                     "email": "aharvey@php.net"
8743:                 },
8744:                 {
8745:                     "name": "Bernhard Schussek",
8746:                     "email": "bschussek@gmail.com"
8747:                 }
8748:             ],
8749:             "description": "Provides the functionality to export PHP variables for visualization",
8750:             "homepage": "https://www.github.com/sebastianbergmann/exporter",
8751:             "keywords": [
8752:                 "export",
8753:                 "exporter"
8754:             ],
8755:             "support": {
8756:                 "issues": "https://github.com/sebastianbergmann/exporter/issues",
8757:                 "security": "https://github.com/sebastianbergmann/exporter/security/policy",
8758:                 "source": "https://github.com/sebastianbergmann/exporter/tree/7.0.3"
8759:             },
8760:             "funding": [
8761:                 {
8762:                     "url": "https://github.com/sebastianbergmann",
8763:                     "type": "github"
8764:                 },
8765:                 {
8766:                     "url": "https://liberapay.com/sebastianbergmann",
8767:                     "type": "liberapay"
8768:                 },
8769:                 {
8770:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8771:                     "type": "thanks_dev"
8772:                 },
8773:                 {
8774:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/exporter",
8775:                     "type": "tidelift"
8776:                 }
8777:             ],
8778:             "time": "2026-05-20T04:37:17+00:00"
8779:         },
8780:         {
8781:             "name": "sebastian/global-state",
8782:             "version": "8.0.3",
8783:             "source": {
8784:                 "type": "git",
8785:                 "url": "https://github.com/sebastianbergmann/global-state.git",
8786:                 "reference": "b164d3274d6537ab462591c5755f76a8f5b1aae9"
8787:             },
8788:             "dist": {
8789:                 "type": "zip",
8790:                 "url": "https://api.github.com/repos/sebastianbergmann/global-state/zipball/b164d3274d6537ab462591c5755f76a8f5b1aae9",
8791:                 "reference": "b164d3274d6537ab462591c5755f76a8f5b1aae9",
8792:                 "shasum": ""
8793:             },
8794:             "require": {
8795:                 "php": ">=8.3",
8796:                 "sebastian/object-reflector": "^5.0",
8797:                 "sebastian/recursion-context": "^7.0.1"
8798:             },
8799:             "require-dev": {
8800:                 "ext-dom": "*",
8801:                 "phpunit/phpunit": "^12.5.28"
8802:             },
8803:             "type": "library",
8804:             "extra": {
8805:                 "branch-alias": {
8806:                     "dev-main": "8.0-dev"
8807:                 }
8808:             },
8809:             "autoload": {
8810:                 "classmap": [
8811:                     "src/"
8812:                 ]
8813:             },
8814:             "notification-url": "https://packagist.org/downloads/",
8815:             "license": [
8816:                 "BSD-3-Clause"
8817:             ],
8818:             "authors": [
8819:                 {
8820:                     "name": "Sebastian Bergmann",
8821:                     "email": "sebastian@phpunit.de"
8822:                 }
8823:             ],
8824:             "description": "Snapshotting of global state",
8825:             "homepage": "https://www.github.com/sebastianbergmann/global-state",
8826:             "keywords": [
8827:                 "global state"
8828:             ],
8829:             "support": {
8830:                 "issues": "https://github.com/sebastianbergmann/global-state/issues",
8831:                 "security": "https://github.com/sebastianbergmann/global-state/security/policy",
8832:                 "source": "https://github.com/sebastianbergmann/global-state/tree/8.0.3"
8833:             },
8834:             "funding": [
8835:                 {
8836:                     "url": "https://github.com/sebastianbergmann",
8837:                     "type": "github"
8838:                 },
8839:                 {
8840:                     "url": "https://liberapay.com/sebastianbergmann",
8841:                     "type": "liberapay"
8842:                 },
8843:                 {
8844:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8845:                     "type": "thanks_dev"
8846:                 },
8847:                 {
8848:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/global-state",
8849:                     "type": "tidelift"
8850:                 }
8851:             ],
8852:             "time": "2026-06-01T15:10:33+00:00"
8853:         },
8854:         {
8855:             "name": "sebastian/lines-of-code",
8856:             "version": "4.0.1",
8857:             "source": {
8858:                 "type": "git",
8859:                 "url": "https://github.com/sebastianbergmann/lines-of-code.git",
8860:                 "reference": "d543b8ef219dcd8da262cbb958639a96bedba10e"
8861:             },
8862:             "dist": {
8863:                 "type": "zip",
8864:                 "url": "https://api.github.com/repos/sebastianbergmann/lines-of-code/zipball/d543b8ef219dcd8da262cbb958639a96bedba10e",
8865:                 "reference": "d543b8ef219dcd8da262cbb958639a96bedba10e",
8866:                 "shasum": ""
8867:             },
8868:             "require": {
8869:                 "nikic/php-parser": "^5.7.0",
8870:                 "php": ">=8.3"
8871:             },
8872:             "require-dev": {
8873:                 "phpunit/phpunit": "^12.5.25"
8874:             },
8875:             "type": "library",
8876:             "extra": {
8877:                 "branch-alias": {
8878:                     "dev-main": "4.0-dev"
8879:                 }
8880:             },
8881:             "autoload": {
8882:                 "classmap": [
8883:                     "src/"
8884:                 ]
8885:             },
8886:             "notification-url": "https://packagist.org/downloads/",
8887:             "license": [
8888:                 "BSD-3-Clause"
8889:             ],
8890:             "authors": [
8891:                 {
8892:                     "name": "Sebastian Bergmann",
8893:                     "email": "sebastian@phpunit.de",
8894:                     "role": "lead"
8895:                 }
8896:             ],
8897:             "description": "Library for counting the lines of code in PHP source code",
8898:             "homepage": "https://github.com/sebastianbergmann/lines-of-code",
8899:             "support": {
8900:                 "issues": "https://github.com/sebastianbergmann/lines-of-code/issues",
8901:                 "security": "https://github.com/sebastianbergmann/lines-of-code/security/policy",
8902:                 "source": "https://github.com/sebastianbergmann/lines-of-code/tree/4.0.1"
8903:             },
8904:             "funding": [
8905:                 {
8906:                     "url": "https://github.com/sebastianbergmann",
8907:                     "type": "github"
8908:                 },
8909:                 {
8910:                     "url": "https://liberapay.com/sebastianbergmann",
8911:                     "type": "liberapay"
8912:                 },
8913:                 {
8914:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
8915:                     "type": "thanks_dev"
8916:                 },
8917:                 {
8918:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/lines-of-code",
8919:                     "type": "tidelift"
8920:                 }
8921:             ],
8922:             "time": "2026-05-19T16:22:07+00:00"
8923:         },
8924:         {
8925:             "name": "sebastian/object-enumerator",
8926:             "version": "7.0.0",
8927:             "source": {
8928:                 "type": "git",
8929:                 "url": "https://github.com/sebastianbergmann/object-enumerator.git",
8930:                 "reference": "1effe8e9b8e068e9ae228e542d5d11b5d16db894"
8931:             },
8932:             "dist": {
8933:                 "type": "zip",
8934:                 "url": "https://api.github.com/repos/sebastianbergmann/object-enumerator/zipball/1effe8e9b8e068e9ae228e542d5d11b5d16db894",
8935:                 "reference": "1effe8e9b8e068e9ae228e542d5d11b5d16db894",
8936:                 "shasum": ""
8937:             },
8938:             "require": {
8939:                 "php": ">=8.3",
8940:                 "sebastian/object-reflector": "^5.0",
8941:                 "sebastian/recursion-context": "^7.0"
8942:             },
8943:             "require-dev": {
8944:                 "phpunit/phpunit": "^12.0"
8945:             },
8946:             "type": "library",
8947:             "extra": {
8948:                 "branch-alias": {
8949:                     "dev-main": "7.0-dev"
8950:                 }
8951:             },
8952:             "autoload": {
8953:                 "classmap": [
8954:                     "src/"
8955:                 ]
8956:             },
8957:             "notification-url": "https://packagist.org/downloads/",
8958:             "license": [
8959:                 "BSD-3-Clause"
8960:             ],
8961:             "authors": [
8962:                 {
8963:                     "name": "Sebastian Bergmann",
8964:                     "email": "sebastian@phpunit.de"
8965:                 }
8966:             ],
8967:             "description": "Traverses array structures and object graphs to enumerate all referenced objects",
8968:             "homepage": "https://github.com/sebastianbergmann/object-enumerator/",
8969:             "support": {
8970:                 "issues": "https://github.com/sebastianbergmann/object-enumerator/issues",
8971:                 "security": "https://github.com/sebastianbergmann/object-enumerator/security/policy",
8972:                 "source": "https://github.com/sebastianbergmann/object-enumerator/tree/7.0.0"
8973:             },
8974:             "funding": [
8975:                 {
8976:                     "url": "https://github.com/sebastianbergmann",
8977:                     "type": "github"
8978:                 }
8979:             ],
8980:             "time": "2025-02-07T04:57:48+00:00"
8981:         },
8982:         {
8983:             "name": "sebastian/object-reflector",
8984:             "version": "5.0.0",
8985:             "source": {
8986:                 "type": "git",
8987:                 "url": "https://github.com/sebastianbergmann/object-reflector.git",
8988:                 "reference": "4bfa827c969c98be1e527abd576533293c634f6a"
8989:             },
8990:             "dist": {
8991:                 "type": "zip",
8992:                 "url": "https://api.github.com/repos/sebastianbergmann/object-reflector/zipball/4bfa827c969c98be1e527abd576533293c634f6a",
8993:                 "reference": "4bfa827c969c98be1e527abd576533293c634f6a",
8994:                 "shasum": ""
8995:             },
8996:             "require": {
8997:                 "php": ">=8.3"
8998:             },
8999:             "require-dev": {
9000:                 "phpunit/phpunit": "^12.0"
9001:             },
9002:             "type": "library",
9003:             "extra": {
9004:                 "branch-alias": {
9005:                     "dev-main": "5.0-dev"
9006:                 }
9007:             },
9008:             "autoload": {
9009:                 "classmap": [
9010:                     "src/"
9011:                 ]
9012:             },
9013:             "notification-url": "https://packagist.org/downloads/",
9014:             "license": [
9015:                 "BSD-3-Clause"
9016:             ],
9017:             "authors": [
9018:                 {
9019:                     "name": "Sebastian Bergmann",
9020:                     "email": "sebastian@phpunit.de"
9021:                 }
9022:             ],
9023:             "description": "Allows reflection of object attributes, including inherited and non-public ones",
9024:             "homepage": "https://github.com/sebastianbergmann/object-reflector/",
9025:             "support": {
9026:                 "issues": "https://github.com/sebastianbergmann/object-reflector/issues",
9027:                 "security": "https://github.com/sebastianbergmann/object-reflector/security/policy",
9028:                 "source": "https://github.com/sebastianbergmann/object-reflector/tree/5.0.0"
9029:             },
9030:             "funding": [
9031:                 {
9032:                     "url": "https://github.com/sebastianbergmann",
9033:                     "type": "github"
9034:                 }
9035:             ],
9036:             "time": "2025-02-07T04:58:17+00:00"
9037:         },
9038:         {
9039:             "name": "sebastian/recursion-context",
9040:             "version": "7.0.1",
9041:             "source": {
9042:                 "type": "git",
9043:                 "url": "https://github.com/sebastianbergmann/recursion-context.git",
9044:                 "reference": "0b01998a7d5b1f122911a66bebcb8d46f0c82d8c"
9045:             },
9046:             "dist": {
9047:                 "type": "zip",
9048:                 "url": "https://api.github.com/repos/sebastianbergmann/recursion-context/zipball/0b01998a7d5b1f122911a66bebcb8d46f0c82d8c",
9049:                 "reference": "0b01998a7d5b1f122911a66bebcb8d46f0c82d8c",
9050:                 "shasum": ""
9051:             },
9052:             "require": {
9053:                 "php": ">=8.3"
9054:             },
9055:             "require-dev": {
9056:                 "phpunit/phpunit": "^12.0"
9057:             },
9058:             "type": "library",
9059:             "extra": {
9060:                 "branch-alias": {
9061:                     "dev-main": "7.0-dev"
9062:                 }
9063:             },
9064:             "autoload": {
9065:                 "classmap": [
9066:                     "src/"
9067:                 ]
9068:             },
9069:             "notification-url": "https://packagist.org/downloads/",
9070:             "license": [
9071:                 "BSD-3-Clause"
9072:             ],
9073:             "authors": [
9074:                 {
9075:                     "name": "Sebastian Bergmann",
9076:                     "email": "sebastian@phpunit.de"
9077:                 },
9078:                 {
9079:                     "name": "Jeff Welch",
9080:                     "email": "whatthejeff@gmail.com"
9081:                 },
9082:                 {
9083:                     "name": "Adam Harvey",
9084:                     "email": "aharvey@php.net"
9085:                 }
9086:             ],
9087:             "description": "Provides functionality to recursively process PHP variables",
9088:             "homepage": "https://github.com/sebastianbergmann/recursion-context",
9089:             "support": {
9090:                 "issues": "https://github.com/sebastianbergmann/recursion-context/issues",
9091:                 "security": "https://github.com/sebastianbergmann/recursion-context/security/policy",
9092:                 "source": "https://github.com/sebastianbergmann/recursion-context/tree/7.0.1"
9093:             },
9094:             "funding": [
9095:                 {
9096:                     "url": "https://github.com/sebastianbergmann",
9097:                     "type": "github"
9098:                 },
9099:                 {
9100:                     "url": "https://liberapay.com/sebastianbergmann",
9101:                     "type": "liberapay"
9102:                 },
9103:                 {
9104:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
9105:                     "type": "thanks_dev"
9106:                 },
9107:                 {
9108:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/recursion-context",
9109:                     "type": "tidelift"
9110:                 }
9111:             ],
9112:             "time": "2025-08-13T04:44:59+00:00"
9113:         },
9114:         {
9115:             "name": "sebastian/type",
9116:             "version": "6.0.4",
9117:             "source": {
9118:                 "type": "git",
9119:                 "url": "https://github.com/sebastianbergmann/type.git",
9120:                 "reference": "82ff822c2edc46724be9f7411d3163021f602773"
9121:             },
9122:             "dist": {
9123:                 "type": "zip",
9124:                 "url": "https://api.github.com/repos/sebastianbergmann/type/zipball/82ff822c2edc46724be9f7411d3163021f602773",
9125:                 "reference": "82ff822c2edc46724be9f7411d3163021f602773",
9126:                 "shasum": ""
9127:             },
9128:             "require": {
9129:                 "php": ">=8.3"
9130:             },
9131:             "require-dev": {
9132:                 "phpunit/phpunit": "^12.5.25"
9133:             },
9134:             "type": "library",
9135:             "extra": {
9136:                 "branch-alias": {
9137:                     "dev-main": "6.0-dev"
9138:                 }
9139:             },
9140:             "autoload": {
9141:                 "classmap": [
9142:                     "src/"
9143:                 ]
9144:             },
9145:             "notification-url": "https://packagist.org/downloads/",
9146:             "license": [
9147:                 "BSD-3-Clause"
9148:             ],
9149:             "authors": [
9150:                 {
9151:                     "name": "Sebastian Bergmann",
9152:                     "email": "sebastian@phpunit.de",
9153:                     "role": "lead"
9154:                 }
9155:             ],
9156:             "description": "Collection of value objects that represent the types of the PHP type system",
9157:             "homepage": "https://github.com/sebastianbergmann/type",
9158:             "support": {
9159:                 "issues": "https://github.com/sebastianbergmann/type/issues",
9160:                 "security": "https://github.com/sebastianbergmann/type/security/policy",
9161:                 "source": "https://github.com/sebastianbergmann/type/tree/6.0.4"
9162:             },
9163:             "funding": [
9164:                 {
9165:                     "url": "https://github.com/sebastianbergmann",
9166:                     "type": "github"
9167:                 },
9168:                 {
9169:                     "url": "https://liberapay.com/sebastianbergmann",
9170:                     "type": "liberapay"
9171:                 },
9172:                 {
9173:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
9174:                     "type": "thanks_dev"
9175:                 },
9176:                 {
9177:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/type",
9178:                     "type": "tidelift"
9179:                 }
9180:             ],
9181:             "time": "2026-05-20T06:45:45+00:00"
9182:         },
9183:         {
9184:             "name": "sebastian/version",
9185:             "version": "6.0.1",
9186:             "source": {
9187:                 "type": "git",
9188:                 "url": "https://github.com/sebastianbergmann/version.git",
9189:                 "reference": "9e935a16187e14f39b0e79910dabd614972cdb26"
9190:             },
9191:             "dist": {
9192:                 "type": "zip",
9193:                 "url": "https://api.github.com/repos/sebastianbergmann/version/zipball/9e935a16187e14f39b0e79910dabd614972cdb26",
9194:                 "reference": "9e935a16187e14f39b0e79910dabd614972cdb26",
9195:                 "shasum": ""
9196:             },
9197:             "require": {
9198:                 "php": ">=8.3"
9199:             },
9200:             "type": "library",
9201:             "extra": {
9202:                 "branch-alias": {
9203:                     "dev-main": "6.0-dev"
9204:                 }
9205:             },
9206:             "autoload": {
9207:                 "classmap": [
9208:                     "src/"
9209:                 ]
9210:             },
9211:             "notification-url": "https://packagist.org/downloads/",
9212:             "license": [
9213:                 "BSD-3-Clause"
9214:             ],
9215:             "authors": [
9216:                 {
9217:                     "name": "Sebastian Bergmann",
9218:                     "email": "sebastian@phpunit.de",
9219:                     "role": "lead"
9220:                 }
9221:             ],
9222:             "description": "Library that helps with managing the version number of Git-hosted PHP projects",
9223:             "homepage": "https://github.com/sebastianbergmann/version",
9224:             "support": {
9225:                 "issues": "https://github.com/sebastianbergmann/version/issues",
9226:                 "security": "https://github.com/sebastianbergmann/version/security/policy",
9227:                 "source": "https://github.com/sebastianbergmann/version/tree/6.0.1"
9228:             },
9229:             "funding": [
9230:                 {
9231:                     "url": "https://github.com/sebastianbergmann",
9232:                     "type": "github"
9233:                 },
9234:                 {
9235:                     "url": "https://liberapay.com/sebastianbergmann",
9236:                     "type": "liberapay"
9237:                 },
9238:                 {
9239:                     "url": "https://thanks.dev/u/gh/sebastianbergmann",
9240:                     "type": "thanks_dev"
9241:                 },
9242:                 {
9243:                     "url": "https://tidelift.com/funding/github/packagist/sebastian/version",
9244:                     "type": "tidelift"
9245:                 }
9246:             ],
9247:             "time": "2026-10-04T05:50:08+00:00"
9248:         },
9249:         {
9250:             "name": "staabm/side-effects-detector",
9251:             "version": "1.0.5",
9252:             "source": {
9253:                 "type": "git",
9254:                 "url": "https://github.com/staabm/side-effects-detector.git",
9255:                 "reference": "d8334211a140ce329c13726d4a715adbddd0a163"
9256:             },
9257:             "dist": {
9258:                 "type": "zip",
9259:                 "url": "https://api.github.com/repos/staabm/side-effects-detector/zipball/d8334211a140ce329c13726d4a715adbddd0a163",
9260:                 "reference": "d8334211a140ce329c13726d4a715adbddd0a163",
9261:                 "shasum": ""
9262:             },
9263:             "require": {
9264:                 "ext-tokenizer": "*",
9265:                 "php": "^7.4 || ^8.0"
9266:             },
9267:             "require-dev": {
9268:                 "phpstan/extension-installer": "^1.4.3",
9269:                 "phpstan/phpstan": "^1.12.6",
9270:                 "phpunit/phpunit": "^9.6.21",
9271:                 "symfony/var-dumper": "^5.4.43",
9272:                 "tomasvotruba/type-coverage": "1.0.0",
9273:                 "tomasvotruba/unused-public": "1.0.0"
9274:             },
9275:             "type": "library",
9276:             "autoload": {
9277:                 "classmap": [
9278:                     "lib/"
9279:                 ]
9280:             },
9281:             "notification-url": "https://packagist.org/downloads/",
9282:             "license": [
9283:                 "MIT"
9284:             ],
9285:             "description": "A static analysis tool to detect side effects in PHP code",
9286:             "keywords": [
9287:                 "static analysis"
9288:             ],
9289:             "support": {
9290:                 "issues": "https://github.com/staabm/side-effects-detector/issues",
9291:                 "source": "https://github.com/staabm/side-effects-detector/tree/1.0.5"
9292:             },
9293:             "funding": [
9294:                 {
9295:                     "url": "https://github.com/staabm",
9296:                     "type": "github"
9297:                 }
9298:             ],
9299:             "time": "2024-10-20T05:08:20+00:00"
9300:         },
9301:         {
9302:             "name": "symfony/yaml",
9303:             "version": "v7.4.21",
9304:             "source": {
9305:                 "type": "git",
9306:                 "url": "https://github.com/symfony/yaml.git",
9307:                 "reference": "044907929c7999814378be22da34722c7a86bad4"
9308:             },
9309:             "dist": {
9310:                 "type": "zip",
9311:                 "url": "https://api.github.com/repos/symfony/yaml/zipball/044907929c7999814378be22da34722c7a86bad4",
9312:                 "reference": "044907929c7999814378be22da34722c7a86bad4",
9313:                 "shasum": ""
9314:             },
9315:             "require": {
9316:                 "php": ">=8.2",
9317:                 "symfony/deprecation-contracts": "^2.5|^3",
9318:                 "symfony/polyfill-ctype": "^1.8"
9319:             },
9320:             "conflict": {
9321:                 "symfony/console": "<6.4"
9322:             },
9323:             "require-dev": {
9324:                 "symfony/console": "^6.4|^7.0|^8.0"
9325:             },
9326:             "bin": [
9327:                 "Resources/bin/yaml-lint"
9328:             ],
9329:             "type": "library",
9330:             "autoload": {
9331:                 "psr-4": {
9332:                     "Symfony\\Component\\Yaml\\": ""
9333:                 },
9334:                 "exclude-from-classmap": [
9335:                     "/Tests/"
9336:                 ]
9337:             },
9338:             "notification-url": "https://packagist.org/downloads/",
9339:             "license": [
9340:                 "MIT"
9341:             ],
9342:             "authors": [
9343:                 {
9344:                     "name": "Fabien Potencier",
9345:                     "email": "fabien@symfony.com"
9346:                 },
9347:                 {
9348:                     "name": "Symfony Community",
9349:                     "homepage": "https://symfony.com/contributors"
9350:                 }
9351:             ],
9352:             "description": "Loads and dumps YAML files",
9353:             "homepage": "https://symfony.com",
9354:             "support": {
9355:                 "source": "https://github.com/symfony/yaml/tree/v7.4.21"
9356:             },
9357:             "funding": [
9358:                 {
9359:                     "url": "https://symfony.com/sponsor",
9360:                     "type": "custom"
9361:                 },
9362:                 {
9363:                     "url": "https://github.com/fabpot",
9364:                     "type": "github"
9365:                 },
9366:                 {
9367:                     "url": "https://github.com/nicolas-grekas",
9368:                     "type": "github"
9369:                 },
9370:                 {
9371:                     "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
9372:                     "type": "tidelift"
9373:                 }
9374:             ],
9375:             "time": "2026-10-12T12:34:30+00:00"
9376:         },
9377:         {
9378:             "name": "theseer/tokenizer",
9379:             "version": "2.0.1",
9380:             "source": {
9381:                 "type": "git",
9382:                 "url": "https://github.com/theseer/tokenizer.git",
9383:                 "reference": "7989e43bf381af0eac72e4f0ca5bcbfa81658be4"
9384:             },
9385:             "dist": {
9386:                 "type": "zip",
9387:                 "url": "https://api.github.com/repos/theseer/tokenizer/zipball/7989e43bf381af0eac72e4f0ca5bcbfa81658be4",
9388:                 "reference": "7989e43bf381af0eac72e4f0ca5bcbfa81658be4",
9389:                 "shasum": ""
9390:             },
9391:             "require": {
9392:                 "ext-dom": "*",
9393:                 "ext-tokenizer": "*",
9394:                 "ext-xmlwriter": "*",
9395:                 "php": "^8.1"
9396:             },
9397:             "type": "library",
9398:             "autoload": {
9399:                 "classmap": [
9400:                     "src/"
9401:                 ]
9402:             },
9403:             "notification-url": "https://packagist.org/downloads/",
9404:             "license": [
9405:                 "BSD-3-Clause"
9406:             ],
9407:             "authors": [
9408:                 {
9409:                     "name": "Arne Blankerts",
9410:                     "email": "arne@blankerts.de",
9411:                     "role": "Developer"
9412:                 }
9413:             ],
9414:             "description": "A small library for converting tokenized PHP source code into XML and potentially other formats",
9415:             "support": {
9416:                 "issues": "https://github.com/theseer/tokenizer/issues",
9417:                 "source": "https://github.com/theseer/tokenizer/tree/2.0.1"
9418:             },
9419:             "funding": [
9420:                 {
9421:                     "url": "https://github.com/theseer",
9422:                     "type": "github"
9423:                 }
9424:             ],
9425:             "time": "2025-12-08T11:19:18+00:00"
9426:         }
9427:     ],
9428:     "aliases": [],
9429:     "minimum-stability": "stable",
9430:     "stability-flags": {},
9431:     "prefer-stable": true,
9432:     "prefer-lowest": false,
9433:     "platform": {
9434:         "php": "^8.3"
9435:     },
9436:     "platform-dev": {},
9437:     "plugin-api-version": "2.6.0"
9438: }
```

## File: bags-platform/backend/phpunit.xml
```xml
 1: <?xml version="1.0" encoding="UTF-8"?>
 2: <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
 3:          xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
 4:          bootstrap="vendor/autoload.php"
 5:          colors="true"
 6: >
 7:     <testsuites>
 8:         <testsuite name="Unit">
 9:             <directory>tests/Unit</directory>
10:         </testsuite>
11:         <testsuite name="Feature">
12:             <directory>tests/Feature</directory>
13:         </testsuite>
14:     </testsuites>
15:     <source>
16:         <include>
17:             <directory>app</directory>
18:         </include>
19:     </source>
20:     <php>
21:         <env name="APP_ENV" value="testing"/>
22:         <env name="APP_MAINTENANCE_DRIVER" value="file"/>
23:         <env name="BCRYPT_ROUNDS" value="4"/>
24:         <env name="BROADCAST_CONNECTION" value="null"/>
25:         <env name="CACHE_STORE" value="array"/>
26:         <env name="DB_CONNECTION" value="pgsql" force="true"/>
27:         <env name="DB_DATABASE" value="alfarid_testing" force="true"/>
28:         <env name="DB_URL" value="" force="true"/>
29:         <env name="MAIL_MAILER" value="array"/>
30:         <env name="QUEUE_CONNECTION" value="sync"/>
31:         <env name="SESSION_DRIVER" value="array"/>
32:         <env name="PULSE_ENABLED" value="false"/>
33:         <env name="TELESCOPE_ENABLED" value="false"/>
34:         <env name="NIGHTWATCH_ENABLED" value="false"/>
35:     </php>
36: </phpunit>
```

## File: bags-platform/docs/api.md
```markdown
 1: # API Notes
 2: 
 3: ## Admin Categories (`/api/v1/admin/categories`)
 4: 
 5: - `GET /categories` — list with pagination (`per_page`, default 15, max 100), filters: `search`, `parent_id` (`root` for top-level), `is_active`. Use `?tree=1` for the full nested tree without pagination.
 6: - `GET /categories/{id}` — single category with parent, children and products count.
 7: - `POST /categories` — create (201). `slug` is generated automatically from `name_en` when omitted.
 8: - `PUT /categories/reorder` — body: `items: [{id, parent_id, sort_order}]`.
 9: - `DELETE /categories/{id}` — fails with 422 when the category has children or linked products.
10: - Maximum category depth is 3 levels; violations return 422.
11: 
12: ## Admin Brands (`/api/v1/admin/brands`)
13: 
14: - Same pattern as categories, without parent/tree/reorder. Logo is uploaded in the `logo` field
15:   (jpg, jpeg, png, webp, **svg** allowed) and returned as `logo_url`.
16: - Deleting a brand linked to products fails with 422.
17: 
18: ## Admin Products (`/api/v1/admin/products`)
19: 
20: - `GET /products` — filters: `search` (names, `code`, variant `sku`/`barcode`), `category_id`
21:   (add `include_children=1` to include subcategories), `brand_id`, `status`, `sales_channel`,
22:   `is_featured`; `sort`: `latest` (default), `oldest`, `name`. Response includes
23:   `variants_count`, `min_retail_price`, `max_retail_price`.
24: - `POST /products` — creates the product as `draft` first and auto-assigns `code` (`P00001`…).
25:   Optional `variants: [...]` can be sent with creation; a variant without `barcode` gets an
26:   internal EAN-13 barcode (prefix `2`), and without `sku` gets `{product_code}-{n}`.
27: - Publishing (`status: published`) requires the `products.publish` permission and a publishable
28:   product: at least one active variant, retail/wholesale prices on all active variants according
29:   to `sales_channel`, at least one image, and an active category. Violations return 422 with
30:   `errors.status` listing every problem; missing publish permission returns 403.
31: - `cost_price` is only visible to users with the `products.view_cost` permission.
32: - `DELETE /products/{id}` — soft-deletes the product and its variants.
33: 
34: ## Product Variants (`/api/v1/admin/products/{product}/variants`)
35: 
36: - Nested and scoped: a variant id under the wrong product returns 404.
37: - `GET` returns all variants without pagination. Create/update/delete require `products.update`
38:   (editing variants is editing the product); read requires `products.view`.
39: - The combination (color_name_en, size, capacity) must be unique per product — duplicates return 422.
40: - A variant without `sku` gets `{product_code}-{NN}` (e.g. `P00001-01`); without `barcode` it gets
41:   an internal EAN-13 barcode (prefix `2`).
42: - The last active variant of a published product cannot be deleted or deactivated (422).
43: - Sending `cost_price` without the `products.view_cost` permission is silently ignored on write.
44: 
45: ## Product Media (`/api/v1/admin/products/{product}/media`)
46: 
47: - `GET` lists the product's media **and** its variants' media ordered by `order_column`
48:   (variant media items carry `variant_id`).
49: - `POST` with `collection=images` + `files[]` (max 10, jpg/png/webp, 5MB each), optionally with
50:   `variant_id` to attach the images to a variant instead. With `collection=video` + `file`
51:   (mp4/webm/quicktime, 50MB) — video is product-only; sending it with `variant_id` returns 422.
52: - `PUT /media/reorder` with `ids: [...]` in the desired order.
53: - `DELETE /media/{media}` — the last image of a published product cannot be deleted (422).
54: 
55: ### Updating with a file upload (important)
56: 
57: PHP does not parse uploaded files from `multipart/form-data` when the request method is `PUT`.
58: Therefore, to update a category (or any resource) **with an image**, the frontend must send a
59: `POST` request to `/categories/{id}` with `_method=PUT` included in the form body.
60: Laravel converts it to a `PUT` request automatically (method spoofing), and validation,
61: authorization and file handling then work as expected.
```

## File: bags-platform/docs/phase2-audit.md
```markdown
 1: # مراجعة المرحلة الثانية: الكتالوج
 2: 
 3: تاريخ المراجعة: 15 أكتوبر 2026.
 4: 
 5: الخطة كانت منفذة بدرجة كبيرة، لكن لم تكن مطابقة بالكامل. تم إصلاح النواقص التالية والتحقق من السلوك الحالي بالاختبارات.
 6: 
 7: ## نتيجة مراجعة المهام
 8: 
 9: | المهمة | النتيجة بعد المراجعة |
10: | --- | --- |
11: | 0 — إعداد الميديا | إصلاح composer.lock وتثبيت spatie/laravel-medialibrary 11.23.9 وتبعياتها؛ إضافة MEDIA_DISK=public للبيئة المحلية و.env.example؛ حد المكتبة 50MB؛ تفعيل GD وEXIF وإنشاء public/storage. |
12: | 1 — Enums | ProductStatus وSalesChannel والقيم والدوال المطلوبة موجودة. |
13: | 2 — Migrations | الجداول والحقول والعلاقات والفهارس المطلوبة موجودة؛ إعادة البناء والـ seed نجحا على PostgreSQL. لا أعمدة stock أو quantity في الكتالوج. |
14: | 3 — Models | العلاقات وSoftDeletes وcasts ومجموعات الميديا وتحويلات الصور المطلوبة موجودة. |
15: | 4 — الصلاحيات | الصلاحيات الأربع عشرة موجودة، وتُمنح لـ super-admin على guard اسمه staff، مطابق للمرحلة الأولى. أُضيف transaction لكتابات Seeder الصلاحيات. |
16: | 5 — الأدوات المشتركة | BusinessException وSlugService وBarcodeGenerator وMediaResource موجودة. أُضيف حد أدنى 1 لـ per_page مع الاحتفاظ بالافتراضي 15 والحد الأعلى 100. |
17: | 6 — الأقسام | الإنشاء والتعديل والصور والحذف والفلاتر والشجرة والترتيب والتحقق من الدورات وارتفاع الشجرة موجودة. أُضيفت transactions لحفظ القسم وصورته، والتحقق من الأقسام غير المحذوفة ومنع تكرار العناصر أثناء الترتيب. |
18: | 7 — البراندات | CRUD والفلاتر والصلاحيات ومنع الحذف عند وجود منتجات ورفع SVG موجودة. أُضيفت transactions لحفظ البراند وشعاره. |
19: | 8 — المنتجات | CRUD والفلاتر والبحث بالباركود والترقيم التلقائي وشروط النشر والصلاحيات والحذف الناعم موجودة. أُصلح slug الفارغ عند التعديل، وتم منع تجاوز صلاحية التكلفة عبر variants المرسلة أثناء إنشاء المنتج. |
20: | 9 — المتغيرات | الترقيم والباركود ومنع التركيبات المكررة والصلاحيات وإخفاء التكلفة وحماية آخر متغير نشط والربط بالمنتج موجودة. إرسال SKU فارغ في التعديل يرجع 422 بدل خطأ قاعدة بيانات. |
21: | 10 — الميديا | الرفع والعرض والترتيب والحذف والتحقق من الملكية وحماية آخر صورة موجودة. نُقل منطق التنفيذ من Controller إلى ProductMediaService، وأُضيفت transactions للرفع والترتيب. المتغير المحذوف لا يُقبل في طلب الرفع. |
22: | 11 — Routes | جميع مسارات الكتالوج المطلوبة موجودة تحت api/v1/admin مع auth:sanctum وauth.staff، وreorder قبل apiResource، وربط variants بالمنتج scoped. |
23: | 12 — Factories وSeeders | المصانع الأربعة موجودة؛ البيانات التجريبية تنتج 7 أقسام و4 براندات و10 منتجات draft، لكل منتج 2–3 متغيرات، عبر ProductService. أُضيف transaction لـ CatalogSeeder، واختبار لإعادة تشغيله بدون تكرار البيانات. |
24: | 13 — الاختبارات | اختبارات الخطة موجودة وناجحة؛ أُضيف 19 اختبارًا للمشكلات المكتشفة والحماية والبيانات التجريبية. |
25: 
26: ## إصلاحات إضافية في التحقق من الطلبات
27: 
28: - تقييد مفاتيح كل عنصر في variants عند إنشاء المنتج، لمنع تمرير product_id أو id أو حقول غير مسموحة داخل المصفوفة.
29: - رفض SKU أو barcode المكرر بين متغيرات الطلب نفسه بـ422 قبل الوصول إلى قيود قاعدة البيانات.
30: - تجاهل cost_price بدون products.view_cost في إنشاء المنتج بمتغيراته، وكذلك مسارات المتغيرات المستقلة.
31: - اختبارات تؤكد تراجع تغييرات القسم والبراند عند رفض الملف المرفوع، وتراجع ترتيب الأقسام عند فشل أحد العناصر.
32: 
33: ## التحقق المنفذ
34: 
35: - php artisan test --compact: نجح 103 اختبارات و524 assertion.
36: - اختبارات الكتالوج وحدها: نجح 36 اختبارًا.
37: - php artisan migrate:fresh --seed --force: نجح على قاعدة alfarid_testing، للحفاظ على بيانات قاعدة alfarid المحلية.
38: - php artisan route:list --path=api/v1/admin --except-vendor: ظهرت جميع المسارات المطلوبة.
39: - composer validate --no-check-publish: نجح.
40: - composer check-platform-reqs: نجح.
41: - composer install --dry-run --no-interaction: لا مكتبات ناقصة أو تغييرات مطلوبة.
42: - Laravel Pint وgit diff --check: نجحا.
43: 
44: ## فروق عن النص الأصلي وحدود التحقق
45: 
46: - المشروع القائم يستخدم Laravel 13.36.0، بينما مقدمة الخطة تفترض Laravel 11/12. تم الحفاظ على إصدار المشروع الموجود؛ لم يتم تنفيذ downgrade للإطار أو تغيير مصادقة المرحلة الأولى.
47: - migration الميديا موجودة قبل migrations الكتالوج زمنيًا؛ جدولها متعدد الأشكال ولا يعتمد على foreign key لجداول المنتجات، وإعادة البناء نجحت. تم الحفاظ على أسماء migrations الموجودة.
48: - لا يمكن إثبات أن المنفذ السابق شغّل migrate:fresh --seed بعد كل مهمة أو التزم بترتيب العمل تاريخيًا. ما تم التحقق منه هو الكود الحالي والتشغيل الفعلي بعد الإصلاح.
49: - النص يسمح بـSKU فارغ في تحديث المتغير رغم أن العمود غير nullable؛ تم حسم التعارض بإرجاع خطأ validation 422 مع الاحتفاظ بالـSKU الحالي.
50: - إعدادات PHP المحلية المستخدمة في المراجعة عُدلت في C:/php8/php.ini: تفعيل gd وexif، upload_max_filesize=50M، post_max_size=64M. لم يتم اختبار إعدادات خادم نشر خارجي.
51: - توثيق POST مع _method=PUT لرفع الملفات أثناء التعديل موجود بالفعل في docs/api.md.
52: - لم تُضف وظائف مخزون أو قوائم أسعار أو خصومات.
```




# Instruction
تعليمات للمنفّذ (اقرأها الأول)
إنت هتنفذ المرحلة الثانية في مشروع Laravel 11/12 API. المرحلة الأولى خلصت، وده معناه إن الحاجات دي موجودة: Sanctum، وجدول users للموظفين، وmiddleware اسمه auth.staff، وباكدج spatie/laravel-permission، والفروع، والإعدادات، والملف routes/api/admin.php متسجل بـ prefix api/v1/admin.

قواعد لازم تلتزم بيها:

نفّذ المهام بالترتيب، ومتبدأش مهمة قبل ما اللي قبلها تخلص وتشتغل.
متسألش أي سؤال. لو فيه حاجة مش مكتوبة، اختار أبسط حل يتماشى مع الخطة.
منطق البيزنس كله بيتحط في app/Services. الـ Controller شغلته يستقبل الطلب ويعمل validate عن طريق FormRequest، وينادي الـ Service، ويرجّع Resource.
متعملش أي حاجة تخص المخزون أو الكميات أو قوائم الأسعار أو الخصومات. دي ليها مراحل تانية. الكتالوج فيه بس بيانات المنتج والسعر الأساسي للمتغير.
أي عملية بتكتب في أكتر من جدول لازم تكون جوه DB::transaction.
بعد كل مهمة شغّل php artisan migrate:fresh --seed واتأكد إن مفيش أخطاء.
المهمة 0: تثبيت الباكدجات
Copycomposer require spatie/laravel-medialibrary
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
php artisan storage:link
التعديلات المطلوبة:

في .env ضيف MEDIA_DISK=public.
في config/media-library.php خلّي 'max_file_size' => 1024 * 1024 * 50 (يعني 50MB).
لازم يكون امتداد PHP gd أو imagick مفعّل.
المهمة 1: الـ Enums
app/Enums/ProductStatus.php

Copy<?php
namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
app/Enums/SalesChannel.php

Copy<?php
namespace App\Enums;

enum SalesChannel: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Both = 'both';

    public function allowsRetail(): bool { return $this !== self::Wholesale; }
    public function allowsWholesale(): bool { return $this !== self::Retail; }
}
المهمة 2: الـ Migrations
اعملهم بالترتيب ده بالظبط، لأن فيه foreign keys بتعتمد على بعض.

1) create_brands_table

CopySchema::create('brands', function (Blueprint $table) {
    $table->id();
    $table->string('name_ar');
    $table->string('name_en');
    $table->string('slug')->unique();
    $table->text('description_ar')->nullable();
    $table->text('description_en')->nullable();
    $table->unsignedInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->softDeletes();
});
2) create_categories_table

CopySchema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
    $table->string('name_ar');
    $table->string('name_en');
    $table->string('slug')->unique();
    $table->text('description_ar')->nullable();
    $table->text('description_en')->nullable();
    $table->unsignedInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->softDeletes();
    $table->index(['parent_id', 'sort_order']);
});
3) create_products_table

CopySchema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('code')->nullable()->unique();          // P00001 بيتولد تلقائي
    $table->foreignId('category_id')->constrained()->restrictOnDelete();
    $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
    $table->unsignedBigInteger('supplier_id')->nullable()->index(); // الـ FK هيتضاف في مرحلة الموردين
    $table->string('name_ar');
    $table->string('name_en');
    $table->string('slug')->unique();
    $table->longText('description_ar')->nullable();
    $table->longText('description_en')->nullable();
    $table->string('material_ar')->nullable();
    $table->string('material_en')->nullable();
    $table->decimal('length_cm', 8, 2)->nullable();
    $table->decimal('width_cm', 8, 2)->nullable();
    $table->decimal('height_cm', 8, 2)->nullable();
    $table->decimal('weight_kg', 8, 3)->nullable();
    $table->unsignedSmallInteger('warranty_months')->nullable();
    $table->string('warranty_details')->nullable();
    $table->string('country_of_origin')->nullable();
    $table->string('status')->default('draft');            // ProductStatus
    $table->string('sales_channel')->default('both');      // SalesChannel
    $table->boolean('is_featured')->default(false);
    $table->timestamp('published_at')->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['status', 'sales_channel']);
});
4) create_product_variants_table

CopySchema::create('product_variants', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->string('sku')->unique();
    $table->string('barcode')->nullable()->unique();
    $table->string('color_name_ar')->nullable();
    $table->string('color_name_en')->nullable();
    $table->string('color_hex', 7)->nullable();            // #000000
    $table->string('size')->nullable();                     // S, M, L, 20 inch
    $table->string('capacity')->nullable();                 // 40L
    $table->decimal('cost_price', 12, 2)->default(0);
    $table->decimal('retail_price', 12, 2)->nullable();
    $table->decimal('wholesale_price', 12, 2)->nullable();
    $table->unsignedInteger('reorder_level')->default(0);
    $table->decimal('weight_kg', 8, 3)->nullable();         // لو فاضي ياخد وزن المنتج
    $table->unsignedInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->softDeletes();
});
5) فيه migration تاني جاهز اسمه create_media_table، وده اتعمل له publish في المهمة 0.

متضيفش أي عمود للمخزون (stock أو quantity). المخزون لكل فرع هيتعمل في جدول منفصل في مرحلة المخزون.

المهمة 3: الـ Models
app/Models/Brand.php

Copyclass Brand extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    protected $fillable = ['name_ar','name_en','slug','description_ar','description_en','sort_order','is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function products() { return $this->hasMany(Product::class); }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile()
            ->acceptsMimeTypes(['image/jpeg','image/png','image/webp','image/svg+xml']);
    }
}
app/Models/Category.php: هو هو زي Brand، بس فيه الحاجات دي:

Copyprotected $fillable = ['parent_id','name_ar','name_en','slug','description_ar','description_en','sort_order','is_active'];

public function parent()   { return $this->belongsTo(Category::class, 'parent_id'); }
public function children() { return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order'); }
public function products() { return $this->hasMany(Product::class); }

public function registerMediaCollections(): void
{
    $this->addMediaCollection('image')->singleFile()
        ->acceptsMimeTypes(['image/jpeg','image/png','image/webp']);
}

// بيرجّع IDs كل الأقسام اللي تحته على أي مستوى
public function descendantIds(): array
{
    $ids = [];
    $queue = [$this->id];
    while ($queue) {
        $children = Category::whereIn('parent_id', $queue)->pluck('id')->all();
        $ids = array_merge($ids, $children);
        $queue = $children;
    }
    return $ids;
}

public function depth(): int
{
    $depth = 1; $p = $this->parent;
    while ($p) { $depth++; $p = $p->parent; }
    return $depth;
}
Copy
app/Models/Product.php

Copyclass Product extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    protected $fillable = [
        'code','category_id','brand_id','supplier_id','name_ar','name_en','slug',
        'description_ar','description_en','material_ar','material_en',
        'length_cm','width_cm','height_cm','weight_kg','warranty_months','warranty_details',
        'country_of_origin','status','sales_channel','is_featured','published_at','created_by',
    ];

    protected $casts = [
        'status' => ProductStatus::class,
        'sales_channel' => SalesChannel::class,
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
        'length_cm' => 'decimal:2', 'width_cm' => 'decimal:2', 'height_cm' => 'decimal:2',
        'weight_kg' => 'decimal:3',
    ];

    public function category() { return $this->belongsTo(Category::class); }
    public function brand()    { return $this->belongsTo(Brand::class); }
    public function variants() { return $this->hasMany(ProductVariant::class)->orderBy('sort_order'); }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->acceptsMimeTypes(['image/jpeg','image/png','image/webp']);
        $this->addMediaCollection('video')->singleFile()
            ->acceptsMimeTypes(['video/mp4','video/webm','video/quicktime']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')
            ->performOnCollections('images')->nonQueued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')
            ->performOnCollections('images')->nonQueued();
    }
}
Copy
الـ imports: Spatie\Image\Enums\Fit و Spatie\MediaLibrary\MediaCollections\Models\Media و Spatie\MediaLibrary\HasMedia و Spatie\MediaLibrary\InteractsWithMedia.

app/Models/ProductVariant.php

Copyclass ProductVariant extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    protected $fillable = [
        'product_id','sku','barcode','color_name_ar','color_name_en','color_hex','size','capacity',
        'cost_price','retail_price','wholesale_price','reorder_level','weight_kg','sort_order','is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2', 'retail_price' => 'decimal:2', 'wholesale_price' => 'decimal:2',
        'weight_kg' => 'decimal:3', 'is_active' => 'boolean',
    ];

    public function product() { return $this->belongsTo(Product::class); }

    // صور خاصة باللون ده
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->acceptsMimeTypes(['image/jpeg','image/png','image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')->nonQueued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')->nonQueued();
    }
}
المهمة 4: الصلاحيات
اعمل seeder اسمه database/seeders/CatalogPermissionSeeder.php، ونادي عليه من DatabaseSeeder:

Copy$permissions = [
    'categories.view','categories.create','categories.update','categories.delete',
    'brands.view','brands.create','brands.update','brands.delete',
    'products.view','products.create','products.update','products.delete',
    'products.view_cost',     // يشوف سعر التكلفة
    'products.publish',       // ينشر المنتج
];
foreach ($permissions as $p) {
    Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
}
Role::where('name', 'super-admin')->first()?->givePermissionTo($permissions);
خلّي guard_name هو نفس الـ guard اللي استخدمته في المرحلة الأولى. لو كان مختلف عن web، استخدم الموجود.

المهمة 5: أدوات مشتركة
app/Exceptions/BusinessException.php: ده الاستثناء اللي بنرميه لما قاعدة من قواعد البيزنس تتكسر، وبيرجّع 422.

Copyclass BusinessException extends \Exception
{
    public function __construct(string $message, public array $errors = [], public int $status = 422)
    { parent::__construct($message); }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => $this->errors], $this->status);
    }
}
app/Services/SlugService.php

Copyclass SlugService
{
    public static function make(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: Str::random(8);
        $slug = $base; $i = 2;
        while ($modelClass::withTrashed()->where('slug', $slug)
              ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}"; $i++;
        }
        return $slug;
    }
}
app/Services/BarcodeGenerator.php: بيولّد باركود EAN-13 داخلي. الباركود ده بيبدأ بـ 2، ودي البادئة المخصصة للاستخدام الداخلي.

Copyclass BarcodeGenerator
{
    public static function fromId(int $id): string
    {
        $base = '2' . str_pad((string) $id, 11, '0', STR_PAD_LEFT); // 12 رقم
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }
        return $base . ((10 - ($sum % 10)) % 10);
    }
}
app/Http/Resources/MediaResource.php

Copyreturn [
    'id' => $this->id,
    'collection' => $this->collection_name,
    'url' => $this->getUrl(),
    'thumb_url' => $this->hasGeneratedConversion('thumb') ? $this->getUrl('thumb') : null,
    'medium_url' => $this->hasGeneratedConversion('medium') ? $this->getUrl('medium') : null,
    'mime_type' => $this->mime_type,
    'size' => $this->size,
    'order' => $this->order_column,
    'variant_id' => $this->model_type === ProductVariant::class ? $this->model_id : null,
];
قاعدة الـ Pagination في كل الـ index: استخدم per_page من الطلب، والافتراضي 15 والحد الأقصى 100: $perPage = min((int) $request->input('per_page', 15), 100);

المهمة 6: الأقسام (Categories)
الملفات المطلوبة
Copyapp/Http/Requests/Admin/Category/StoreCategoryRequest.php
app/Http/Requests/Admin/Category/UpdateCategoryRequest.php
app/Http/Requests/Admin/Category/ReorderCategoriesRequest.php
app/Http/Resources/Admin/CategoryResource.php
app/Services/Catalog/CategoryService.php
app/Http/Controllers/Api/V1/Admin/CategoryController.php
Validation
Store:

Copy'parent_id' => ['nullable','integer', Rule::exists('categories','id')->whereNull('deleted_at')],
'name_ar' => ['required','string','max:150'],
'name_en' => ['required','string','max:150'],
'slug' => ['nullable','string','max:180','alpha_dash', Rule::unique('categories','slug')],
'description_ar' => ['nullable','string'],
'description_en' => ['nullable','string'],
'sort_order' => ['nullable','integer','min:0'],
'is_active' => ['nullable','boolean'],
'image' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
Update: نفس القواعد بس كل حاجة sometimes، والـ slug بيتجاهل السجل الحالي: Rule::unique('categories','slug')->ignore($this->route('category')). وكمان ضيف 'remove_image' => ['nullable','boolean'].

Reorder:

Copy'items' => ['required','array','min:1'],
'items.*.id' => ['required','integer','exists:categories,id'],
'items.*.parent_id' => ['nullable','integer','exists:categories,id'],
'items.*.sort_order' => ['required','integer','min:0'],
CategoryService: القواعد
**create(array data, ?UploadedFile $image)**: لو الـ slug مش مبعوت، اعمله بـ `SlugService::make(Category::class, $data['name_en'])`. اتأكد إن العمق مش أكتر من 3 مستويات، وده معناه إن عمق الأب لازم يكون أقل من 3، ولو أكتر ارمي `BusinessException('أقصى عمق للأقسام 3 مستويات')`. بعد كده احفظ، ولو فيه صورة اعمل `category->addMedia($image)->toMediaCollection('image')`.
**update(Category c, array $data, ?UploadedFile $image)**: لو `parent_id` اتغير، اعمل `assertValidParent(c, newParentId)‘.ولو‘remove 
i
​
 mage=true‘اعمل‘c->clearMediaCollection('image')`.
**assertValidParent(Category c, ?int $parentId)**: ارمي BusinessException في الحالات دي: لو `parentId == c−>id‘،أولو‘parentIdموجود في$c->descendantIds()` (لأن ده يعمل دورة)، أو لو العمق الجديد هيعدّي 3.
delete(Category $c): ارمي BusinessException لو القسم فيه أقسام فرعية ('لا يمكن حذف قسم يحتوي على أقسام فرعية')، أو لو فيه منتجات ('لا يمكن حذف قسم مرتبط بمنتجات'). لو مفيش حاجة من دول، اعمل soft delete.
reorder(array $items): جوه transaction، لكل عنصر اعمل assertValidParent وبعدها update(['parent_id','sort_order']).
tree(): هات كل الأقسام في query واحدة مرتبة بـ sort_order ومعاها withCount('products')، وابني الشجرة في PHP:
Copypublic function tree(bool $onlyActive = false): Collection
{
    $all = Category::query()->with('media')->withCount('products')
        ->when($onlyActive, fn($q) => $q->where('is_active', true))
        ->orderBy('sort_order')->get();
    $grouped = $all->groupBy('parent_id');
    $build = function ($parentId) use (&$build, $grouped) {
        return ($grouped[$parentId] ?? collect())->map(function ($cat) use ($build) {
            $cat->setRelation('children', $build($cat->id));
            return $cat;
        })->values();
    };
    return $build(null);
}
ملاحظة: groupBy('parent_id') بيحط الأقسام الرئيسية تحت مفتاح ""، فاستخدم $grouped[$parentId ?? ''].

CategoryResource
Copyreturn [
    'id' => $this->id,
    'parent_id' => $this->parent_id,
    'name_ar' => $this->name_ar,
    'name_en' => $this->name_en,
    'slug' => $this->slug,
    'description_ar' => $this->description_ar,
    'description_en' => $this->description_en,
    'sort_order' => $this->sort_order,
    'is_active' => $this->is_active,
    'image_url' => $this->getFirstMediaUrl('image') ?: null,
    'products_count' => $this->whenCounted('products'),
    'parent' => new CategoryResource($this->whenLoaded('parent')),
    'children' => CategoryResource::collection($this->whenLoaded('children')),
    'created_at' => $this->created_at,
];
CategoryController
اعمل implement لـ HasMiddleware:

Copypublic static function middleware(): array
{
    return [
        new Middleware('can:categories.view', only: ['index','show']),
        new Middleware('can:categories.create', only: ['store']),
        new Middleware('can:categories.update', only: ['update','reorder']),
        new Middleware('can:categories.delete', only: ['destroy']),
    ];
}
index: لو ?tree=1 رجّع CategoryResource::collection($service->tree()) من غير pagination. غير كده اعمل query بالفلاتر دي: search بيدوّر في name_ar وname_en بـ LIKE. وparent_id لو قيمته root يبقى whereNull، ولو رقم يبقى where عادي. وis_active. ورتّب بـ sort_order، وهات withCount('products') و with('parent','media')، وبعدها paginate.
show: اعمل load('parent','children','media') وloadCount('products').
store: يرجّع 201.
update: يرجّع الـ Resource.
destroy: يرجّع response()->json(['message' => 'تم الحذف']).
reorder: يرجّع ['message' => 'تم الترتيب'].
رفع الملفات في التعديل: الـ PHP مش بيقرا ملفات multipart لما الطلب يكون PUT. عشان كده الفرونت هيبعت POST ومعاه _method=PUT في الـ body، و Laravel هيتعامل معاه تلقائي. اكتب الملاحظة دي في توثيق الـ API.

المهمة 7: البراندات (Brands)
هي نفس فكرة الأقسام بالظبط، بس من غير parent ومن غير tree ومن غير reorder. الفروق:

الصورة اسمها logo، والـ collection اسمها logo، ومسموح فيها svg.
الحذف ممنوع لو البراند عليه منتجات.
الـ index فيه فلتر search و is_active، ومعاه withCount('products').
الصلاحيات من نوع brands.*.
الـ Resource فيه logo_url بدل image_url.
الملفات: StoreBrandRequest وUpdateBrandRequest وBrandResource وBrandService وBrandController.

المهمة 8: المنتجات (Products)
الملفات
Copyapp/Http/Requests/Admin/Product/StoreProductRequest.php
app/Http/Requests/Admin/Product/UpdateProductRequest.php
app/Http/Resources/Admin/ProductResource.php
app/Services/Catalog/ProductService.php
app/Http/Controllers/Api/V1/Admin/ProductController.php
Validation (Store)
Copy'category_id' => ['required','integer', Rule::exists('categories','id')->whereNull('deleted_at')],
'brand_id' => ['nullable','integer', Rule::exists('brands','id')->whereNull('deleted_at')],
'supplier_id' => ['nullable','integer'],
'name_ar' => ['required','string','max:255'],
'name_en' => ['required','string','max:255'],
'slug' => ['nullable','alpha_dash','max:280', Rule::unique('products','slug')],
'description_ar' => ['nullable','string'],
'description_en' => ['nullable','string'],
'material_ar' => ['nullable','string','max:150'],
'material_en' => ['nullable','string','max:150'],
'length_cm' => ['nullable','numeric','min:0'],
'width_cm' => ['nullable','numeric','min:0'],
'height_cm' => ['nullable','numeric','min:0'],
'weight_kg' => ['nullable','numeric','min:0'],
'warranty_months' => ['nullable','integer','min:0','max:120'],
'warranty_details' => ['nullable','string','max:255'],
'country_of_origin' => ['nullable','string','max:100'],
'status' => ['nullable', Rule::enum(ProductStatus::class)],
'sales_channel' => ['required', Rule::enum(SalesChannel::class)],
'is_featured' => ['nullable','boolean'],
// اختياري: متغيرات مع الإنشاء
'variants' => ['nullable','array'],
'variants.*' => ['array'],   // القواعد التفصيلية من VariantRules (المهمة 9)
ودمج قواعد المتغيرات بالشكل ده:

Copyforeach (VariantRules::base() as $field => $rule) {
    $rules["variants.*.$field"] = $rule;
}
Update: نفس القواعد، وكل حاجة فيها sometimes، والـ slug بيتجاهل المنتج الحالي، ومن غير variants.

ProductService
Copypublic function create(array $data, User $user): Product
{
    return DB::transaction(function () use ($data, $user) {
        $variants = $data['variants'] ?? [];
        unset($data['variants']);

        $data['slug'] = $data['slug'] ?? SlugService::make(Product::class, $data['name_en']);
        $data['created_by'] = $user->id;
        $wantsPublish = ($data['status'] ?? 'draft') === ProductStatus::Published->value;
        $data['status'] = ProductStatus::Draft->value; // دايمًا يتعمل draft الأول

        $product = Product::create($data);
        $product->update(['code' => 'P' . str_pad($product->id, 5, '0', STR_PAD_LEFT)]);

        foreach ($variants as $v) {
            app(VariantService::class)->create($product, $v);
        }

        if ($wantsPublish) {
            $this->changeStatus($product, ProductStatus::Published, $user);
        }
        return $product->fresh(['category','brand','variants','media']);
    });
}

public function update(Product $product, array $data, User $user): Product
{
    return DB::transaction(function () use ($product, $data, $user) {
        $newStatus = isset($data['status']) ? ProductStatus::from($data['status']) : null;
        unset($data['status']);
        $product->update($data);
        if ($newStatus && $newStatus !== $product->status) {
            $this->changeStatus($product, $newStatus, $user);
        }
        return $product->fresh(['category','brand','variants','media']);
    });
}

public function changeStatus(Product $product, ProductStatus $status, User $user): void
{
    if ($status === ProductStatus::Published) {
        if (! $user->can('products.publish')) {
            throw new BusinessException('ليس لديك صلاحية نشر المنتجات', [], 403);
        }
        $this->assertPublishable($product);
        $product->published_at ??= now();
    }
    $product->status = $status;
    $product->save();
}

public function assertPublishable(Product $product): void
{
    $errors = [];
    $active = $product->variants()->where('is_active', true)->get();

    if ($active->isEmpty()) $errors[] = 'يجب وجود متغير واحد نشط على الأقل';
    if ($product->sales_channel->allowsRetail() && $active->contains(fn($v) => ! $v->retail_price || $v->retail_price <= 0))
        $errors[] = 'كل المتغيرات النشطة لازم يكون لها سعر قطاعي';
    if ($product->sales_channel->allowsWholesale() && $active->contains(fn($v) => ! $v->wholesale_price || $v->wholesale_price <= 0))
        $errors[] = 'كل المتغيرات النشطة لازم يكون لها سعر جملة';
    if ($product->getMedia('images')->isEmpty()) $errors[] = 'يجب رفع صورة واحدة على الأقل';
    if (! $product->category?->is_active) $errors[] = 'القسم غير نشط';

    if ($errors) throw new BusinessException('لا يمكن نشر المنتج', ['status' => $errors]);
}

public function delete(Product $product): void
{
    DB::transaction(function () use ($product) {
        $product->variants()->delete();  // soft
        $product->delete();              // soft
    });
    // TODO مرحلة الطلبات: منع الحذف لو المنتج عليه طلبات، وبدل كده يتعمل archived
}
Copy
ProductController
الصلاحيات: products.view لـ index و show، وproducts.create لـ store، وproducts.update لـ update، وproducts.delete لـ destroy.

index: الفلاتر المطلوبة:

Copy$q = Product::query()
    ->with(['category:id,name_ar,name_en', 'brand:id,name_ar,name_en', 'media'])
    ->withCount('variants')
    ->withMin('variants as min_retail_price', 'retail_price')
    ->withMax('variants as max_retail_price', 'retail_price');

if ($s = $request->search) {
    $q->where(fn($w) => $w->where('name_ar','like',"%$s%")->orWhere('name_en','like',"%$s%")
        ->orWhere('code', $s)
        ->orWhereHas('variants', fn($v) => $v->where('sku', $s)->orWhere('barcode', $s)));
}
if ($request->filled('category_id')) {
    $cat = Category::findOrFail($request->category_id);
    $ids = $request->boolean('include_children') ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
    $q->whereIn('category_id', $ids);
}
$q->when($request->brand_id, fn($x, $v) => $x->where('brand_id', $v))
  ->when($request->status, fn($x, $v) => $x->where('status', $v))
  ->when($request->sales_channel, fn($x, $v) => $x->where('sales_channel', $v))
  ->when($request->has('is_featured'), fn($x) => $x->where('is_featured', $request->boolean('is_featured')));

match ($request->input('sort', 'latest')) {
    'oldest' => $q->oldest(),
    'name'   => $q->orderBy('name_en'),
    default  => $q->latest(),
};
return ProductResource::collection($q->paginate($perPage));
show: اعمل load(['category','brand','variants.media','media']).

ProductResource
Copyreturn [
    'id' => $this->id,
    'code' => $this->code,
    'name_ar' => $this->name_ar, 'name_en' => $this->name_en, 'slug' => $this->slug,
    'description_ar' => $this->description_ar, 'description_en' => $this->description_en,
    'material_ar' => $this->material_ar, 'material_en' => $this->material_en,
    'dimensions' => ['length_cm' => $this->length_cm, 'width_cm' => $this->width_cm, 'height_cm' => $this->height_cm],
    'weight_kg' => $this->weight_kg,
    'warranty_months' => $this->warranty_months, 'warranty_details' => $this->warranty_details,
    'country_of_origin' => $this->country_of_origin,
    'status' => $this->status->value,
    'sales_channel' => $this->sales_channel->value,
    'is_featured' => $this->is_featured,
    'published_at' => $this->published_at,
    'category' => new CategoryResource($this->whenLoaded('category')),
    'brand' => new BrandResource($this->whenLoaded('brand')),
    'supplier_id' => $this->supplier_id,
    'main_image' => $this->getFirstMediaUrl('images', 'thumb') ?: null,
    'images' => MediaResource::collection($this->whenLoaded('media', fn() => $this->getMedia('images'))),
    'video' => $this->whenLoaded('media', fn() => ($v = $this->getFirstMedia('video')) ? new MediaResource($v) : null),
    'variants_count' => $this->whenCounted('variants'),
    'min_retail_price' => $this->when(isset($this->min_retail_price), $this->min_retail_price),
    'max_retail_price' => $this->when(isset($this->max_retail_price), $this->max_retail_price),
    'variants' => VariantResource::collection($this->whenLoaded('variants')),
    'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
];
المهمة 9: المتغيرات (Variants)
الملفات
Copyapp/Http/Requests/Admin/Variant/VariantRules.php     (class فيه static method)
app/Http/Requests/Admin/Variant/StoreVariantRequest.php
app/Http/Requests/Admin/Variant/UpdateVariantRequest.php
app/Http/Resources/Admin/VariantResource.php
app/Services/Catalog/VariantService.php
app/Http/Controllers/Api/V1/Admin/ProductVariantController.php
VariantRules
Copyclass VariantRules
{
    public static function base(?int $ignoreId = null): array
    {
        return [
            'sku' => ['nullable','string','max:64','alpha_dash', Rule::unique('product_variants','sku')->ignore($ignoreId)],
            'barcode' => ['nullable','string','max:32','regex:/^[0-9A-Za-z\-]+$/', Rule::unique('product_variants','barcode')->ignore($ignoreId)],
            'color_name_ar' => ['nullable','string','max:50'],
            'color_name_en' => ['nullable','string','max:50'],
            'color_hex' => ['nullable','regex:/^#[0-9A-Fa-f]{6}$/'],
            'size' => ['nullable','string','max:50'],
            'capacity' => ['nullable','string','max:50'],
            'cost_price' => ['nullable','numeric','min:0','max:9999999'],
            'retail_price' => ['nullable','numeric','min:0','max:9999999'],
            'wholesale_price' => ['nullable','numeric','min:0','max:9999999'],
            'reorder_level' => ['nullable','integer','min:0'],
            'weight_kg' => ['nullable','numeric','min:0'],
            'sort_order' => ['nullable','integer','min:0'],
            'is_active' => ['nullable','boolean'],
        ];
    }
}
الـ StoreVariantRequest بيرجّع VariantRules::base(). والـ UpdateVariantRequest بيرجّع نفس القواعد بعد ما يتحط قبلها sometimes، وبيستخدم ignore($this->route('variant')->id).

VariantService
Copypublic function create(Product $product, array $data): ProductVariant
{
    return DB::transaction(function () use ($product, $data) {
        Product::whereKey($product->id)->lockForUpdate()->first(); // منع تكرار الترقيم
        $this->assertUniqueCombination($product, $data);

        if (empty($data['sku'])) {
            $seq = $product->variants()->withTrashed()->count() + 1;
            do {
                $sku = $product->code . '-' . str_pad($seq, 2, '0', STR_PAD_LEFT);
                $seq++;
            } while (ProductVariant::withTrashed()->where('sku', $sku)->exists());
            $data['sku'] = $sku;
        }

        $variant = $product->variants()->create($data);

        if (empty($variant->barcode)) {
            $variant->update(['barcode' => BarcodeGenerator::fromId($variant->id)]);
        }
        return $variant;
    });
}

public function update(ProductVariant $variant, array $data): ProductVariant
{
    return DB::transaction(function () use ($variant, $data) {
        $this->assertUniqueCombination($variant->product, array_merge($variant->only(['color_name_en','size','capacity']), $data), $variant->id);

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->assertNotLastActiveOfPublished($variant);
        }
        $variant->update($data);
        return $variant->fresh('media');
    });
}

public function delete(ProductVariant $variant): void
{
    $this->assertNotLastActiveOfPublished($variant);
    $variant->delete();
    // TODO مرحلة المخزون: منع الحذف لو عليه رصيد
}

private function assertUniqueCombination(Product $product, array $d, ?int $ignoreId = null): void
{
    $exists = $product->variants()
        ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
        ->where(fn($q) => isset($d['color_name_en']) ? $q->where('color_name_en', $d['color_name_en']) : $q->whereNull('color_name_en'))
        ->where(fn($q) => isset($d['size']) ? $q->where('size', $d['size']) : $q->whereNull('size'))
        ->where(fn($q) => isset($d['capacity']) ? $q->where('capacity', $d['capacity']) : $q->whereNull('capacity'))
        ->exists();
    if ($exists) throw new BusinessException('يوجد متغير بنفس اللون والمقاس والسعة لهذا المنتج');
}

private function assertNotLastActiveOfPublished(ProductVariant $variant): void
{
    $product = $variant->product;
    if ($product->status === ProductStatus::Published
        && $product->variants()->where('is_active', true)->where('id', '!=', $variant->id)->doesntExist()) {
        throw new BusinessException('لا يمكن حذف/تعطيل آخر متغير نشط في منتج منشور');
    }
}
Copy
VariantResource
Copyreturn [
    'id' => $this->id,
    'product_id' => $this->product_id,
    'sku' => $this->sku,
    'barcode' => $this->barcode,
    'color' => ['name_ar' => $this->color_name_ar, 'name_en' => $this->color_name_en, 'hex' => $this->color_hex],
    'size' => $this->size,
    'capacity' => $this->capacity,
    'cost_price' => $this->when($request->user()?->can('products.view_cost'), $this->cost_price),
    'retail_price' => $this->retail_price,
    'wholesale_price' => $this->wholesale_price,
    'reorder_level' => $this->reorder_level,
    'weight_kg' => $this->weight_kg ?? $this->product?->weight_kg,
    'sort_order' => $this->sort_order,
    'is_active' => $this->is_active,
    'images' => MediaResource::collection($this->whenLoaded('media', fn() => $this->getMedia('images'))),
];
الموظف اللي معندوش صلاحية products.view_cost مش هيشوف حقل cost_price خالص. وكمان لو بعته في طلب التعديل، الـ Controller لازم يشيله من الداتا: if (! $user->can('products.view_cost')) unset($data['cost_price']);

ProductVariantController
الصلاحيات: products.view لـ index و show، وproducts.update لـ store و update و destroy. السبب إن تعديل المتغيرات معناه تعديل المنتج نفسه.

**index(Product product)∗∗:يرجّع‘VariantResource::collection(product->variants()->with('media')->get())` من غير pagination.
store: يرجّع 201.
باقي الدوال: show و update و destroy.
المهمة 10: الميديا (Media)
الملفات
Copyapp/Http/Requests/Admin/Product/UploadProductMediaRequest.php
app/Http/Requests/Admin/Product/ReorderProductMediaRequest.php
app/Http/Controllers/Api/V1/Admin/ProductMediaController.php
Validation للرفع
Copy'collection' => ['required', Rule::in(['images','video'])],
'variant_id' => ['nullable','integer', Rule::exists('product_variants','id')->where('product_id', $this->route('product')->id)],
'files' => ['required_if:collection,images','array','max:10'],
'files.*' => ['image','mimes:jpg,jpeg,png,webp','max:5120'],
'file' => ['required_if:collection,video','file','mimetypes:video/mp4,video/webm,video/quicktime','max:51200'],
Validation الترتيب: 'ids' => ['required','array','min:1'], 'ids.*' => ['integer'].

Controller
Copy// GET /products/{product}/media
public function index(Product $product)
{
    $variantIds = $product->variants()->pluck('id');
    $media = Media::query()
        ->where(fn($q) => $q->where(['model_type' => Product::class, 'model_id' => $product->id])
            ->orWhere(fn($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
        ->orderBy('order_column')->get();
    return MediaResource::collection($media);
}

// POST /products/{product}/media
public function store(UploadProductMediaRequest $request, Product $product)
{
    $owner = $request->variant_id ? $product->variants()->findOrFail($request->variant_id) : $product;

    if ($request->collection === 'video') {
        if ($owner instanceof ProductVariant) throw new BusinessException('الفيديو للمنتج فقط');
        $media = collect([$product->addMediaFromRequest('file')->toMediaCollection('video')]);
    } else {
        $media = collect($request->file('files'))
            ->map(fn($f) => $owner->addMedia($f)->toMediaCollection('images'));
    }
    return MediaResource::collection($media)->response()->setStatusCode(201);
}

// PUT /products/{product}/media/reorder
public function reorder(ReorderProductMediaRequest $request, Product $product)
{
    $this->assertMediaBelongs($product, $request->ids);
    Media::setNewOrder($request->ids);
    return response()->json(['message' => 'تم الترتيب']);
}

// DELETE /products/{product}/media/{media}
public function destroy(Product $product, Media $media)
{
    $this->assertMediaBelongs($product, [$media->id]);
    if ($product->status === ProductStatus::Published && $media->collection_name === 'images'
        && $media->model_type === Product::class && $product->getMedia('images')->count() <= 1) {
        throw new BusinessException('لا يمكن حذف آخر صورة لمنتج منشور');
    }
    $media->delete();
    return response()->json(['message' => 'تم الحذف']);
}

private function assertMediaBelongs(Product $product, array $ids): void
{
    $variantIds = $product->variants()->withTrashed()->pluck('id')->all();
    $count = Media::whereIn('id', $ids)->where(fn($q) => $q
        ->where(fn($w) => $w->where('model_type', Product::class)->where('model_id', $product->id))
        ->orWhere(fn($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds))
    )->count();
    if ($count !== count(array_unique($ids))) abort(404, 'ملف غير تابع لهذا المنتج');
}
Copy
الصلاحيات: products.view لـ index، وproducts.update لكل الباقي.

المهمة 11: الـ Routes
جوه routes/api/admin.php، وجوه الـ group اللي فيه auth:sanctum وauth.staff من المرحلة الأولى:

Copyuse App\Http\Controllers\Api\V1\Admin\{CategoryController, BrandController, ProductController, ProductVariantController, ProductMediaController};

// مهم: reorder قبل apiResource عشان ميتقريش كأنه {category}
Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
Route::apiResource('categories', CategoryController::class);

Route::apiResource('brands', BrandController::class);

Route::apiResource('products', ProductController::class);
Route::apiResource('products.variants', ProductVariantController::class)->scoped();

Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->name('products.media.store');
Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');
الـ scoped() بيضمن إن /products/1/variants/99 يرجّع 404 لو المتغير 99 مش تابع للمنتج 1.

بعد ما تخلص، شغّل php artisan route:list --path=api/v1/admin واتأكد إن الـ routes دي كلها ظاهرة:

Method	URI
GET/POST	/api/v1/admin/categories
GET/PUT/DELETE	/api/v1/admin/categories/{category}
PUT	/api/v1/admin/categories/reorder
GET/POST	/api/v1/admin/brands
GET/PUT/DELETE	/api/v1/admin/brands/{brand}
GET/POST	/api/v1/admin/products
GET/PUT/DELETE	/api/v1/admin/products/{product}
GET/POST	/api/v1/admin/products/{product}/variants
GET/PUT/DELETE	/api/v1/admin/products/{product}/variants/{variant}
GET/POST	/api/v1/admin/products/{product}/media
PUT	/api/v1/admin/products/{product}/media/reorder
DELETE	/api/v1/admin/products/{product}/media/{media}
المهمة 12: Factories وبيانات تجريبية
اعمل Factory لكل من Brand وCategory وProduct وProductVariant.

وبعدها اعمل CatalogSeeder يضيف الآتي:

الأقسام: شنط يد (Handbags)، محافظ (Wallets)، شنط سفر (Luggage)، شنط ظهر (Backpacks). وتحت شنط سفر اعمل 3 أقسام فرعية: كابينة (Cabin)، متوسطة (Medium)، كبيرة (Large).
البراندات: 4 براندات.
المنتجات: 10 منتجات، كل واحد ليه من 2 لـ 3 متغيرات بألوان مختلفة، ومعمولة عن طريق ProductService عشان الـ code والـ SKU والباركود يتولدوا. خلّيهم كلهم draft، لأن مفيش صور.
المهمة 13: الاختبارات (Feature Tests)
اعمل tests/Feature/Admin/Catalog/ واكتب فيه الاختبارات دي، واستخدم Storage::fake('public'):

موظف من غير صلاحية categories.create يرجعله 403.
إنشاء قسم بصورة يرجّع 201، والـ image_url يكون موجود.
إن القسم يبقى أب لنفسه أو لحد من أحفاده يرجّع 422.
إنشاء قسم في مستوى رابع يرجّع 422.
حذف قسم فيه منتجات يرجّع 422.
?tree=1 يرجّع شجرة متداخلة صح.
إنشاء منتج يولّد code بالشكل P00001، وslug تلقائي.
إنشاء متغير من غير SKU يولّد P00001-01، وباركود 13 رقم الـ check digit بتاعه صح.
متغيرين بنفس اللون والمقاس على نفس المنتج يرجّع 422.
نشر منتج من غير صورة أو من غير سعر يرجّع 422، ومعاه رسائل الأخطاء.
نشر منتج كامل يغيّر الـ status لـ published، ويحط قيمة لـ published_at.
موظف من غير products.view_cost ميشوفش cost_price في الـ response.
/products/1/variants/{id لمنتج تاني} يرجّع 404.
حذف media تابعة لمنتج تاني يرجّع 404.
فلتر search بالباركود يرجّع المنتج الصح.
