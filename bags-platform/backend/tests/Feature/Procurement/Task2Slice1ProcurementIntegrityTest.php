<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Http\Resources\Admin\Procurement\PurchaseOrderResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Procurement\ProcurementService;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

class Task2Slice1ProcurementIntegrityTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    private User $admin;

    private User $secondAdmin;

    private Branch $branch;

    private Branch $secondBranch;

    private Supplier $supplier;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private ProcurementService $procurement;

    private SupplierFinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->secondAdmin = User::factory()->create();
        $this->secondAdmin->assignRole('Super Admin');

        $this->branch = Branch::query()->create(['name' => 'Branch 1', 'code' => 'BR1', 'is_active' => true]);
        $this->secondBranch = Branch::query()->create(['name' => 'Branch 2', 'code' => 'BR2', 'is_active' => true]);

        $this->supplier = Supplier::query()->create([
            'code' => 'SUP-TEST-1',
            'name' => 'Test Supplier',
            'payment_terms_days' => 30,
            'is_active' => true,
            'opening_balance' => '0.00',
            'current_balance' => '0.00',
        ]);

        $product = Product::factory()->create();

        $this->variantA = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'cost_price' => 100.00,
            'retail_price' => 150.00,
            'wholesale_price' => 120.00,
        ]);

        $this->variantB = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'cost_price' => 200.00,
            'retail_price' => 250.00,
            'wholesale_price' => 220.00,
        ]);

        $this->procurement = app(ProcurementService::class);
        $this->finance = app(SupplierFinanceService::class);
    }

    public function test_update_purchase_order_rejects_identical_items_no_op(): void
    {
        $po = $this->procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'ordered_qty' => 5, 'unit_cost' => 100.00, 'tax_rate' => 14],
            ],
        ], $this->admin);

        $initialItemId = $po->items()->first()->id;

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('لا يوجد تغيير فعلي في أمر الشراء.');

        $this->procurement->updatePurchaseOrder($po, [
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'ordered_qty' => 5, 'unit_cost' => 100.00, 'tax_rate' => 14],
            ],
        ], $this->secondAdmin);
    }

    public function test_update_purchase_order_updates_items_when_changed(): void
    {
        $po = $this->procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'ordered_qty' => 5, 'unit_cost' => 100.00, 'tax_rate' => 14],
            ],
        ], $this->admin);

        $updated = $this->procurement->updatePurchaseOrder($po, [
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'ordered_qty' => 8, 'unit_cost' => 100.00, 'tax_rate' => 14],
            ],
        ], $this->secondAdmin);

        $this->assertSame(8, (int) $updated->items()->first()->ordered_qty);
        $this->assertSame($this->secondAdmin->id, $updated->last_modified_by);
    }

    public function test_execute_payment_rejects_inactive_treasury(): void
    {
        $treasury = Treasury::query()->create([
            'name' => 'Inactive Safe',
            'code' => 'SAFE-INACT',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $this->branch->id,
            'is_active' => false,
            'current_balance' => '1000.00',
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('الخزينة المحددة غير نشطة.');

        $this->finance->createPayment([
            'supplier_id' => $this->supplier->id,
            'treasury_id' => $treasury->id,
            'payment_method' => 'cash',
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
        ], $this->admin);
    }

    public function test_create_purchase_order_rejects_cross_branch_pr(): void
    {
        $pr = $this->procurement->createPurchaseRequest([
            'branch_id' => $this->secondBranch->id,
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'requested_qty' => 5],
            ],
        ], $this->admin);
        $this->procurement->submitPurchaseRequest($pr, $this->admin);
        $this->procurement->approvePurchaseRequest($pr, $this->secondAdmin);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('فرع أمر الشراء يجب أن يطابق فرع طلب الشراء الداخلي المرتبط.');

        $this->procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id, // Mismatch with secondBranch!
            'purchase_request_id' => $pr->id,
        ], $this->admin);
    }

    public function test_create_purchase_order_rejects_mismatched_pr_items(): void
    {
        $pr = $this->procurement->createPurchaseRequest([
            'branch_id' => $this->branch->id,
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'requested_qty' => 5],
            ],
        ], $this->admin);
        $this->procurement->submitPurchaseRequest($pr, $this->admin);
        $this->procurement->approvePurchaseRequest($pr, $this->secondAdmin);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('بنود أمر الشراء يجب أن تطابق أصناف طلب الشراء الداخلي المرتبط.');

        $this->procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'purchase_request_id' => $pr->id,
            'items' => [
                // variantB is not in PR!
                ['product_variant_id' => $this->variantB->id, 'ordered_qty' => 2, 'unit_cost' => 200.00, 'tax_rate' => 0],
            ],
        ], $this->admin);
    }

    public function test_purchase_order_last_modifier_relation_and_resource(): void
    {
        $po = $this->procurement->createPurchaseOrder([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'items' => [
                ['product_variant_id' => $this->variantA->id, 'ordered_qty' => 5, 'unit_cost' => 100.00, 'tax_rate' => 14],
            ],
        ], $this->admin);

        $this->procurement->updatePurchaseOrder($po, [
            'expected_delivery_date' => now()->addDays(5)->toDateString(),
        ], $this->secondAdmin);

        $po->refresh();
        $this->assertTrue($po->relationLoaded('lastModifier') || method_exists($po, 'lastModifier'));
        $this->assertSame($this->secondAdmin->id, $po->lastModifier?->id);

        $resource = (new PurchaseOrderResource($po))->toArray(Request::create('/'));
        $this->assertArrayHasKey('last_modified_by', $resource);
        $this->assertSame($this->secondAdmin->id, $resource['last_modified_by']);
    }
}
