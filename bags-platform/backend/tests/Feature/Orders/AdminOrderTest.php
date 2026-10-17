<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * المرحلة 7 (7.4 — جانب الإدارة): قائمة الطلبات وفلاترها، تغيير الحالة
 * عبر OrderService بآثار المخزون، ونقطة التوزيع اليدوي/المُجبر.
 */
class AdminOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->admin = $this->staffWithPermissions(['orders.view', 'orders.update', 'orders.allocate', 'inventory.all_branches']);
        $this->branch = Branch::factory()->create();
    }

    public function test_admin_lists_filters_and_shows_orders(): void
    {
        $cod = Order::factory()->create(['payment_method' => 'cod', 'status' => OrderStatus::Pending]);
        Order::factory()->create(['payment_method' => 'card', 'status' => OrderStatus::Delivered]);

        $this->api('GET', '/api/v1/admin/orders')->assertOk()->assertJsonCount(2, 'data');
        $this->api('GET', '/api/v1/admin/orders?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/api/v1/admin/orders?payment_method=card')->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/api/v1/admin/orders?search='.$cod->order_number)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', "/api/v1/admin/orders/{$cod->id}")
            ->assertOk()->assertJsonPath('data.order_number', $cod->order_number);
    }

    public function test_status_flow_confirm_allocates_ship_sells_and_deliver(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $order = $this->orderFromCart($variant, 3);
        $product = $variant->product;

        // pending → delivered مباشرة مرفوض من آلة الحالات
        $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertUnprocessable();

        $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame($this->branch->id, $order->fresh()->allocated_branch_id);
        $this->assertSame('3.0000', (string) $this->level($variant->id)->reserved);
        $this->assertSame('10.0000', (string) $this->level($variant->id)->on_hand);

        // الشحن من إنشاء البوليصة فقط (L3)
        $company = ShippingCompany::factory()->create();
        $this->api('POST', "/api/v1/admin/orders/{$order->id}/shipment", ['shipping_company_id' => $company->id])
            ->assertOk();
        $this->assertSame('shipped', $order->fresh()->status->value);
        $level = $this->level($variant->id);
        $this->assertSame('0.0000', (string) $level->reserved);
        $this->assertSame('7.0000', (string) $level->on_hand);
        $this->assertSame(3, (int) $product->fresh()->sales_count);

        $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        // لا إلغاء بعد التسليم
        $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertUnprocessable();

        $this->assertSame(4, $order->statusHistories()->count()); // إنشاء + تأكيد + شحن + تسليم
    }

    public function test_admin_cancel_of_confirmed_order_releases_reservation(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 8);
        $order = $this->orderFromCart($variant, 2);

        $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame('2.0000', (string) $this->level($variant->id)->reserved);

        $this->api('PATCH', "/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $level = $this->level($variant->id);
        $this->assertSame('0.0000', (string) $level->reserved);
        $this->assertSame('8.0000', (string) $level->on_hand);
    }

    public function test_manual_allocate_endpoint_with_forced_branch(): void
    {
        $variant = $this->purchasableVariant(100);
        $other = Branch::factory()->create();
        $this->giveStock($variant, 10, $this->branch);
        $this->giveStock($variant, 10, $other);

        // طلب مؤكد مباشرة (بلا توزيع) — نقطة التوزيع تصلحه
        $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);
        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'حقيبة',
            'sku_snapshot' => $variant->sku,
            'qty' => 4, 'unit_price' => 100, 'line_total' => 400,
        ]);

        // التوزيع على طلب pending مرفوض
        $pending = Order::factory()->create(['status' => OrderStatus::Pending]);
        $this->api('POST', "/api/v1/admin/orders/{$pending->id}/allocate", [])->assertUnprocessable();

        $this->api('POST', "/api/v1/admin/orders/{$order->id}/allocate", ['branch_id' => $other->id])
            ->assertOk()->assertJsonPath('data.allocated_branch.id', $other->id);
        $this->assertSame('4.0000', (string) $this->level($variant->id, $other)->reserved);

        // إعادة توزيع طلب مُوزّع مرفوضة
        $this->api('POST', "/api/v1/admin/orders/{$order->id}/allocate", [])->assertUnprocessable();
    }

    public function test_order_endpoints_require_permissions(): void
    {
        $plain = User::factory()->create();
        $token = $plain->createToken('t')->plainTextToken;
        $order = Order::factory()->create();

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/orders')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertForbidden();
    }

    // ---------- helpers ----------

    private function orderFromCart(ProductVariant $variant, float|int $qty): Order
    {
        $cart = Cart::create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => $qty]);

        return app(OrderService::class)->createFromCart($cart, [
            'recipient_name' => 'عميل', 'recipient_phone' => '01012345678',
            'payment_method' => 'cod',
        ]);
    }

    private function purchasableVariant(float $price): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => $price,
        ]);
    }

    private function giveStock(ProductVariant $variant, float|int $qty, ?Branch $branch = null): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $this->admin->id,
        ]);
    }

    private function level(int $variantId, ?Branch $branch = null): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', ($branch ?? $this->branch)->id)
            ->whereNull('location_id')
            ->firstOrFail();
    }

    private function staffWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function api(string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->json($method, $uri, $payload);
    }
}
