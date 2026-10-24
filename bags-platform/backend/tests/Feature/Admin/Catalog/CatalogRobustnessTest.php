<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogRobustnessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        'brands.view', 'brands.create', 'brands.update', 'brands.delete',
        'products.view', 'products.create', 'products.update', 'products.delete',
        'products.publish', 'products.view_cost',
    ];

    // ---------- M4: مدخلات غلط بترجع 404/422 مش 500 ----------

    public function test_non_numeric_route_id_returns_404(): void
    {
        $token = $this->adminToken();

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products/abc')->assertNotFound();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/categories/abc')->assertNotFound();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/products/1/media/xyz')->assertNotFound();
    }

    public function test_malformed_index_filters_return_422(): void
    {
        $token = $this->adminToken();

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?category_id=abc')->assertUnprocessable();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?brand_id=abc')->assertUnprocessable();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?status=banana')->assertUnprocessable();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/categories?parent_id=abc')->assertUnprocessable();
    }

    public function test_array_query_params_return_422_not_500(): void
    {
        $token = $this->adminToken();

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?search[]=x')->assertUnprocessable();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?category_id[]=1&category_id[]=2')->assertUnprocessable();
        $this->requestWithToken($token, 'GET', '/api/v1/admin/brands?search[]=x')->assertStatus(410);
        $this->requestWithToken($token, 'GET', '/api/v1/admin/categories?search[]=x')->assertUnprocessable();
    }

    public function test_unknown_category_filter_returns_422(): void
    {
        $token = $this->adminToken();

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?category_id=999999')->assertUnprocessable();
    }

    // ---------- M5: بحث مش حساس لحالة الحروف والـ wildcards حرفية ----------

    public function test_search_is_case_insensitive(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();
        $this->createProduct($token, $category->id, 'Classic Leather Handbag');

        foreach (['handbag', 'HANDBAG', 'LeAtHeR'] as $term) {
            $this->requestWithToken($token, 'GET', '/api/v1/admin/products?search='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_search_wildcards_are_escaped(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();
        $this->createProduct($token, $category->id, 'Cotton Bag');
        $this->createProduct($token, $category->id, '100% Cotton Bag');

        // ‏% لو اتعامل كـ wildcard هيرجع المنتجين؛ متعشّق بيرجع اللي فيه % حرفيًا بس
        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?search='.urlencode('100%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name_en', '100% Cotton Bag');

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?search='.urlencode('%'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ---------- L6: أرخص/أغلى سعر من النشطين بس ----------

    public function test_min_max_retail_prices_ignore_inactive_variants(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();
        $productId = $this->createProduct($token, $category->id, 'Priced Bag', [
            'variants' => [
                ['color_name_en' => 'Red', 'retail_price' => 100],
                ['color_name_en' => 'Blue', 'retail_price' => 40, 'is_active' => false],
            ],
        ]);

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $productId)
            ->assertJsonPath('data.0.min_retail_price', '100.00')
            ->assertJsonPath('data.0.max_retail_price', '100.00');
    }

    // ---------- M11: تكرار التركيبة وحالة الحروف ----------

    public function test_combination_check_is_case_insensitive(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Black', 'size' => 'M',
        ])->assertCreated();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'black', 'size' => 'm',
        ])->assertUnprocessable();
    }

    public function test_color_details_without_english_name_are_rejected(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_hex' => '#112233',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['color_name_en']);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_name_ar' => 'أسود',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['color_name_en']);
    }

    // ---------- L1: archived وقت الإنشاء مرفوض مش بيتحول draft بصمت ----------

    public function test_creating_a_product_directly_as_archived_is_rejected(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Archived At Birth',
            'sales_channel' => 'retail',
            'status' => 'archived',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    // ---------- M7/M8: حدود الأبعاد والـ arrays ----------

    public function test_oversized_image_dimensions_are_rejected(): void
    {
        $this->fakeUploads();
        $token = $this->adminToken();

        Auth::forgetGuards();
        // F7: the guard is a total pixel budget (25M), not an edge cap — a
        // 6001x40 strip is harmless and now passes, while a 6000x5000
        // (30M pixels) decompression bomb is still rejected.
        $this->withToken($token)->post('/api/v1/admin/categories', [
            'name_ar' => 'قسم',
            'name_en' => 'Huge Image Category',
            'image' => UploadedFile::fake()->image('huge.jpg', 6000, 5000),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_too_many_variants_on_create_are_rejected(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();
        $variants = array_map(fn ($i) => ['color_name_en' => 'Color '.$i], range(1, 101));

        $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Many Variants',
            'sales_channel' => 'retail',
            'variants' => $variants,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['variants']);
    }

    public function test_media_reorder_rejects_duplicate_and_too_many_ids(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/media/reorder", [
            'ids' => [1, 1],
        ])->assertUnprocessable();

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/media/reorder", [
            'ids' => range(1, 201),
        ])->assertUnprocessable();
    }

    // ---------- L7: روابط الميديا بالـ uuid ----------

    public function test_media_urls_use_uuid_paths(): void
    {
        $this->fakeUploads();
        $token = $this->adminToken();
        $product = Product::factory()->create();

        Auth::forgetGuards();
        $uploaded = $this->withToken($token)->post("/api/v1/admin/products/{$product->id}/media", [
            'collection' => 'images',
            'files' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertCreated();

        $url = (string) $uploaded->json('data.0.url');
        $uuid = (string) $product->getFirstMedia('images')->uuid;
        $this->assertNotEmpty($uuid);
        $this->assertStringContainsString($uuid, $url);
    }

    // ---------- helpers ----------

    private function createProduct(string $token, int $categoryId, string $nameEn, array $extra = []): int
    {
        $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', array_merge([
            'category_id' => $categoryId,
            'name_ar' => 'منتج',
            'name_en' => $nameEn,
            'sales_channel' => 'retail',
        ], $extra));
        $response->assertCreated();

        return $response->json('data.id');
    }

    private function adminToken(): string
    {
        foreach (self::ALL_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo(self::ALL_PERMISSIONS);

        return $staff->createToken('test')->plainTextToken;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
