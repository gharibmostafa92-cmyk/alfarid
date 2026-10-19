<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * المرحلة 10 (إدارة): الموردون وطلبات الشراء الداخلية وأوامر الشراء
 * واستلامها بمتوسط التكلفة المرجح + فواتير الموردين ودفعاتهم وأرصدتها.
 */
class ProcurementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private string $token;

    private Branch $branch;

    private Supplier $supplier;

    private ProductVariant $variant;

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $data, ['Idempotency-Key' => (string) Str::uuid(), ...$headers], $options);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->token = $this->admin->createToken('t')->plainTextToken;
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $this->variant = $product->variants()->first() ?? $product->variants()->create([
            'sku' => 'WAC-1', 'cost_price' => 100, 'retail_price' => 200,
        ]);
        $this->variant->update(['cost_price' => 100]);
    }

    private function auth(): static
    {
        return $this->withToken($this->token);
    }

    private function createSentPo(float $qty = 10, float $cost = 130): array
    {
        $response = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => $qty, 'unit_cost' => $cost, 'tax_rate' => 14,
            ]],
        ])->assertCreated();
        $po = $response->json('data');
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")->assertOk();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        return $po;
    }

    public function test_supplier_create_initializes_current_balance_from_opening(): void
    {
        $response = $this->auth()->postJson('/api/v1/admin/suppliers', [
            'code' => 'SUP-NEW', 'name' => 'مورد جديد', 'opening_balance' => 250,
            'payment_terms_days' => 45,
        ])->assertCreated();

        $response->assertJsonPath('data.current_balance', '250.00');
        $this->assertDatabaseHas('suppliers', ['code' => 'SUP-NEW', 'current_balance' => 250]);
    }

    public function test_opening_balance_update_preserves_posted_transactions_and_statement_balance(): void
    {
        $this->freezeTime();
        $this->supplier->update(['opening_balance' => 100, 'current_balance' => 100]);
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'OPENING-REVIEW',
            'issue_date' => now()->toDateString(), 'subtotal' => 200,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")->assertOk();
        $this->auth()->patchJson("/api/v1/admin/suppliers/{$this->supplier->id}", [
            'code' => $this->supplier->code, 'name' => $this->supplier->name, 'opening_balance' => 150,
            'current_balance' => 999,
        ])->assertOk()->assertJsonPath('data.current_balance', '350.00');

        $this->assertDatabaseHas('suppliers', ['id' => $this->supplier->id, 'opening_balance' => 150, 'current_balance' => 350]);
        $statement = app(SupplierFinanceService::class)->statement($this->supplier->fresh());
        $this->assertSame(350.0, $statement['closing_balance']);
    }

    public function test_purchase_order_can_update_delivery_date_without_replacing_items(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'ordered_qty' => 5, 'unit_cost' => 20]],
        ])->assertCreated()->json('data');

        $this->auth()->patchJson("/api/v1/admin/purchase-orders/{$po['id']}", [
            'expected_delivery_date' => '2026-11-01',
        ])->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['id' => $po['id'], 'expected_delivery_date' => '2026-11-01']);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $po['items'][0]['id'], 'ordered_qty' => 5]);
    }

    public function test_purchase_request_flow_and_conversion_to_po(): void
    {
        $this->variant->update(['cost_price' => 40]);

        $pr = $this->auth()->postJson('/api/v1/admin/purchase-requests', [
            'branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 8]],
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('PR-', $pr['request_number']);
        $this->assertSame('draft', $pr['status']);

        // الاعتماد من draft مرفوض — لازم إرسال الأول
        $this->auth()->postJson("/api/v1/admin/purchase-requests/{$pr['id']}/approve")->assertUnprocessable();
        $this->auth()->postJson("/api/v1/admin/purchase-requests/{$pr['id']}/submit")->assertOk();
        $this->auth()->postJson("/api/v1/admin/purchase-requests/{$pr['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        // أمر شراء من الطلب: البنود والفرع منسوخة من الطلب
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'purchase_request_id' => $pr['id'],
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('PO-', $po['po_number']);
        $this->assertSame($this->branch->id, $po['branch_id']);
        $this->assertSame('8.0000', $po['items'][0]['ordered_qty']);
        $this->assertSame('40.0000', $po['items'][0]['unit_cost']);
        $this->assertSame('320.00', $po['subtotal']);

        $this->auth()->getJson("/api/v1/admin/purchase-requests/{$pr['id']}")
            ->assertOk()->assertJsonPath('data.status', 'converted_to_po');
    }

    public function test_purchase_order_totals_include_tax_and_discount(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'discount_amount' => 30,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 10, 'unit_cost' => 50, 'tax_rate' => 14,
            ]],
        ])->assertCreated()->json('data');

        // subtotal 500، ضريبة 70، خصم 30 → الإجمالي 540
        $this->assertSame('500.0000', $po['items'][0]['line_total']);
        $this->assertSame('500.00', $po['subtotal']);
        $this->assertSame('70.00', $po['tax_amount']);
        $this->assertSame('540.00', $po['grand_total']);
        $this->assertSame('draft', $po['status']);
    }

    public function test_receive_updates_weighted_average_cost_and_stock(): void
    {
        // رصيد حالي 10 بتكلفة 100، واستلام 10 بسعر شراء 130 ⇒ المتوسط 115
        StockLevel::create([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'location_id' => null, 'on_hand' => 10, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
        ]);
        $po = $this->createSentPo(10, 130);
        $itemId = $po['items'][0]['id'];

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $itemId, 'qty' => 10]],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $variant = $this->variant->fresh();
        $this->assertSame('115.0000', (string) $variant->cost_price);
        $this->assertSame('130.0000', (string) $variant->last_cost_price);

        $level = StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')
            ->first();
        $this->assertEquals(20.0, (float) $level->on_hand);

        $movement = StockMovement::query()
            ->where('reference_type', 'purchase_order')
            ->where('reference_id', $po['id'])
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame(StockMovementType::PurchaseReceipt->value, $movement->type->value);
        $this->assertEquals(10.0, (float) $movement->quantity);
        $this->assertEquals(130.0, (float) $movement->unit_cost);
    }

    public function test_partial_receive_then_complete_and_over_receive_rejected(): void
    {
        $po = $this->createSentPo(10, 50);
        $itemId = $po['items'][0]['id'];

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $itemId, 'qty' => 4]],
        ])->assertOk()->assertJsonPath('data.status', 'partially_received');

        // المتبقي 6 فقط — استلام 7 مرفوض
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $itemId, 'qty' => 7]],
        ])->assertUnprocessable();

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $itemId, 'qty' => 6]],
        ])->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.items.0.received_qty', '10.0000');
    }

    public function test_receive_on_draft_po_is_rejected(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 5, 'unit_cost' => 10,
            ]],
        ])->assertCreated()->json('data');

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 5]],
        ])->assertUnprocessable();
    }

    public function test_invoice_posting_and_payments_move_supplier_balance(): void
    {
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'PAPER-100',
            'issue_date' => '2026-10-01',
            'subtotal' => 1000, 'tax_amount' => 140,
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('SIN-', $invoice['invoice_number']);
        $this->assertSame('1140.00', $invoice['total_amount']);
        // الاستحقاق الافتراضي: الإصدار + أجل المورد (30 في الفاكتوري)
        $this->assertSame('2026-10-31', $invoice['due_date']);
        $this->assertSame('0.00', (string) $this->supplier->fresh()->current_balance);

        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('1140.00', (string) $this->supplier->fresh()->current_balance);

        $p1 = $this->auth()->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_id' => $invoice['id'],
            'amount' => 500, 'payment_method' => 'bank_transfer', 'reference_no' => 'BANK-500', 'payment_date' => '2026-10-05',
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('SPY-', $p1['payment_number']);
        $this->assertSame('640.00', (string) $this->supplier->fresh()->current_balance);
        $this->auth()->getJson("/api/v1/admin/supplier-invoices/{$invoice['id']}")
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', '500.00')
            ->assertJsonPath('data.remaining_amount', '640.00');

        // دفع أكثر من المتبقي على الفاتورة مرفوض
        $this->auth()->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_id' => $invoice['id'],
            'amount' => 641, 'payment_method' => 'cash', 'payment_date' => '2026-10-06',
        ])->assertUnprocessable();

        $this->auth()->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_id' => $invoice['id'],
            'amount' => 640, 'payment_method' => 'cash', 'payment_date' => '2026-10-06',
        ])->assertCreated();
        $this->assertSame('0.00', (string) $this->supplier->fresh()->current_balance);
        $this->auth()->getJson("/api/v1/admin/supplier-invoices/{$invoice['id']}")
            ->assertJsonPath('data.status', 'paid');

        // حذف أول دفعة يعكس أثرها: الرصيد يرجع والفاتورة ترجع جزئية
        $this->auth()->deleteJson("/api/v1/admin/supplier-payments/{$p1['id']}")->assertOk();
        $this->assertSame('500.00', (string) $this->supplier->fresh()->current_balance);
        $this->auth()->getJson("/api/v1/admin/supplier-invoices/{$invoice['id']}")
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', '640.00');
    }

    public function test_invoice_cancel_after_posting_reverses_balance(): void
    {
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'PAPER-200',
            'issue_date' => '2026-10-02',
            'subtotal' => 200, 'tax_amount' => 0,
        ])->assertCreated()->json('data');

        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice['id']}/post")->assertOk();
        $this->assertSame('200.00', (string) $this->supplier->fresh()->current_balance);

        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice['id']}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('0.00', (string) $this->supplier->fresh()->current_balance);
    }

    public function test_permissions_gate_procurement_endpoints(): void
    {
        $plain = User::factory()->create();
        $token = $plain->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/suppliers')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/purchase-orders')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/supplier-invoices')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/supplier-payments')->assertForbidden();
    }
}
