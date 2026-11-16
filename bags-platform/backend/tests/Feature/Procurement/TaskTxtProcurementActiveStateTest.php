<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** F-10: procurement transitions reject inactive branches, suppliers, locations, and variants. */
class TaskTxtProcurementActiveStateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;
    private Branch $branch;
    private Supplier $supplier;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
        $this->withoutMiddleware(\App\Http\Middleware\ManufacturingBusiness::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $this->variant = $product->variants()->first() ?? $product->variants()->create([
            'sku' => 'ACTIVE-STATE-1', 'cost_price' => 100, 'retail_price' => 200,
        ]);
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->admin->createToken('active-state')->plainTextToken);
    }

    private function createDraft(): array
    {
        return $this->asAdmin()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_variant_id' => $this->variant->id,
                'ordered_qty' => 10,
                'unit_cost' => 100,
                'tax_rate' => 0,
            ]],
        ])->assertCreated()->json('data');
    }

    private function approveAndSend(array $po): void
    {
        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        Auth::forgetGuards();
        $token = $approver->createToken('active-state-approver')->plainTextToken;
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();
        Auth::forgetGuards();
    }

    public function test_inactive_variants_are_rejected_for_purchase_orders_and_requests(): void
    {
        $this->variant->update(['is_active' => false]);
        $item = ['product_variant_id' => $this->variant->id, 'ordered_qty' => 2, 'unit_cost' => 100];

        $this->asAdmin()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'branch_id' => $this->branch->id, 'items' => [$item],
        ])->assertUnprocessable();
        $this->asAdmin()->postJson('/api/v1/admin/purchase-requests', [
            'branch_id' => $this->branch->id,
            'items' => [['product_variant_id' => $this->variant->id, 'requested_qty' => 2]],
        ])->assertUnprocessable();
    }

    public function test_inactive_supplier_or_branch_cannot_approve_or_send_an_order(): void
    {
        $supplierOrder = $this->createDraft();
        $this->supplier->update(['is_active' => false]);
        $this->asAdmin()->postJson("/api/v1/admin/purchase-orders/{$supplierOrder['id']}/approve")->assertUnprocessable();

        $this->supplier->update(['is_active' => true]);
        $branchOrder = $this->createDraft();
        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        $token = $approver->createToken('inactive-branch')->plainTextToken;
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$branchOrder['id']}/approve")->assertOk();
        $this->branch->update(['is_active' => false]);
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$branchOrder['id']}/send")->assertUnprocessable();
        $this->assertSame(PurchaseOrderStatus::Draft, PurchaseOrder::query()->findOrFail($branchOrder['id'])->status);
    }

    public function test_supplier_cannot_accept_an_order_for_an_inactive_branch(): void
    {
        $po = $this->createDraft();
        $this->approveAndSend($po);
        $this->branch->update(['is_active' => false]);
        $supplierUser = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);

        $this->withToken($supplierUser->createToken('supplier', ['supplier'])->plainTextToken)
            ->postJson("/api/v1/supplier/purchase-orders/{$po['id']}/accept")
            ->assertUnprocessable();
        $this->assertSame(PurchaseOrderStatus::SentToSupplier, PurchaseOrder::query()->findOrFail($po['id'])->status);
    }

    public function test_receipt_rejects_an_inactive_location(): void
    {
        $po = $this->createDraft();
        $this->approveAndSend($po);
        $location = Location::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);

        $this->asAdmin()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'location_id' => $location->id,
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 1]],
        ])->assertUnprocessable();
    }
}
