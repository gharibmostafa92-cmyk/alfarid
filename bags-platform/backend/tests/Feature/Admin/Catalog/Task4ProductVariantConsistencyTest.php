<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task4ProductVariantConsistencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'products.view', 'products.create', 'products.update', 'products.delete',
        'products.publish', 'products.view_cost',
    ];

    private function adminToken(): string
    {
        foreach (self::ALL_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo(self::ALL_PERMISSIONS);

        return $staff->createToken('test')->plainTextToken;
    }

    public function test_variant_routes_reject_mismatched_parent_product(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();

        $product1 = Product::factory()->create(['category_id' => $category->id]);
        $product2 = Product::factory()->create(['category_id' => $category->id]);

        $variant1 = ProductVariant::factory()->create([
            'product_id' => $product1->id,
            'retail_price' => 100,
        ]);
        $variant2 = ProductVariant::factory()->create([
            'product_id' => $product2->id,
            'retail_price' => 200,
        ]);

        // GET with mismatched product ID must return 404
        $this->withToken($token)
            ->getJson("/api/v1/admin/products/{$product1->id}/variants/{$variant2->id}")
            ->assertNotFound();

        // PUT with mismatched product ID must return 404
        $this->withToken($token)
            ->putJson("/api/v1/admin/products/{$product1->id}/variants/{$variant2->id}", [
                'retail_price' => 250,
            ])
            ->assertNotFound();

        // DELETE with mismatched product ID must return 404
        $this->withToken($token)
            ->deleteJson("/api/v1/admin/products/{$product1->id}/variants/{$variant2->id}")
            ->assertNotFound();

        // Legitimate parent-variant access works
        $this->withToken($token)
            ->getJson("/api/v1/admin/products/{$product1->id}/variants/{$variant1->id}")
            ->assertOk();
    }
}
