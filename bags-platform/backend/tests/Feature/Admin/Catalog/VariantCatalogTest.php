<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VariantCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'products.view', 'products.create', 'products.update', 'products.delete',
        'products.publish', 'products.view_cost',
    ];

    public function test_variant_without_sku_gets_sequence_sku_and_valid_ean13_barcode(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();

        $product = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Barcode Product',
            'sales_channel' => 'retail',
        ])->assertCreated();
        $productId = $product->json('data.id');
        $code = (string) $product->json('data.code');
        $this->assertMatchesRegularExpression('/^P\d{5}$/', $code);

        // أول متغير لأي منتج بياخد لاحقة ‎-01‎ (في قاعدة فاضية بيبقى P00001-01)
        $variant = $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$productId}/variants", [
            'color_name_en' => 'Red', 'size' => 'M', 'retail_price' => 100,
        ])->assertCreated()
            ->assertJsonPath('data.sku', $code.'-01');

        $barcode = (string) $variant->json('data.barcode');
        $this->assertMatchesRegularExpression('/^\d{13}$/', $barcode);
        $this->assertTrue($this->ean13CheckDigitIsValid($barcode), "Invalid EAN-13 check digit: {$barcode}");
    }

    public function test_two_variants_with_same_color_and_size_are_rejected(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();

        $payload = ['color_name_en' => 'Red', 'size' => 'M'];
        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", $payload)
            ->assertCreated();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", $payload)
            ->assertUnprocessable();
    }

    public function test_variant_of_another_product_returns_404(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $foreignVariant = ProductVariant::factory()->create(['product_id' => $other->id]);

        $this->requestWithToken($token, 'GET', "/api/v1/admin/products/{$product->id}/variants/{$foreignVariant->id}")
            ->assertNotFound();
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/variants/{$foreignVariant->id}", [
            'retail_price' => 10,
        ])->assertNotFound();
    }

    private function ean13CheckDigitIsValid(string $barcode): bool
    {
        $sum = 0;
        foreach (str_split(substr($barcode, 0, 12)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return ((10 - ($sum % 10)) % 10) === (int) $barcode[12];
    }

    private function adminToken(): string
    {
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);

        return $staff->createToken('test')->plainTextToken;
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
