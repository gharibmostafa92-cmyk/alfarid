<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\SupplierInvoiceStatus;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Http\Middleware\ManufacturingBusiness;
use App\Models\Treasury;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * F-01: branch users must not list, open, mutate, or allocate records that
 * belong to another branch or to central-only unlinked invoices.
 */
class TaskTxtProcurementBranchScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, RolePermissionSeeder::class]);
        $this->withoutMiddleware(ManufacturingBusiness::class);
    }

    public function test_branch_staff_cannot_see_or_create_supplier_invoices_from_another_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $supplier = Supplier::factory()->create();

        $poA = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branchA->id,
            'status' => PurchaseOrderStatus::SupplierAccepted,
            'subtotal' => 500,
            'grand_total' => 500,
        ]);
        $poB = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branchB->id,
            'status' => PurchaseOrderStatus::SupplierAccepted,
            'subtotal' => 500,
            'grand_total' => 500,
        ]);

        $invoiceA = SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $poA->id,
            'status' => SupplierInvoiceStatus::Posted,
        ]);
        $invoiceB = SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $poB->id,
            'status' => SupplierInvoiceStatus::Posted,
        ]);
        $central = SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'status' => SupplierInvoiceStatus::Posted,
        ]);

        $staff = User::factory()->create(['branch_id' => $branchA->id]);
        $staff->givePermissionTo(['purchases.view', 'purchases.create']);
        $this->withToken($staff->createToken('branch-scope')->plainTextToken);

        $this->getJson('/api/v1/admin/supplier-invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoiceA->id);

        $this->getJson("/api/v1/admin/supplier-invoices/{$invoiceB->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/supplier-invoices/{$central->id}")->assertNotFound();

        $payload = [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'BRANCH-SCOPE-1',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
        ];

        $this->postJson('/api/v1/admin/supplier-invoices', $payload)->assertForbidden();
        $this->assertDatabaseMissing('supplier_invoices', ['supplier_invoice_no' => 'BRANCH-SCOPE-1']);

        $this->postJson('/api/v1/admin/supplier-invoices', [
            ...$payload,
            'supplier_invoice_no' => 'BRANCH-SCOPE-2',
            'purchase_order_id' => $poB->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('supplier_invoices', ['supplier_invoice_no' => 'BRANCH-SCOPE-2']);
    }

    public function test_branch_staff_cannot_see_void_or_allocate_another_branch_payment(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $creator = User::factory()->create();

        $treasuryA = Treasury::create([
            'name' => 'A safe', 'code' => 'F01-A', 'type' => 'main_safe',
            'branch_id' => $branchA->id, 'current_balance' => '0.00', 'is_active' => true,
        ]);
        $treasuryB = Treasury::create([
            'name' => 'B safe', 'code' => 'F01-B', 'type' => 'main_safe',
            'branch_id' => $branchB->id, 'current_balance' => '0.00', 'is_active' => true,
        ]);

        $poB = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branchB->id,
            'status' => PurchaseOrderStatus::SupplierAccepted,
            'subtotal' => 500,
            'grand_total' => 500,
        ]);
        $invoiceB = SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $poB->id,
            'status' => SupplierInvoiceStatus::Posted,
        ]);

        $paymentA = SupplierPayment::factory()->create([
            'supplier_id' => $supplier->id,
            'treasury_id' => $treasuryA->id,
            'created_by' => $creator->id,
        ]);
        $paymentB = SupplierPayment::factory()->create([
            'supplier_id' => $supplier->id,
            'treasury_id' => $treasuryB->id,
            'created_by' => $creator->id,
        ]);

        $staff = User::factory()->create(['branch_id' => $branchA->id]);
        $staff->givePermissionTo([
            'purchases.view', 'supplier_payments.update', 'supplier_payments.delete',
        ]);
        $this->withToken($staff->createToken('branch-payment-scope')->plainTextToken);

        $this->getJson('/api/v1/admin/supplier-payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paymentA->id);

        $this->getJson("/api/v1/admin/supplier-payments/{$paymentB->id}")->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($staff->createToken('branch-payment-void')->plainTextToken)
            ->postJson("/api/v1/admin/supplier-payments/{$paymentB->id}/void", ['void_reason' => 'attempt'])
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($staff->createToken('branch-payment-allocate')->plainTextToken)
            ->postJson("/api/v1/admin/supplier-payments/{$paymentB->id}/allocate", [
                'supplier_invoice_id' => $invoiceB->id,
            ])->assertNotFound();

        $this->assertNull($paymentB->fresh()->voided_at);
        $this->assertNull($paymentB->fresh()->supplier_invoice_id);
    }
}

