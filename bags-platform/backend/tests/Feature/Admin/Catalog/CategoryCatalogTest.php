<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Http\Middleware\ManufacturingBusiness;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CategoryCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'categories.view', 'categories.create', 'categories.update', 'categories.delete',
    ];

    public function test_staff_without_create_permission_gets_403(): void
    {
        $viewer = $this->staffWithPermissions(['categories.view']);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'POST', '/api/v1/admin/categories', [
            'name_ar' => 'قسم', 'name_en' => 'Category',
        ])->assertForbidden();
    }

    public function test_create_category_with_image_returns_201_and_image_url(): void
    {
        $this->fakeUploads();
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->uploadWithToken($token, '/api/v1/admin/categories', [
            'name_ar' => 'شنط يد',
            'name_en' => 'Handbags',
            'image' => UploadedFile::fake()->image('handbags.jpg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name_en', 'Handbags')
            ->assertJsonPath('data.slug', 'handbags');
        $this->assertNotEmpty($response->json('data.image_url'));
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $category = Category::factory()->create();

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$category->id}", [
            'parent_id' => $category->id,
        ])->assertUnprocessable();
    }

    public function test_category_cannot_move_under_its_descendant(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$root->id}", [
            'parent_id' => $grandchild->id,
        ])->assertUnprocessable();
    }

    public function test_creating_a_fourth_level_category_is_rejected(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $level1 = Category::factory()->create();
        $level2 = Category::factory()->create(['parent_id' => $level1->id]);
        $level3 = Category::factory()->create(['parent_id' => $level2->id]);

        $this->requestWithToken($token, 'POST', '/api/v1/admin/categories', [
            'name_ar' => 'مستوى رابع', 'name_en' => 'Level Four', 'parent_id' => $level3->id,
        ])->assertUnprocessable();
    }

    public function test_deleting_a_category_with_products_is_rejected(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/categories/{$category->id}")
            ->assertUnprocessable();
    }

    public function test_reorder_moves_category_to_another_parent_and_updates_sort_order(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $from = Category::factory()->create();
        $to = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $from->id, 'sort_order' => 0]);

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/categories/reorder', [
            'items' => [
                ['id' => $child->id, 'parent_id' => $to->id, 'sort_order' => 3],
            ],
        ])->assertOk();

        $child->refresh();
        $this->assertSame($to->id, $child->parent_id);
        $this->assertSame(3, $child->sort_order);
    }

    public function test_reorder_returns_422_when_depth_exceeds_three_levels(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $level1 = Category::factory()->create();
        $level2 = Category::factory()->create(['parent_id' => $level1->id]);
        $level3 = Category::factory()->create(['parent_id' => $level2->id]);
        $other = Category::factory()->create();

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/categories/reorder', [
            'items' => [
                ['id' => $other->id, 'parent_id' => $level3->id, 'sort_order' => 0],
            ],
        ])->assertUnprocessable();
    }

    public function test_update_with_post_method_spoof_and_image_returns_200_and_image_url(): void
    {
        $this->fakeUploads();
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $category = Category::factory()->create();

        $response = $this->uploadWithToken($token, "/api/v1/admin/categories/{$category->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('cat.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.image_url'));
    }

    public function test_update_remove_image_clears_image_url(): void
    {
        $this->fakeUploads();
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $category = Category::factory()->create();
        $category->addMedia(UploadedFile::fake()->image('keep.jpg'))->toMediaCollection('image');

        $response = $this->uploadWithToken($token, "/api/v1/admin/categories/{$category->id}", [
            '_method' => 'PUT',
            'remove_image' => '1',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.image_url'));
    }

    public function test_deactivating_category_with_published_product_returns_422(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $category = Category::factory()->create(['is_active' => true]);
        Product::factory()->published()->create(['category_id' => $category->id]);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$category->id}", [
            'is_active' => false,
        ])->assertUnprocessable();
    }

    public function test_viewer_cannot_reorder_categories(): void
    {
        $viewer = $this->staffWithPermissions(['categories.view']);
        $token = $viewer->createToken('test')->plainTextToken;
        $category = Category::factory()->create();

        $this->requestWithToken($token, 'PUT', '/api/v1/admin/categories/reorder', [
            'items' => [
                ['id' => $category->id, 'parent_id' => null, 'sort_order' => 1],
            ],
        ])->assertForbidden();
    }

    public function test_tree_returns_correctly_nested_categories(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $root = Category::factory()->create(['name_en' => 'Luggage']);
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $grandchild = Category::factory()->create(['parent_id' => $child->id]);
        Category::factory()->create(['name_en' => 'Other Root']);

        $response = $this->requestWithToken($token, 'GET', '/api/v1/admin/categories?tree=1');
        $response->assertOk();

        $treeRoot = collect($response->json('data'))->firstWhere('id', $root->id);
        $this->assertNotNull($treeRoot);
        $this->assertSame($child->id, $treeRoot['children'][0]['id']);
        $this->assertSame($grandchild->id, $treeRoot['children'][0]['children'][0]['id']);
    }

    public function test_optimistic_concurrency_version_and_order_checks_across_brands_categories_variants_and_media(): void
    {
        $this->fakeUploads();
        $perms = [
            ...self::ALL_PERMISSIONS,
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',
            'products.view', 'products.create', 'products.update', 'products.delete',
        ];
        $admin = $this->staffWithPermissions($perms);
        $token = $admin->createToken('test')->plainTextToken;

        // 1. Category DELETE endpoint respects expected_version
        $createdCat = $this->requestWithToken($token, 'POST', '/api/v1/admin/categories', [
            'name_ar' => 'قسم قفل', 'name_en' => 'LockCat',
        ])->assertCreated()->assertJsonPath('data.lock_version', 0);
        $catId = $createdCat->json('data.id');
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$catId}", [
            'name_en' => 'LockCatV1', 'expected_version' => 0,
        ])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/categories/{$catId}?expected_version=0")
            ->assertStatus(409);
        $this->assertNotSoftDeleted(Category::findOrFail($catId));

        // 2. Variant DELETE endpoint respects expected_version
        $product = Product::factory()->create(['category_id' => $catId]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100]);
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/variants/{$variant->id}", [
            'retail_price' => 120, 'expected_version' => 0,
        ])->assertOk();
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/products/{$product->id}/variants/{$variant->id}?expected_version=0")
            ->assertStatus(409);
        $this->assertNotSoftDeleted(ProductVariant::findOrFail($variant->id));

        // 3. Media reorder endpoint enforces expected_ids
        Auth::forgetGuards();
        $m1 = $this->withToken($token)->post("/api/v1/admin/products/{$product->id}/media", [
            'collection' => 'images', 'files' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertCreated()->json('data.0.id');
        Auth::forgetGuards();
        $m2 = $this->withToken($token)->post("/api/v1/admin/products/{$product->id}/media", [
            'collection' => 'images', 'files' => [UploadedFile::fake()->image('b.jpg')],
        ])->assertCreated()->json('data.0.id');

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/media/reorder", [
            'ids' => [$m2, $m1],
            'expected_ids' => [$m2, $m1],
        ])->assertStatus(409);

        // 4. Brand exposes lock_version and rejects stale PUT and DELETE with 409 (exercising BrandController/Service directly)
        $this->withoutMiddleware(ManufacturingBusiness::class);
        $createdBrand = $this->requestWithToken($token, 'POST', '/api/v1/admin/brands', [
            'name_ar' => 'براند', 'name_en' => 'BrandOne',
        ])->assertCreated()->assertJsonPath('data.lock_version', 0);
        $brandId = $createdBrand->json('data.id');

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/brands/{$brandId}", [
            'name_en' => 'BrandTwo', 'expected_version' => 0,
        ])->assertOk()->assertJsonPath('data.lock_version', 1);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/brands/{$brandId}", [
            'name_en' => 'StaleBrand', 'expected_version' => 0,
        ])->assertStatus(409);

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/brands/{$brandId}?expected_version=0")
            ->assertStatus(409);
        $this->assertNotSoftDeleted(Brand::findOrFail($brandId));
    }

    /** @param list<string> $permissions */
    private function staffWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }

    private function uploadWithToken(string $token, string $uri, array $payload): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->post($uri, $payload);
    }
}
