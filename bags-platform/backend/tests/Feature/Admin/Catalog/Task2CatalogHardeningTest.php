<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\BarcodeGenerator;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\RetailPriceSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2CatalogHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function adminToken(array $perms): string
    {
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($perms);

        return $user->createToken('test')->plainTextToken;
    }

    public function test_retail_price_snapshot_refresh_uses_ordered_for_no_key_update(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 150, 'is_active' => true]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        app(RetailPriceSnapshot::class)->refresh($product->id);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => stripos($q, 'for no key update') !== false && stripos($q, 'order by') !== false),
            'RetailPriceSnapshot::refresh must lock products in id order using FOR NO KEY UPDATE.'
        );
    }

    public function test_product_destroy_enforces_expected_version_optimistic_lock(): void
    {
        $token = $this->adminToken(['products.view', 'products.delete', 'products.publish']);
        $product = Product::factory()->create(['status' => ProductStatus::Draft, 'lock_version' => 2]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->deleteJson("/api/v1/admin/products/{$product->id}?expected_version=1")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_category_reorder_enforces_expected_version_from_request(): void
    {
        $token = $this->adminToken(['categories.view', 'categories.update']);
        $cat = Category::factory()->create(['lock_version' => 3, 'sort_order' => 0]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson('/api/v1/admin/categories/reorder', [
                'items' => [
                    [
                        'id' => $cat->id,
                        'parent_id' => null,
                        'sort_order' => 5,
                        'expected_version' => 1,
                    ],
                ],
            ])
            ->assertStatus(409);
    }

    public function test_barcode_generator_enforces_11_digit_bounds_and_create_keeps_lock_version_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarcodeGenerator::fromId(0);
    }

    public function test_barcode_generator_rejects_overflow_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarcodeGenerator::fromId(100_000_000_000);
    }

    public function test_product_and_variant_creation_keep_lock_version_zero(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();

        $product = app(ProductService::class)->create([
            'name_ar' => 'حقيبة اختبار',
            'name_en' => 'Test Bag',
            'category_id' => $category->id,
            'status' => 'draft',
            'sales_channel' => 'retail',
            'variants' => [
                [
                    'color_name_en' => 'Black',
                    'color_name_ar' => 'أسود',
                    'retail_price' => 250,
                ],
            ],
        ], $user);

        $this->assertSame(0, (int) $product->fresh()->lock_version);
        $this->assertSame(0, (int) $product->variants()->firstOrFail()->lock_version);
    }

    public function test_product_index_search_uses_uppercase_indexed_sku_and_barcode_lookup(): void
    {
        $token = $this->adminToken(['products.view']);
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'BAG-XYZ-01']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/admin/products?search=bag-xyz-01')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertFalse(
            $queries->contains(fn (string $q) => stripos($q, 'LOWER(sku)') !== false || stripos($q, 'LOWER(barcode)') !== false),
            'ProductController::index should query uppercase sku/barcode directly to use unique B-Tree indexes.'
        );
    }
}
