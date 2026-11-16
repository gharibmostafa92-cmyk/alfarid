<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Middleware\ManufacturingBusiness;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pos\TreasuryService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-03: invoice ceilings, inactive suppliers, and draft invoices.
 */
class TaskTxtProcurementInvoiceCeilingTest extends TestCase
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
        $this->withoutMiddleware(ManufacturingBusiness::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->token = $this->admin->createToken('invoice-ceiling')->plainTextToken;
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $this->variant = $product->variants()->first() ?? $product->variants()->create([
            'sku' => 'CEILING-1', 'cost_price' => 100, 'retail_price' => 200,
        ]);
    }

    private function auth(): static
    {
        return $this->withToken($this->token);
    }

    public function test_an_inactive_supplier_cannot_post_a_draft_invoice(): void
    {
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'INACTIVE-DRAFT',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
        ])->assertCreated()->json('data.id');

        $this->supplier->update(['is_active' => false]);

        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")
            ->assertUnprocessable();
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice, 'status' => 'draft']);
    }

    public function test_invoice_cannot_be_posted_before_any_purchase_order_goods_are_received(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100);
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'NOT-RECEIVED-YET',
            'issue_date' => now()->toDateString(),
            'subtotal' => 1000,
        ])->assertCreated()->json('data.id');

        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")
            ->assertUnprocessable();
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice, 'status' => 'draft']);
    }

    public function test_an_inactive_supplier_cannot_receive_a_payment_linked_to_an_invoice(): void
    {
        $invoice = $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_no' => 'INACTIVE-LINKED',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
        ])->assertCreated()->json('data.id');
        $this->auth()->postJson("/api/v1/admin/supplier-invoices/{$invoice}/post")->assertOk();

        $treasury = app(TreasuryService::class)->createAccount([
            'name' => 'Invoice Ceiling Safe',
            'code' => 'CEILING-SAFE',
            'type' => 'main_safe',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ], $this->admin, '1000.00');

        $this->supplier->update(['is_active' => false]);

        $this->auth()->postJson('/api/v1/admin/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_id' => $invoice,
            'treasury_id' => $treasury->id,
            'amount' => 10,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_a_draft_invoice_does_not_block_closing_a_short_received_order(): void
    {
        $po = $this->sentOrder(qty: 10, cost: 100);

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 5]],
        ])->assertOk();

        $this->auth()->postJson('/api/v1/admin/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po['id'],
            'supplier_invoice_no' => 'DRAFT-SHORT',
            'issue_date' => now()->toDateString(),
            'subtotal' => 600,
        ])->assertCreated();

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/close-short")
            ->assertOk();

        $this->assertSame(PurchaseOrderStatus::Completed, PurchaseOrder::query()->findOrFail($po['id'])->status);
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
        $this->withToken($approver->createToken('ceiling-approver')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")
            ->assertOk();
        Auth::forgetGuards();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        return $po;
    }
}
