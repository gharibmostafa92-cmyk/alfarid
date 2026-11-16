<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Procurement\ProcurementService;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

/**
 * Supplier/procurement audit findings F01-F02, F07-F11, F13-F16, F18 and F19.
 */
class Task3SupplierAuditTest extends TestCase
{
    use ExercisesHistoricalResale;
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
        $this->exerciseHistoricalResale();
        $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->token = $this->admin->createToken('t')->plainTextToken;
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $this->variant = $product->variants()->first() ?? $product->variants()->create([
            'sku' => 'AUDIT-1', 'cost_price' => 100, 'retail_price' => 200,
        ]);
    }

    private function auth(): static
    {
        return $this->withToken($this->token);
    }

    public function test_supplier_payments_without_an_idempotency_key_do_not_share_a_fake_unique_key(): void
    {
        $service = app(SupplierFinanceService::class);
        $treasury = app(\App\Services\Pos\TreasuryService::class)->createAccount(['name' => 'Safe', 'code' => 'SUP-SAFE', 'type' => 'main_safe', 'is_active' => true], $this->admin, '100.00');
        $base = [
            'supplier_id' => $this->supplier->id,
            'treasury_id' => $treasury->id,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ];

        $first = $service->createPayment([...$base, 'amount' => 10], $this->admin);
        $second = $service->createPayment([...$base, 'amount' => 20], $this->admin);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($first->idempotency_key);
        $this->assertNull($second->idempotency_key);
        $this->assertSame(2, \App\Models\SupplierPayment::query()->where('supplier_id', $this->supplier->id)->count());
        $this->assertSame('70.00', $treasury->fresh()->current_balance);
        $service->voidPayment($first, $this->admin, 'test');
        $this->assertSame('80.00', $treasury->fresh()->current_balance);
        $this->assertDatabaseHas('treasury_transactions', ['reference_type' => 'supplier_payment', 'reference_id' => $first->id, 'type' => 'supplier_payment_reversal']);
    }

    public function test_invoice_payment_above_ten_billion_preserves_decimal_value(): void
    {
        $finance = app(SupplierFinanceService::class);
        $invoice = $finance->createInvoice([
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'LARGE-10000000000',
            'issue_date' => now()->toDateString(),
            'subtotal' => '9999999999.99',
            'tax_amount' => '1.00',
        ], actor: $this->admin);
        $finance->postInvoice($invoice, $this->admin);
        $treasury = app(\App\Services\Pos\TreasuryService::class)->createAccount(
            ['name' => 'Large pay', 'code' => 'SUP-LARGE', 'type' => 'main_safe', 'is_active' => true],
            $this->admin,
            '20000000001.98',
        );

        $payment = $finance->createPayment([
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_id' => $invoice->id,
            'treasury_id' => $treasury->id,
            'amount' => '10000000000.99',
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ], $this->admin);

        $this->assertSame('10000000000.99', $payment->amount);
        $this->assertSame('10000000000.99', $invoice->fresh()->paid_amount);
        $this->assertSame('0.00', $this->supplier->fresh()->current_balance);
        $this->assertSame('10000000000.99', $treasury->fresh()->current_balance);
    }

    public function test_purchase_order_line_total_uses_its_full_validated_decimal_capacity(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 99999999,
                'unit_cost' => '99999999.9999',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('9999999899990000.0001', (string) DB::table('purchase_order_items')->where('purchase_order_id', $po['id'])->value('line_total'));
        $this->assertSame('9999999899990000.00', (string) DB::table('purchase_orders')->where('id', $po['id'])->value('subtotal'));
    }

    public function test_purchase_order_discount_and_tax_keep_exact_cents(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'discount_amount' => '0.01',
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 3,
                'unit_cost' => '0.10',
                'tax_rate' => '10',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('0.30', (string) DB::table('purchase_orders')->where('id', $po['id'])->value('subtotal'));
        $this->assertSame('0.03', (string) DB::table('purchase_orders')->where('id', $po['id'])->value('tax_amount'));
        $this->assertSame('0.32', (string) DB::table('purchase_orders')->where('id', $po['id'])->value('grand_total'));
    }

    public function test_insufficient_treasury_funds_roll_back_supplier_payment(): void
    {
        $treasury = app(\App\Services\Pos\TreasuryService::class)->createAccount(['name' => 'Safe', 'code' => 'SUP-LOW', 'type' => 'main_safe', 'is_active' => true], $this->admin, '5.00');
        $before = $this->supplier->fresh()->current_balance;
        try {
            app(SupplierFinanceService::class)->createPayment(['supplier_id' => $this->supplier->id, 'treasury_id' => $treasury->id, 'amount' => 10, 'payment_method' => 'cash', 'payment_date' => now()->toDateString()], $this->admin);
            $this->fail('Payment must fail when the treasury has insufficient funds.');
        } catch (\App\Exceptions\BusinessException) {
            $this->assertSame($before, $this->supplier->fresh()->current_balance);
            $this->assertSame('5.00', $treasury->fresh()->current_balance);
            $this->assertDatabaseCount('supplier_payments', 0);
        }
    }

    public function test_bank_transfer_cannot_be_posted_to_a_cash_safe(): void
    {
        $treasury = app(\App\Services\Pos\TreasuryService::class)->createAccount(['name' => 'Safe', 'code' => 'SUP-WRONG', 'type' => 'main_safe', 'is_active' => true], $this->admin, '100.00');
        $this->expectException(\App\Exceptions\BusinessException::class);
        app(SupplierFinanceService::class)->createPayment(['supplier_id' => $this->supplier->id, 'treasury_id' => $treasury->id, 'amount' => 10, 'payment_method' => 'bank_transfer', 'reference_no' => 'BANK-1', 'payment_date' => now()->toDateString()], $this->admin);
    }

    public function test_payment_creator_can_list_only_accessible_payment_treasuries(): void
    {
        $staff = User::factory()->create(['branch_id' => $this->branch->id]);
        $staff->givePermissionTo('supplier_payments.create');
        $own = app(\App\Services\Pos\TreasuryService::class)->createAccount(['name' => 'Own', 'code' => 'OWN-PAY', 'type' => 'main_safe', 'branch_id' => $this->branch->id, 'is_active' => true], $this->admin);
        $other = app(\App\Services\Pos\TreasuryService::class)->createAccount(['name' => 'Other', 'code' => 'OTHER-PAY', 'type' => 'main_safe', 'branch_id' => Branch::factory()->create()->id, 'is_active' => true], $this->admin);
        $ids = $this->withToken($staff->createToken('staff')->plainTextToken)->getJson('/api/v1/admin/supplier-payments/treasuries')->assertOk()->json('data');
        $this->assertContains($own->id, array_column($ids, 'id'));
        $this->assertNotContains($other->id, array_column($ids, 'id'));
    }

    public function test_duplicate_receipt_lines_are_aggregated_into_one_movement(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100);
        $itemId = $po['items'][0]['id'];

        app(ProcurementService::class)->receivePurchaseOrder(
            PurchaseOrder::query()->findOrFail($po['id']),
            [
                ['purchase_order_item_id' => $itemId, 'qty' => 3],
                ['purchase_order_item_id' => $itemId, 'qty' => 2],
            ],
            null,
            null,
            $this->admin->id,
            'duplicate-lines',
        );

        $this->assertSame(1, StockMovement::query()->where('type', 'purchase_receipt')->count());
        $this->assertSame(5.0, (float) StockMovement::query()->where('type', 'purchase_receipt')->value('quantity'));
        $this->assertSame(5.0, (float) DB::table('purchase_order_items')->where('id', $itemId)->value('received_qty'));
    }

    public function test_receipt_request_rejects_duplicate_lines_at_validation_layer(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100);

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [
                ['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 1],
                ['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 1],
            ],
        ])->assertUnprocessable();
    }

    public function test_discount_reduces_the_tax_base_and_invoice_ceiling_matches_grand_total(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100, taxRate: 15, discount: 100);

        $this->assertSame('135.00', $po['tax_amount']);
        $this->assertSame('1035.00', $po['grand_total']);

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 10]],
        ])->assertOk();

        $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'TAX-OK',
            'issue_date' => now()->toDateString(),
            'subtotal' => 1035,
        ])->assertCreated();

        $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'TAX-OVER',
            'issue_date' => now()->toDateString(),
            'subtotal' => 1036,
        ])->assertUnprocessable();
    }

    public function test_weighted_average_cost_excludes_damaged_owned_units(): void
    {
        StockLevel::forceCreate([
            'product_variant_id' => $this->variant->id, 'branch_id' => $this->branch->id,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 10, 'in_transit' => 0,
        ]);
        $this->variant->update(['cost_price' => 50]);
        $po = $this->sentOrder(qty: 10, cost: 100);

        app(ProcurementService::class)->receivePurchaseOrder(
            PurchaseOrder::query()->findOrFail($po['id']),
            [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 10]],
            null,
            null,
            $this->admin->id,
            'damaged-wac',
        );

        $this->assertSame('75.0000', $this->variant->fresh()->cost_price);
    }

    public function test_empty_purchase_order_update_is_rejected_instead_of_laundering_last_editor(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 2,
                'unit_cost' => 10,
            ]],
        ])->assertCreated()->json('data');

        $editor = User::factory()->create();
        $editor->assignRole('Super Admin');
        Auth::forgetGuards();
        $this->withToken($editor->createToken('editor')->plainTextToken)
            ->patchJson("/api/v1/admin/purchase-orders/{$po['id']}", [])
            ->assertUnprocessable();

        $this->assertNull(PurchaseOrder::query()->findOrFail($po['id'])->last_modified_by);
    }

    public function test_supplier_cannot_request_changes_after_an_invoice_is_posted(): void
    {
        $po = $this->sentOrder(qty: 5, cost: 20);
        $portalUser = SupplierUser::factory()->for($this->supplier, 'supplier')->create();
        $portalToken = $portalUser->createToken('supplier', ['supplier'])->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($portalToken)
            ->postJson("/api/v1/supplier/purchase-orders/{$po['id']}/accept")
            ->assertOk();

        Auth::forgetGuards();
        // F05: posting requires received goods, so receive part of the order
        // before recording and posting the matching invoice.
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 2]],
        ])->assertOk();
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'PRE-PAY',
            'issue_date' => now()->toDateString(),
            'subtotal' => 40,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")->assertOk();

        Auth::forgetGuards();
        $this->withToken($portalToken)
            ->postJson("/api/v1/supplier/purchase-orders/{$po['id']}/request-change", ['notes' => 'too late'])
            ->assertUnprocessable();
    }

    public function test_supplier_portal_rejects_session_authenticated_requests_without_a_bearer_token(): void
    {
        $portalUser = SupplierUser::factory()->for($this->supplier, 'supplier')->create();

        Auth::forgetGuards();
        $this->actingAs($portalUser, 'sanctum')
            ->getJson('/api/v1/supplier/dashboard')
            ->assertForbidden();
    }

    public function test_future_supplier_invoice_issue_date_is_rejected(): void
    {
        $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'FUTURE',
            'issue_date' => now()->addMonth()->toDateString(),
            'subtotal' => 10,
        ])->assertUnprocessable();
    }

    public function test_supplier_delivery_accepts_an_uploaded_invoice_file(): void
    {
        $po = $this->sentOrder(qty: 2, cost: 50);
        $portalUser = SupplierUser::factory()->for($this->supplier, 'supplier')->create();
        $portalToken = $portalUser->createToken('supplier', ['supplier'])->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($portalToken)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->post("/api/v1/supplier/purchase-orders/{$po['id']}/deliveries", [
                'delivery_date' => now()->toDateString(),
                'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'shipped_qty' => 1]],
                'invoice_file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertNotNull(DB::table('supplier_deliveries')->value('invoice_file_path'));
    }

    public function test_query_filter_arrays_return_validation_errors_not_server_errors(): void
    {
        $this->auth()->getJson('/api/v1/admin/purchase-orders?status[]=draft')->assertUnprocessable();
        $this->auth()->getJson('/api/v1/admin/purchase-requests?branch_id[]=1')->assertUnprocessable();

        $po = $this->sentOrder(qty: 1, cost: 10);
        $portalUser = SupplierUser::factory()->for($this->supplier, 'supplier')->create();
        $portalToken = $portalUser->createToken('supplier', ['supplier'])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($portalToken)->getJson('/api/v1/supplier/purchase-orders?status[]=sent_to_supplier')->assertUnprocessable();
        $this->withToken($portalToken)->getJson('/api/v1/supplier/deliveries?purchase_order_id[]=1')->assertUnprocessable();
        $this->assertSame('sent_to_supplier', PurchaseOrder::query()->findOrFail($po['id'])->status->value);
    }

    public function test_store_supplier_user_requires_only_the_route_update_permission(): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('suppliers.update', 'staff'));
        $token = $staff->createToken('staff')->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/users", [
            'name' => 'Portal Staff',
            'email' => 'portal-staff@example.test',
            'password' => 'password123',
        ])->assertCreated();
    }

    public function test_date_fields_require_the_canonical_iso_format(): void
    {
        $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'expected_delivery_date' => 'next year',
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 1,
                'unit_cost' => 10,
            ]],
        ])->assertUnprocessable();
    }

    public function test_duplicate_supplier_indexes_are_removed_and_procurement_fk_indexes_exist(): void
    {
        $this->assertSame([], DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', ['supplier_payments_supplier_date_index', 'supplier_invoices_supplier_status_index'])
            ->pluck('indexname')->all());

        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexname')->all();
        $this->assertContains('purchase_request_items_purchase_request_id_index', $indexes);
        $this->assertContains('purchase_orders_purchase_request_id_index', $indexes);
        $this->assertContains('supplier_payments_supplier_id_payment_date_index', $indexes);
        $this->assertContains('supplier_invoices_supplier_id_status_index', $indexes);
    }

    public function test_purchase_order_api_is_branch_scoped_and_rejects_foreign_branches(): void
    {
        $otherBranch = Branch::factory()->create();
        $own = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'ordered_qty' => 1, 'unit_cost' => 10]],
        ])->assertCreated()->json('data');
        $foreign = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $otherBranch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'ordered_qty' => 1, 'unit_cost' => 10]],
        ])->assertCreated()->json('data');

        $staff = User::factory()->create(['branch_id' => $this->branch->id]);
        $staff->givePermissionTo(['purchases.view', 'purchases.create']);
        $token = $staff->createToken('branch-staff')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)->getJson("/api/v1/admin/purchase-orders/{$own['id']}")->assertOk();
        $this->withToken($token)->getJson("/api/v1/admin/purchase-orders/{$foreign['id']}")->assertForbidden();

        $ids = $this->withToken($token)->getJson('/api/v1/admin/purchase-orders')->assertOk()->json('data.*.id');
        $this->assertContains($own['id'], $ids);
        $this->assertNotContains($foreign['id'], $ids);

        $this->withToken($token)->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $otherBranch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'ordered_qty' => 1, 'unit_cost' => 10]],
        ])->assertForbidden();
    }

    public function test_procurement_service_fails_closed_without_an_authenticated_actor(): void
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        Auth::forgetGuards();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ProcurementService::class)->sendPurchaseOrder($po);
    }

    public function test_close_short_rejects_posted_invoices_above_the_received_value(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100);
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 5]],
        ])->assertOk();

        // F05: an invoice above the received value (600 > 500) can be recorded
        // as a draft, but posting it must fail the 3-way match.
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'OVER-RECEIVED',
            'issue_date' => now()->toDateString(),
            'subtotal' => 600,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")->assertUnprocessable();

        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/cancel")->assertOk();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/close-short")->assertOk();
        $this->assertSame('completed', PurchaseOrder::query()->findOrFail($po['id'])->status->value);
    }

    public function test_zero_total_supplier_invoices_are_rejected(): void
    {
        $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'ZERO',
            'issue_date' => now()->toDateString(),
            'subtotal' => 0,
        ])->assertUnprocessable();
    }

    public function test_inactive_supplier_cannot_receive_an_unlinked_payment(): void
    {
        $treasury = app(\App\Services\Pos\TreasuryService::class)->createAccount(
            ['name' => 'Inactive Safe', 'code' => 'INACTIVE-SAFE', 'type' => 'main_safe', 'is_active' => true],
            $this->admin,
            '100.00',
        );
        $this->supplier->update(['is_active' => false]);

        $this->expectException(\App\Exceptions\BusinessException::class);
        app(SupplierFinanceService::class)->createPayment([
            'supplier_id' => $this->supplier->id,
            'treasury_id' => $treasury->id,
            'amount' => 10,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ], $this->admin);
    }

    public function test_admin_statement_endpoint_returns_the_supplier_ledger(): void
    {
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'STATEMENT-1',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")->assertOk();

        $statement = $this->auth()->getJson("/api/v1/admin/suppliers/{$this->supplier->id}/statement?from_date=".now()->startOfYear()->toDateString().'&to_date='.now()->toDateString())
            ->assertOk()->json('data');

        $this->assertSame($this->supplier->id, $statement['supplier']['id']);
        $this->assertNotEmpty($statement['entries']);
        $this->assertSame(100.0, (float) $statement['closing_balance']);
    }

    public function test_supplier_payment_date_requires_the_canonical_iso_format(): void
    {
        $this->auth()->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'treasury_id' => 1,
            'amount' => 10,
            'payment_method' => 'cash',
            'payment_date' => '15/11/2026',
        ])->assertUnprocessable();
    }

    /**
     * @return array<string, mixed>
     */
    private function sentOrder(float $qty = 10, float $cost = 100, float $taxRate = 0, float $discount = 0): array
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'discount_amount' => $discount,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => $taxRate,
            ]],
        ])->assertCreated()->json('data');

        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        Auth::forgetGuards();
        $this->withToken($approver->createToken('approver')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")->assertOk();
        Auth::forgetGuards();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        return $po;
    }
}
