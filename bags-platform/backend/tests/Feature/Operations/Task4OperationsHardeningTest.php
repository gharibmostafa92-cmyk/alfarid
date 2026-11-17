<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ShippingCompany;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task4OperationsHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private string $token;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->token = $this->admin->createToken('admin-token')->plainTextToken;
        $this->branch = Branch::factory()->create();
    }

    public function test_treasury_transfers_index_filters_by_unified_treasury_id(): void
    {
        $t1 = Treasury::create(['code' => 'TR-A', 'name' => 'Safe A', 'type' => 'main_safe', 'branch_id' => $this->branch->id, 'current_balance' => 1000, 'is_active' => true]);
        $t2 = Treasury::create(['code' => 'TR-B', 'name' => 'Safe B', 'type' => 'main_safe', 'branch_id' => $this->branch->id, 'current_balance' => 500, 'is_active' => true]);
        $t3 = Treasury::create(['code' => 'TR-C', 'name' => 'Safe C', 'type' => 'main_safe', 'branch_id' => $this->branch->id, 'current_balance' => 500, 'is_active' => true]);

        $fromT1 = TreasuryTransfer::create([
            'transfer_number' => 'TR-1001',
            'from_treasury_id' => $t1->id,
            'to_treasury_id' => $t2->id,
            'amount' => 100,
            'status' => 'pending',
            'created_by' => $this->admin->id,
        ]);
        $toT1 = TreasuryTransfer::create([
            'transfer_number' => 'TR-1002',
            'from_treasury_id' => $t3->id,
            'to_treasury_id' => $t1->id,
            'amount' => 200,
            'status' => 'pending',
            'created_by' => $this->admin->id,
        ]);
        TreasuryTransfer::create([
            'transfer_number' => 'TR-1003',
            'from_treasury_id' => $t2->id,
            'to_treasury_id' => $t3->id,
            'amount' => 50,
            'status' => 'pending',
            'created_by' => $this->admin->id,
        ]);

        $res = $this->withToken($this->token)
            ->getJson("/api/v1/admin/treasury-transfers?treasury_id={$t1->id}")
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$fromT1->id, $toT1->id], $ids);
    }

    public function test_returns_stats_and_per_page_work_without_n_plus_one_fanout(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'allocated_branch_id' => $this->branch->id,
            'status' => OrderStatus::Delivered,
        ]);

        ReturnRequest::create([
            'return_number' => 'RET-1001',
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'status' => ReturnStatus::Pending->value,
            'reason' => 'defective',
        ]);
        ReturnRequest::create([
            'return_number' => 'RET-1002',
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'status' => ReturnStatus::Approved->value,
            'reason' => 'wrong_item',
        ]);

        $stats = $this->withToken($this->token)
            ->getJson('/api/v1/admin/returns/stats')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $stats['pending']);
        $this->assertSame(1, $stats['approved']);
        $this->assertSame(2, $stats['total']);

        $page = $this->withToken($this->token)
            ->getJson('/api/v1/admin/returns?per_page=1')
            ->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(2, $page->json('meta.total'));
    }

    public function test_create_shipment_and_payment_notice_approve_support_idempotency_key(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $treasury = Treasury::create(['code' => 'TR-PN', 'name' => 'Main Safe', 'type' => 'main_safe', 'branch_id' => $this->branch->id, 'is_active' => true, 'current_balance' => 0]);
        $notice = WholesalePaymentNotice::create([
            'notice_number' => 'PN-1001',
            'customer_id' => $customer->id,
            'amount' => '250.00',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-1001',
            'transfer_date' => '2026-11-10',
            'receipt_file_path' => 'payment-notices/test.pdf',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $key = 'pn-approve-key-1';
        $first = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
                'treasury_id' => $treasury->id,
                'admin_notes' => 'verified',
            ])
            ->assertOk();

        $second = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
                'treasury_id' => $treasury->id,
                'admin_notes' => 'verified',
            ])
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertEquals(250.0, (float) $treasury->fresh()->current_balance);

        $company = ShippingCompany::factory()->create(['is_active' => true]);
        Location::factory()->create(['branch_id' => $this->branch->id]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        app(\App\Services\Inventory\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $this->branch->id,
            'type' => \App\Enums\Inventory\StockMovementType::AdjustmentIn,
            'affected_bucket' => \App\Enums\Inventory\StockBucket::OnHand,
            'quantity' => 10,
            'created_by' => $this->admin->id,
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Confirmed,
        ]);
        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Test Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);
        $this->withToken($this->token)
            ->postJson("/api/v1/admin/orders/{$order->id}/allocate", ['branch_id' => $this->branch->id])
            ->assertOk();

        $shipKey = 'ship-key-1';
        $ship1 = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', $shipKey)
            ->postJson("/api/v1/admin/orders/{$order->id}/shipment", [
                'shipping_company_id' => $company->id,
                'awb_number' => 'AWB-99901',
            ])
            ->assertSuccessful();

        $ship2 = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', $shipKey)
            ->postJson("/api/v1/admin/orders/{$order->id}/shipment", [
                'shipping_company_id' => $company->id,
                'awb_number' => 'AWB-99901',
            ])
            ->assertOk();

        $this->assertSame($ship1->json('data.id'), $ship2->json('data.id'));
    }
}
