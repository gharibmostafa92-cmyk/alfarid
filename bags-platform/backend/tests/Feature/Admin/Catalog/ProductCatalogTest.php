<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'products.view', 'products.create', 'products.update', 'products.delete',
        'products.publish', 'products.view_cost',
    ];

    public function test_creating_a_product_generates_code_and_slug(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();

        $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'حقيبة جلد',
            'name_en' => 'Leather Handbag',
            'sales_channel' => 'retail',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'leather-handbag')
            ->assertJsonPath('data.status', 'draft');

        // الكود = P + رقم المنتج مبطّن لخمس خانات (أول منتج في قاعدة فاضية بيبقى P00001)
        $this->assertMatchesRegularExpression('/^P\d{5}$/', (string) $response->json('data.code'));
        $this->assertSame(
            'P'.str_pad((string) $response->json('data.id'), 5, '0', STR_PAD_LEFT),
            $response->json('data.code'),
        );
    }

    public function test_publishing_without_prices_fails_with_error_messages(): void
    {
        $token = $this->adminToken();
        $productId = $this->createProduct($token, [
            'variants' => [['color_name_en' => 'Red', 'size' => 'M']],
        ]);

        $response = $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
            'status' => 'published',
        ]);

        $response->assertUnprocessable();
        $errors = $response->json('errors.status') ?? [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('سعر قطاعي', implode(' ', $errors));
    }

    public function test_publishing_without_an_image_fails_with_error_messages(): void
    {
        $token = $this->adminToken();
        $productId = $this->createProduct($token, [
            'variants' => [['color_name_en' => 'Red', 'retail_price' => 100]],
        ]);

        $response = $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
            'status' => 'published',
        ]);

        $response->assertUnprocessable();
        $errors = $response->json('errors.status') ?? [];
        $this->assertStringContainsString('صورة واحدة', implode(' ', $errors));
    }

    public function test_publishing_a_complete_product_sets_status_and_published_at(): void
    {
        $this->fakeUploads();
        $token = $this->adminToken();
        $productId = $this->createProduct($token, [
            'variants' => [['color_name_en' => 'Red', 'retail_price' => 100]],
        ]);

        Auth::forgetGuards();
        $this->withToken($token)->post("/api/v1/admin/products/{$productId}/media", [
            'collection' => 'images',
            'files' => [UploadedFile::fake()->image('product.jpg')],
        ])->assertCreated();

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
            'status' => 'published',
        ])->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', fn ($value) => ! empty($value));

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'published']);
    }

    public function test_staff_without_view_cost_cannot_see_cost_price(): void
    {
        $token = $this->adminToken();
        $productId = $this->createProduct($token, [
            'variants' => [['color_name_en' => 'Red', 'cost_price' => 500, 'retail_price' => 900]],
        ]);

        $viewer = $this->staffWithPermissions(['products.view']);
        $viewerToken = $viewer->createToken('test')->plainTextToken;

        $response = $this->requestWithToken($viewerToken, 'GET', "/api/v1/admin/products/{$productId}");
        $response->assertOk();
        $this->assertArrayNotHasKey('cost_price', $response->json('data.variants.0'));

        $adminResponse = $this->requestWithToken($token, 'GET', "/api/v1/admin/products/{$productId}");
        $this->assertSame('500.0000', (string) $adminResponse->json('data.variants.0.cost_price'));
    }

    public function test_search_by_barcode_finds_the_right_product(): void
    {
        $token = $this->adminToken();
        $targetId = $this->createProduct($token, [
            'name_en' => 'Target Bag',
            'variants' => [['color_name_en' => 'Red', 'barcode' => '6220001112223']],
        ]);
        $this->createProduct($token, ['name_en' => 'Other Bag']);

        $this->requestWithToken($token, 'GET', '/api/v1/admin/products?search=6220001112223')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetId);
    }

    private function adminToken(): string
    {
        return $this->staffWithPermissions(self::ALL_PERMISSIONS)->createToken('test')->plainTextToken;
    }

    private function createProduct(string $token, array $overrides = []): int
    {
        $category = Category::factory()->create();
        $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', array_merge([
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Some Product',
            'sales_channel' => 'retail',
        ], $overrides));
        $response->assertCreated();

        return $response->json('data.id');
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
}
