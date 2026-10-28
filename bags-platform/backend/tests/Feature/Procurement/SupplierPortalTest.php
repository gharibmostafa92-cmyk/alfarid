<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\ProcurementService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

/**
 * المرحلة 10 (بوابة المورد): دخول supplier_users، عزل بيانات كل مورد،
 * قبول/طلب تعديل أوامر الشراء، إشعارات التوريد واستلامها، وكشف الحساب.
 */
class SupplierPortalTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    private Supplier $supplierA;

    private Supplier $supplierB;

    private SupplierUser $userA;

    private Branch $branch;

    private ProductVariant $variant;

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $data, ['Idempotency-Key' => (string) Str::uuid(), ...$headers], $options);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseHistoricalResale();
        $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);

        $this->supplierA = Supplier::factory()->create(['code' => 'SUP-A']);
        $this->supplierB = Supplier::factory()->create(['code' => 'SUP-B']);
        $this->userA = SupplierUser::factory()->for($this->supplierA, 'supplier')->create([
            'email' => 'portal-a@example.com',
        ]);
        $this->branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->variant = $product->variants()->first() ?? $product->variants()->create([
            'sku' => 'PORT-1', 'cost_price' => 50, 'retail_price' => 90,
        ]);
    }

    private function makePo(Supplier $supplier, PurchaseOrderStatus $status, float $qty = 10): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->for($supplier)->for($this->branch)->create([
            'status' => $status, 'created_by' => User::factory()->create()->id,
            'subtotal' => $qty * 50, 'grand_total' => $qty * 50,
        ]);
        PurchaseOrderItem::factory()->for($po)->create([
            'product_variant_id' => $this->variant->id,
            'ordered_qty' => $qty, 'unit_cost' => 50, 'line_total' => $qty * 50,
        ]);

        return $po->fresh(['items']);
    }

    private function login(string $email = 'portal-a@example.com', string $password = 'password123')
    {
        return $this->postJson('/api/v1/supplier/auth/login', [
            'email' => $email, 'password' => $password,
        ]);
    }

    private function loginToken(): string
    {
        return $this->login()->assertOk()->json('token');
    }

    public function test_existing_token_returns_403_after_supplier_is_disabled(): void
    {
        $token = $this->loginToken();
        $this->supplierA->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/v1/supplier/dashboard')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/supplier/statement')->assertForbidden();
    }

    public function test_stale_order_cannot_be_accepted_after_supplier_reassignment(): void
    {
        $po = $this->makePo($this->supplierA, PurchaseOrderStatus::SentToSupplier);
        PurchaseOrder::query()->whereKey($po->id)->update(['supplier_id' => $this->supplierB->id]);

        try {
            app(ProcurementService::class)->supplierAccept($po, $this->userA);
            $this->fail('An order reassigned to another supplier must be inaccessible.');
        } catch (BusinessException $exception) {
            $this->assertSame(404, $exception->status);
        }

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id, 'supplier_id' => $this->supplierB->id, 'status' => 'sent_to_supplier',
        ]);
    }

    public function test_delivery_returns_422_if_direct_receipts_already_use_remaining_quantity(): void
    {
        $po = $this->makePo($this->supplierA, PurchaseOrderStatus::PartiallyReceived);
        $po->items->first()->update(['received_qty' => 7]);
        $token = $this->loginToken();

        $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/deliveries", [
            'delivery_date' => '2026-10-19',
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'shipped_qty' => 4]],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('supplier_deliveries', ['purchase_order_id' => $po->id]);
    }

    public function test_order_with_delivery_cannot_be_reassigned_to_another_supplier(): void
    {
        $po = $this->makePo($this->supplierA, PurchaseOrderStatus::SupplierAccepted);
        $token = $this->loginToken();
        $delivery = $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/deliveries", [
            'delivery_date' => '2026-10-19',
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'shipped_qty' => 3]],
        ])->assertCreated()->json('data.id');
        $po->update(['status' => PurchaseOrderStatus::ChangeRequested]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        Auth::forgetGuards();

        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->patchJson("/api/v1/admin/purchase-orders/{$po->id}", ['supplier_id' => $this->supplierB->id])
            ->assertUnprocessable();

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'supplier_id' => $this->supplierA->id]);
        $this->assertDatabaseHas('supplier_deliveries', ['id' => $delivery, 'supplier_id' => $this->supplierA->id]);
    }

    public function test_login_issues_token_and_rejects_bad_credentials(): void
    {
        $response = $this->login()->assertOk();
        $response->assertJsonPath('supplier.code', 'SUP-A');
        $this->assertNotEmpty($response->json('token'));

        $this->login(password: 'wrong-password')->assertUnprocessable();

        $inactive = SupplierUser::factory()->for($this->supplierA, 'supplier')->create(['is_active' => false]);
        $this->login(email: $inactive->email)->assertUnprocessable();
    }

    public function test_dashboard_and_order_isolation_between_suppliers(): void
    {
        $this->supplierA->update(['current_balance' => 750]);
        $this->makePo($this->supplierA, PurchaseOrderStatus::SentToSupplier);
        $accepted = $this->makePo($this->supplierA, PurchaseOrderStatus::SupplierAccepted);
        $otherPo = $this->makePo($this->supplierB, PurchaseOrderStatus::SentToSupplier);
        SupplierPayment::factory()->for($this->supplierA)->create(['amount' => 300]);
        SupplierPayment::factory()->for($this->supplierA)->create(['amount' => 200]);

        $token = $this->loginToken();

        $this->withToken($token)->getJson('/api/v1/supplier/dashboard')
            ->assertOk()
            ->assertJsonPath('data.new_orders_count', 1)
            ->assertJsonPath('data.in_progress_count', 1)
            ->assertJsonPath('data.current_balance', '750.00')
            ->assertJsonCount(2, 'data.last_payments');

        $list = $this->withToken($token)->getJson('/api/v1/supplier/purchase-orders')
            ->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertNotContains($otherPo->id, array_column($list, 'id'));

        // أمر مورد آخر: غير موجود أصلًا في عالم هذا المستخدم
        $this->withToken($token)->getJson("/api/v1/supplier/purchase-orders/{$otherPo->id}")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$otherPo->id}/accept")->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/supplier/purchase-orders/{$accepted->id}")
            ->assertOk()->assertJsonPath('data.id', $accepted->id);
    }

    public function test_accept_and_request_change_transitions(): void
    {
        $po = $this->makePo($this->supplierA, PurchaseOrderStatus::SentToSupplier);
        $token = $this->loginToken();

        $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/accept")
            ->assertOk()->assertJsonPath('data.status', 'supplier_accepted');

        // القبول مرة تانية من حالة خاطئة مرفوض
        $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/accept")
            ->assertUnprocessable();

        $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/request-change", [
            'notes' => 'سعر الصنف الأول زاد 5% — نحتاج مراجعة',
        ])->assertOk()
            ->assertJsonPath('data.status', 'change_requested')
            ->assertJsonPath('data.supplier_change_notes', 'سعر الصنف الأول زاد 5% — نحتاج مراجعة');
    }

    public function test_delivery_asn_shipment_cap_and_admin_receipt(): void
    {
        $po = $this->makePo($this->supplierA, PurchaseOrderStatus::SupplierAccepted, 10);
        $itemId = $po->items->first()->id;
        $token = $this->loginToken();

        $delivery = $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/deliveries", [
            'delivery_date' => '2026-10-19',
            'supplier_invoice_no' => 'PAPER-900',
            'items' => [['purchase_order_item_id' => $itemId, 'shipped_qty' => 5]],
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('DLV-', $delivery['delivery_number']);
        $this->assertSame('pending_receipt', $delivery['status']);

        // شحن تراكمي فوق المطلوب (5 + 6 > 10) مرفوض
        $this->withToken($token)->postJson("/api/v1/supplier/purchase-orders/{$po->id}/deliveries", [
            'delivery_date' => '2026-10-20',
            'items' => [['purchase_order_item_id' => $itemId, 'shipped_qty' => 6]],
        ])->assertUnprocessable();

        // أمين المخزن يستلم الإشعار من إدارة أمر الشراء
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $adminToken = $admin->createToken('t')->plainTextToken;
        $this->withToken($adminToken)->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'supplier_delivery_id' => $delivery['id'],
            'items' => [['purchase_order_item_id' => $itemId, 'qty' => 5]],
        ])->assertOk()->assertJsonPath('data.status', 'partially_received');

        $this->assertDatabaseHas('supplier_deliveries', ['id' => $delivery['id'], 'status' => 'received']);
        $this->assertDatabaseHas('supplier_delivery_items', [
            'supplier_delivery_id' => $delivery['id'], 'received_qty' => 5,
        ]);
        $level = StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')->first();
        $this->assertEquals(5.0, (float) $level->on_hand);
    }

    public function test_statement_running_balance_math(): void
    {
        // رصيد افتتاحي 1000 + فاتورة معتمدة 500 (دائن) − دفعة 200 (مدين) = 1300
        $supplier = Supplier::factory()->create([
            'code' => 'SUP-STMT', 'opening_balance' => 1000, 'current_balance' => 1000,
            'payment_terms_days' => 30,
        ]);
        $user = SupplierUser::factory()->for($supplier, 'supplier')->create([
            'email' => 'stmt@example.com',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $adminToken = $admin->createToken('t')->plainTextToken;

        $invoice = $this->withToken($adminToken)->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'PAPER-1',
            'issue_date' => '2026-10-01',
            'subtotal' => 500, 'tax_amount' => 0,
        ])->assertCreated()->json('data');
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/supplier-invoices/{$invoice['id']}/post")->assertOk();
        $this->withToken($adminToken)->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $supplier->id,
            'amount' => 200, 'payment_method' => 'bank_transfer', 'reference_no' => 'BANK-200', 'payment_date' => '2026-10-05',
        ])->assertCreated();

        $token = $this->login(email: $user->email)->assertOk()->json('token');
        $stmt = $this->withToken($token)
            ->getJson('/api/v1/supplier/statement?from_date=2026-09-01&to_date=2026-10-31')
            ->assertOk()->json('data');

        $this->assertSame(1000.0, (float) $stmt['opening_balance']);
        $this->assertCount(2, $stmt['entries']);
        $this->assertSame('invoice', $stmt['entries'][0]['type']);
        $this->assertSame(500.0, (float) $stmt['entries'][0]['credit']);
        $this->assertSame(1500.0, (float) $stmt['entries'][0]['running_balance']);
        $this->assertSame('payment', $stmt['entries'][1]['type']);
        $this->assertSame(200.0, (float) $stmt['entries'][1]['debit']);
        $this->assertSame(1300.0, (float) $stmt['entries'][1]['running_balance']);
        $this->assertSame(1300.0, (float) $stmt['closing_balance']);
        $this->assertSame('1300.00', (string) $stmt['supplier']['current_balance']);
    }
}
