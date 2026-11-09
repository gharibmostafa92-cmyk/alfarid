<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        $this->api('POST', "/api/v1/admin/orders/{$order->id}/deallocate")
            ->assertOk()->assertJsonPath('data.allocated_branch', null);
        $this->assertSame('0.0000', (string) $this->level($variant->id, $other)->reserved);
        $this->api('POST', "/api/v1/admin/orders/{$order->id}/deallocate")->assertUnprocessable();
        $this->assertSame('0.0000', (string) $this->level($variant->id, $other)->reserved);
        $carrier = ShippingCompany::factory()->create(['is_active' => true]);
        $shipment = $this->api('POST', "/api/v1/admin/orders/{$order->id}/shipment", ['shipping_company_id' => $carrier->id])
            ->assertUnprocessable();
        $this->assertStringContainsString('لا توجد حجوزات', $shipment->json('message'));
        $this->assertDatabaseCount('shipments', 0);
        $disabled = Branch::factory()->create(['is_active' => false]);
        $this->api('POST', "/api/v1/admin/orders/{$order->id}/allocate", ['branch_id' => $disabled->id])
            ->assertUnprocessable();

        $this->api('POST', "/api/v1/admin/orders/{$order->id}/allocate", ['branch_id' => $this->branch->id])
            ->assertOk()->assertJsonPath('data.allocated_branch.id', $this->branch->id);
        $this->assertSame('4.0000', (string) $this->level($variant->id)->reserved);
        $this->assertSame('0.0000', (string) $this->level($variant->id, $other)->reserved);

        $local = $this->staffWithPermissions(['orders.view', 'orders.allocate']);
        $local->update(['branch_id' => $this->branch->id]);
        $token = $local->createToken('allocate')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/orders/{$order->id}/deallocate")->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/orders/{$order->id}/allocate", ['branch_id' => $this->branch->id])
            ->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/orders')->assertOk()->assertJsonMissing(['id' => $order->id]);
        $this->assertSame('0.0000', (string) $this->level($variant->id)->reserved);
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

    public function test_order_routes_isolate_branches_and_require_the_correct_staff_permissions(): void
    {
        $other = Branch::factory()->create();
        $inactive = Branch::factory()->create(['is_active' => false]);
        $own = Order::factory()->create(['allocated_branch_id' => $this->branch->id, 'status' => OrderStatus::Pending]);
        $foreign = Order::factory()->create([
            'allocated_branch_id' => $other->id, 'status' => OrderStatus::Confirmed, 'payment_status' => 'unpaid',
        ]);
        $unallocated = Order::factory()->create(['allocated_branch_id' => null, 'status' => OrderStatus::Pending]);
        $company = ShippingCompany::factory()->create();
        $permissions = ['orders.view', 'orders.update', 'orders.allocate', 'orders.payment'];
        $local = $this->staffWithPermissions($permissions);
        $local->update(['branch_id' => $this->branch->id]);
        $disabled = $this->staffWithPermissions($permissions);
        $disabled->update(['branch_id' => $inactive->id]);
        $branchless = $this->staffWithPermissions($permissions);
        $branchless->update(['branch_id' => null]);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $base = '/api/v1/admin/orders';
        $target = "{$base}/{$foreign->id}";
        $payment = ['payment_status' => 'paid', 'notes' => 'Received'];
        $orderRoutes = [
            ['GET', $target, []],
            ['PATCH', "{$target}/status", ['status' => 'processing']],
            ['POST', "{$target}/payment", $payment],
            ['POST', "{$target}/shipment", ['shipping_company_id' => $company->id]],
            ['POST', "{$target}/allocate", ['branch_id' => $other->id]],
            ['POST', "{$target}/deallocate", []],
        ];
        $call = function (string $token, string $method, string $path, array $payload = []): TestResponse {
            Auth::forgetGuards();

            return $this->withToken($token)->json($method, $path, $payload);
        };

        $this->getJson($base)->assertUnauthorized();
        $customerToken = Customer::factory()->create()->createToken('customer')->plainTextToken;
        $this->assertContains($call($customerToken, 'GET', $base)->status(), [401, 403]);
        $this->assertContains($call($customerToken, ...$orderRoutes[1])->status(), [401, 403]);

        $localToken = $local->createToken('local')->plainTextToken;
        foreach ($orderRoutes as $route) {
            $call($localToken, ...$route)->assertForbidden();
        }
        $call($localToken, 'GET', $base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $call($localToken, 'GET', "{$base}/stats")->assertOk()->assertJsonPath('data.pending', 1);
        $call($localToken, 'GET', "{$base}?allocated_branch_id={$other->id}")->assertForbidden();
        $call($localToken, 'GET', "{$base}/{$unallocated->id}")->assertForbidden();
        foreach ([$disabled, $branchless] as $blocked) {
            $token = $blocked->createToken('blocked')->plainTextToken;
            foreach ($orderRoutes as $route) {
                $call($token, ...$route)->assertForbidden();
            }
            $call($token, 'GET', $base)->assertForbidden();
            $call($token, 'GET', "{$base}/stats")->assertForbidden();
        }

        foreach ([$this->admin, $superAdmin] as $central) {
            $token = $central->createToken('central')->plainTextToken;
            $call($token, 'GET', $target)->assertOk();
            $call($token, 'GET', "{$base}/{$unallocated->id}")->assertOk();
            $call($token, 'GET', "{$base}/stats")->assertOk()->assertJsonPath('data.pending', 2);
        }
        $call($this->admin->createToken('no-payment')->plainTextToken, 'POST', "{$target}/payment", $payment)
            ->assertForbidden();
        $this->assertSame(OrderStatus::Confirmed, $foreign->refresh()->status);
        $this->assertSame('unpaid', $foreign->payment_status);
        $this->assertSame($other->id, $foreign->allocated_branch_id);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
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
