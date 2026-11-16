<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\PurchaseRequestStatus;
use App\Enums\Procurement\SupplierDeliveryStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

/**
 * Roadmap close-out for procurement items that were still open after the audit.
 */
class TaskTxtProcurementCloseoutTest extends TestCase
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
            'sku' => 'CLOSE-1', 'cost_price' => 100, 'retail_price' => 200,
        ]);
    }

    private function auth(): static
    {
        return $this->withToken($this->token);
    }

    public function test_zero_quantity_receipt_does_not_trap_the_purchase_order(): void
    {
        $po = $this->sentOrder();

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 0]],
        ])->assertUnprocessable();

        $this->assertSame(PurchaseOrderStatus::SentToSupplier, PurchaseOrder::query()->findOrFail($po['id'])->status);
    }

    public function test_purchase_order_with_a_live_invoice_cannot_be_cancelled(): void
    {
        $po = $this->sentOrder(qty: 4, cost: 25);
        // F05: a draft invoice within the order total no longer needs received
        // goods to be recorded, and still blocks cancellation while live.
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'LIVE-1',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
        ])->assertCreated()->json('data.id');

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/cancel")->assertUnprocessable();
        $this->assertSame(PurchaseOrderStatus::SentToSupplier, PurchaseOrder::query()->findOrFail($po['id'])->status);
    }

    public function test_deleting_a_cancelled_order_keeps_the_request_converted_while_another_order_is_live(): void
    {
        $first = $this->sentOrder();
        $second = $this->sentOrder();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$first['id']}/cancel")->assertOk();

        $request = PurchaseRequest::factory()->create([
            'branch_id' => $this->branch->id,
            'requested_by' => $this->admin->id,
            'status' => PurchaseRequestStatus::ConvertedToPo,
        ]);
        PurchaseOrder::query()->whereIn('id', [$first['id'], $second['id']])->update([
            'purchase_request_id' => $request->id,
        ]);

        $this->auth()->deleteJson("/api/v1/admin/purchase-orders/{$first['id']}")->assertOk();

        $this->assertSame(PurchaseRequestStatus::ConvertedToPo, $request->fresh()->status);
    }

    public function test_the_last_person_who_edited_a_purchase_order_cannot_approve_it(): void
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
            ->patchJson("/api/v1/admin/purchase-orders/{$po['id']}", [
                'expected_delivery_date' => now()->addDays(3)->toDateString(),
            ])->assertOk();

        Auth::forgetGuards();
        $this->withToken($editor->createToken('editor-approve')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")
            ->assertUnprocessable();

        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        Auth::forgetGuards();
        $this->withToken($approver->createToken('approver')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")
            ->assertOk();
    }

    public function test_a_draft_purchase_order_cannot_receive_an_invoice(): void
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 1,
                'unit_cost' => 50,
            ]],
        ])->assertCreated()->json('data');

        $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'DRAFT-PO',
            'issue_date' => now()->toDateString(),
            'subtotal' => 50,
        ])->assertUnprocessable();
    }

    public function test_a_completed_short_order_cannot_be_invoiced_for_the_unordered_remainder(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100);
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 4]],
        ])->assertOk();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/close-short")->assertOk();

        // F05: the draft fits the order total so it can be recorded, but posting
        // it must fail the 3-way match against the 400 actually received.
        $over = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'OVER-RECEIVED',
            'issue_date' => now()->toDateString(),
            'subtotal' => 1000,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$over}/post")->assertUnprocessable();
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$over}/cancel")->assertOk();

        $matching = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'MATCHES-RECEIVED',
            'issue_date' => now()->toDateString(),
            'subtotal' => 400,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$matching}/post")->assertOk();
    }

    public function test_supplier_statement_hides_the_internal_void_reason(): void
    {
        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $this->supplier->id,
            'amount' => 25,
            'payment_date' => now()->toDateString(),
            'voided_at' => now(),
            'void_reason' => 'internal mismatch note',
            'created_by' => $this->admin->id,
        ]);

        $supplierView = app(SupplierFinanceService::class)->statement($this->supplier->fresh());
        $adminView = app(SupplierFinanceService::class)->statement($this->supplier->fresh(), null, null, true);

        $supplierText = json_encode($supplierView, JSON_UNESCAPED_UNICODE);
        $adminText = json_encode($adminView, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($supplierText);
        $this->assertStringNotContainsString('internal mismatch note', $supplierText);
        $this->assertStringContainsString('internal mismatch note', (string) $adminText);
        $this->assertNotNull($payment->id);
    }

    public function test_payment_date_cannot_be_in_the_future(): void
    {
        $this->auth()->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'amount' => 10,
            'payment_method' => 'cash',
            'payment_date' => now()->addDay()->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_deactivating_a_supplier_revokes_portal_tokens(): void
    {
        $portalUser = SupplierUser::factory()->for($this->supplier, 'supplier')->create();
        $plain = $portalUser->createToken('supplier-token', ['supplier'])->plainTextToken;
        $this->assertNotNull(PersonalAccessToken::findToken($plain));

        $this->auth()->patchJson("/api/v1/admin/suppliers/{$this->supplier->id}", [
            'code' => $this->supplier->code,
            'name' => $this->supplier->name,
            'is_active' => false,
        ])->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($plain));
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function test_close_short_does_not_label_a_partially_received_notice_as_rejected(bool $noticeOnly): void
    {
        $po = $this->sentOrder(qty: 10, cost: 20);
        $order = PurchaseOrder::query()->findOrFail($po['id']);
        $notice = $order->deliveries()->create([
            'delivery_number' => 'ASN-CLOSE-1',
            'supplier_id' => $this->supplier->id,
            'status' => SupplierDeliveryStatus::PendingReceipt,
            'delivery_date' => now()->toDateString(),
        ]);
        $notice->items()->create([
            'purchase_order_item_id' => $po['items'][0]['id'],
            'product_variant_id' => $this->variant->id,
            'shipped_qty' => 10,
            'received_qty' => 4,
        ]);

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 4]],
            'supplier_delivery_id' => $notice->id,
        ])->assertOk();
        $path = $noticeOnly
            ? "/api/v1/admin/supplier-deliveries/{$notice->id}/close-short"
            : "/api/v1/admin/purchase-orders/{$po['id']}/close-short";
        $this->auth()->postJson($path)->assertOk();

        $this->assertSame(SupplierDeliveryStatus::ClosedShort, $notice->fresh()->status);
        $this->assertSame($noticeOnly ? 'partially_received' : 'completed', $order->fresh()->status->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentOrder(float $qty = 10, float $cost = 100): array
    {
        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => 0,
            ]],
        ])->assertCreated()->json('data');

        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        Auth::forgetGuards();
        $this->withToken($approver->createToken('approver')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")
            ->assertOk();
        Auth::forgetGuards();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        return $po;
    }
}
