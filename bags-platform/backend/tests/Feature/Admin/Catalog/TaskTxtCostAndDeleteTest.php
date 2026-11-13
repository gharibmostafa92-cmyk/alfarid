<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\SalesChannel;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Catalog\ProductService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 7-8: cost never leaks without products.view_cost,
 * and product delete semantics (soft delete, stock, publish perm, carts).
 */
class TaskTxtCostAndDeleteTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_catalog_tools_enabled',
            'ai_catalog_vision_enabled',
            'ai_catalog_copywriting_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private static int $seq = 0;

    private function costFixture(): array
    {
        $n = 94000 + (++self::$seq);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $cat->id,
            'code' => 'P'.$n,
            'status' => ProductStatus::Draft,
            'sales_channel' => SalesChannel::Both,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'P'.$n.'-BLK',
            'cost_price' => 187.65,
            'last_cost_price' => 187.65,
            'retail_price' => 450.00,
            'wholesale_price' => 320.00,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function scanJson(string $json, string $needle = '187.65'): void
    {
        $this->assertStringNotContainsString($needle, $json);
        $this->assertStringNotContainsString('cost_price', $json);
    }

    // ── task 7: cost never leaks on any path ──
    public function test_task7_cost_never_leaks_without_permission(): void
    {
        [$product, $variant] = $this->costFixture();
        $viewer = $this->staff(['products.view', 'products.create', 'products.update']);
        $this->assertFalse($viewer->can('products.view_cost'));
        $token = $this->token($viewer);

        // REST reads.
        $show = $this->withToken($token)->getJson("/api/v1/admin/products/{$product->id}")->assertOk();
        $this->scanJson($show->getContent());
        $vshow = $this->withToken($token)->getJson("/api/v1/admin/products/{$product->id}/variants/{$variant->id}")->assertOk();
        $this->scanJson($vshow->getContent());

        // REST writes with cost_price are ignored (stays 187.65).
        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/variants/{$variant->id}", [
            'cost_price' => 10,
            'expected_version' => $variant->fresh()->lock_version,
        ])->assertOk();
        $this->assertSame(187.65, (float) $variant->fresh()->cost_price);

        // Assistant read tools.
        $conv = AiConversation::create(['user_id' => $viewer->id, 'model' => 'gemini-test']);
        $registry = app(ToolRegistry::class);
        foreach ([
            ['name' => 'get_product', 'args' => ['product_id' => $product->id]],
            ['name' => 'lookup_code', 'args' => ['code' => $variant->sku]],
            ['name' => 'search_products', 'args' => ['query' => $product->code]],
        ] as $call) {
            $out = $registry->dispatch($viewer, $conv, $call);
            $this->scanJson(json_encode(['d' => $out->data, 'u' => $out->uiBlock], JSON_UNESCAPED_UNICODE));
        }

        // Health report has no retail<=cost section and no cost values.
        $health = $registry->dispatch($viewer, $conv, ['name' => 'catalog_health_report', 'args' => []]);
        $healthJson = json_encode(['d' => $health->data, 'u' => $health->uiBlock], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->assertStringNotContainsString('187.65', (string) $healthJson);
        $this->assertStringNotContainsString('التكلفة', (string) $healthJson);

        // Audit log carries no cost.
        $auditJson = DB::table('staff_audit_logs')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('187.65', $auditJson);
        $this->assertStringNotContainsString('cost_price', $auditJson);

        // Stored assistant messages carry no cost.
        $msgJson = DB::table('ai_messages')->where('conversation_id', $conv->id)->pluck('content')->implode(' ');
        $this->assertStringNotContainsString('187.65', $msgJson);
    }

    private function draftProduct(string $code): array
    {
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $cat->id,
            'code' => $code,
            'status' => ProductStatus::Draft,
            'sales_channel' => SalesChannel::Retail,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => $code.'-BLK',
            'retail_price' => 400,
            'wholesale_price' => null,
            'cost_price' => 50,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function adminToken(array $extra = []): array
    {
        $admin = $this->staff(array_merge([
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.publish',
        ], $extra));

        return [$admin, $this->token($admin)];
    }

    // ── task 8a: draft without stock → soft delete, media kept ──
    public function test_task8a_delete_draft_soft_deletes_and_keeps_media(): void
    {
        [$admin, $token] = $this->adminToken();
        [$product, $variant] = $this->draftProduct('P95111');

        DB::table('media')->insert([
            'model_type' => Product::class,
            'model_id' => $product->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'collection_name' => 'images',
            'name' => 'p',
            'file_name' => 'p.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'r2_public',
            'size' => 10,
            'manipulations' => json_encode([]),
            'custom_properties' => json_encode([]),
            'generated_conversions' => json_encode([]),
            'responsive_images' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        // Media rows survive the soft delete.
        $this->assertDatabaseHas('media', ['model_type' => Product::class, 'model_id' => $product->id]);
    }

    // ── task 8b: variant with stock balance blocks deletion ──
    public function test_task8b_delete_blocked_by_stock_balance(): void
    {
        [$admin, $token] = $this->adminToken();
        [$product, $variant] = $this->draftProduct('P95222');
        $branch = \App\Models\Branch::factory()->create();
        $stock = new StockLevel;
        $stock->forceFill([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'location_id' => null,
            'on_hand' => 5,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
        ])->save();

        $res = $this->withToken($token)->deleteJson("/api/v1/admin/products/{$product->id}");
        $res->assertStatus(422);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'deleted_at' => null]);
    }

    // ── task 8c: published product needs products.publish ──
    public function test_task8c_delete_published_requires_publish_permission(): void
    {
        [$product, $variant] = $this->draftProduct('P95333');
        $product->forceFill(['status' => ProductStatus::Published])->save();
        $editor = $this->staff(['products.view', 'products.delete']);

        $this->withToken($this->token($editor))->deleteJson("/api/v1/admin/products/{$product->id}")->assertStatus(403);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    // ── task 8d: cart items cleaned on product delete ──
    public function test_task8d_cart_items_cleaned_on_product_delete(): void
    {
        [$admin, $token] = $this->adminToken();
        [$product, $variant] = $this->draftProduct('P95444');
        CartItem::factory()->create(['product_variant_id' => $variant->id]);
        $this->assertSame(1, CartItem::where('product_variant_id', $variant->id)->count());

        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$product->id}")->assertOk();

        $this->assertSame(0, CartItem::where('product_variant_id', $variant->id)->count());
    }

    public function test_f16_product_code_collision_does_not_return_500_on_create_or_update(): void
    {
        [$admin, $token] = $this->adminToken();
        $cat = Category::factory()->create(['is_active' => true]);

        // Seed an imported product occupying the code matching the next auto-increment ID.
        $existing = Product::factory()->create(['category_id' => $cat->id]);
        $nextIdCode = 'P'.str_pad((string) ($existing->id + 1), 5, '0', STR_PAD_LEFT);
        $existing->update(['code' => $nextIdCode]);

        $created = $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id,
            'name_ar' => 'منتج مصادم',
            'name_en' => 'Colliding Code Product',
            'sales_channel' => 'retail',
        ])->assertCreated();

        $this->assertNotSame($nextIdCode, $created->json('data.code'));

        // Explicit duplicate code collision on update throws 409 BusinessException instead of raw SQL 500.
        $other = Product::findOrFail($created->json('data.id'));
        try {
            app(ProductService::class)->update($other, ['code' => $nextIdCode], $admin);
            $this->fail('Expected 409 BusinessException on duplicate product code.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_f17_order_history_keeps_soft_deleted_product_image_and_blocks_force_delete_and_active_orders(): void
    {
        $this->fakeUploads();
        [$admin, $token] = $this->adminToken();
        $customer = Customer::factory()->create();
        $cat = Category::factory()->create(['is_active' => true]);

        $historical = Product::factory()->published()->create(['category_id' => $cat->id]);
        $histVariant = ProductVariant::factory()->create(['product_id' => $historical->id, 'retail_price' => 250]);
        $historical->addMedia(UploadedFile::fake()->image('bag.jpg'))->toMediaCollection('images');

        $deliveredOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered,
        ]);
        OrderItem::create([
            'order_id' => $deliveredOrder->id,
            'product_variant_id' => $histVariant->id,
            'product_name_snapshot' => $historical->name_ar,
            'sku_snapshot' => $histVariant->sku,
            'qty' => 1,
            'unit_price' => 250,
            'line_total' => 250,
        ]);

        // Soft-delete allowed once order is delivered (no active orders / stock),
        // and customer order endpoints still resolve item image.
        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$historical->id}")->assertOk();

        Auth::forgetGuards();
        $customerToken = $customer->createToken('store')->plainTextToken;
        $orderList = $this->withToken($customerToken)->getJson('/api/v1/store/account/orders')->assertOk();
        $this->assertNotEmpty($orderList->json('data.0.items.0.image'));
        $orderShow = $this->withToken($customerToken)->getJson("/api/v1/store/account/orders/{$deliveredOrder->order_number}")->assertOk();
        $this->assertNotEmpty($orderShow->json('data.items.0.image'));

        // forceDelete must be blocked with BusinessException when historical order items exist.
        try {
            app(ProductService::class)->forceDelete($historical->fresh());
            $this->fail('forceDelete must refuse products referenced by order items.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }

        // Active (in-transit / uncompleted) order blocks soft-delete too.
        $activeProduct = Product::factory()->published()->create(['category_id' => $cat->id]);
        $activeVariant = ProductVariant::factory()->create(['product_id' => $activeProduct->id, 'retail_price' => 300]);
        $shippedOrder = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Shipped]);
        OrderItem::create([
            'order_id' => $shippedOrder->id,
            'product_variant_id' => $activeVariant->id,
            'product_name_snapshot' => $activeProduct->name_ar,
            'sku_snapshot' => $activeVariant->sku,
            'qty' => 1,
            'unit_price' => 300,
            'line_total' => 300,
        ]);

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$activeProduct->id}")->assertUnprocessable();
        $this->assertNotSoftDeleted($activeProduct);
    }

    public function test_f19_wallet_index_has_no_per_row_queries_for_reference_label(): void
    {
        $customer = Customer::factory()->create();
        for ($i = 1; $i <= 10; $i++) {
            CustomerWalletTransaction::create([
                'customer_id' => $customer->id,
                'type' => 'checkout_payment',
                'amount' => -10.00,
                'balance_after' => 100.00 - ($i * 10),
                'reference_type' => 'order',
                'reference_id' => $i,
                'created_at' => now(),
            ]);
        }

        $token = $customer->createToken('wallet')->plainTextToken;
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $res = $this->withToken($token)->getJson('/api/v1/store/account/wallet')->assertOk();
        $this->assertSame('طلب #10', $res->json('transactions.data.0.reference_label'));
        $orderQueries = array_filter($queries, fn (string $sql) => str_contains($sql, '"orders"'));
        $this->assertCount(0, $orderQueries);
    }
}
