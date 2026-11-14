<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class Task2Slice3AuthorizationAndShippingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('Super Admin');
        $this->token = $this->staff->createToken('admin-token')->plainTextToken;
    }

    public function test_critical04_branch_staff_cannot_reallocate_split_branch_order(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $branchUser = User::factory()->create(['branch_id' => $branchA->id]);
        \Spatie\Permission\Models\Permission::findOrCreate('orders.allocate', 'staff');
        $branchUser->givePermissionTo('orders.allocate');
        $branchToken = $branchUser->createToken('branch-token')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'قياسي',
            'sku' => 'SKU-'.uniqid(),
            'retail_price' => 100,
            'is_active' => true,
        ]);

        $splitOrder = Order::factory()->create([
            'status' => OrderStatus::Confirmed,
            'allocated_branch_id' => null,
        ]);
        OrderItem::query()->create([
            'order_id' => $splitOrder->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'allocated_branch_id' => $branchA->id,
        ]);
        OrderItem::query()->create([
            'order_id' => $splitOrder->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'allocated_branch_id' => $branchB->id,
        ]);

        $res = $this->withToken($branchToken)->postJson("/api/v1/admin/orders/{$splitOrder->id}/allocate", [
            'branch_id' => $branchA->id,
        ]);

        $res->assertStatus(403);
    }

    public function test_medium03_manual_update_payment_blocks_refunded_for_wallet_credit_pos(): void
    {
        foreach (['wallet', 'credit'] as $method) {
            $order = Order::factory()->create([
                'channel' => 'store',
                'payment_method' => $method,
                'payment_status' => 'paid',
            ]);

            $res = $this->withToken($this->token)->postJson("/api/v1/admin/orders/{$order->id}/payment", [
                'payment_status' => 'refunded',
                'refund_reference' => 'MANUAL-REF-1',
            ]);

            $res->assertStatus(422);
            $this->assertSame('paid', $order->fresh()->payment_status);
        }
    }

    public function test_medium04_shipping_zone_destroy_with_rates_returns_422_instead_of_500(): void
    {
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        ShippingRate::query()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'cod_fee' => 10,
            'estimated_days_min' => 1,
            'estimated_days_max' => 3,
            'is_active' => true,
        ]);

        $res = $this->withToken($this->token)->deleteJson("/api/v1/admin/shipping-zones/{$zone->id}");

        $res->assertStatus(422);
        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
    }
}
