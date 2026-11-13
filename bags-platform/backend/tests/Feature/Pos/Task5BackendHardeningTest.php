<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Finance\TreasuryType;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Inventory\StocktakeStatus;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stocktake;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task5BackendHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private User $stockCounter;
    private Treasury $posDrawer;
    private ProductVariant $variant1;
    private ProductVariant $variant2;
    private string $cashierToken;
    private string $counterToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
        config(['inventory.pos_max_discount_percent' => 100]);

        $this->branch = Branch::create([
            'name' => 'Cairo Branch',
            'code' => 'BR-CAIRO',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier Omar',
            'email' => 'omar.cashier@example.com',
            'phone' => '+201011112233',
            'password' => Hash::make('secret123'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->stockCounter = User::create([
            'name' => 'Counter Ziad',
            'email' => 'ziad.counter@example.com',
            'phone' => '+201011112244',
            'password' => Hash::make('secret123'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $cashierRole = Role::findOrCreate('cashier', 'staff');
        $this->cashier->assignRole($cashierRole);

        foreach (['pos.access', 'pos.sales.create', 'pos.sales.refund', 'pos.discount', 'pos.override_price'] as $perm) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($perm, 'staff'));
        }

        // Counter has only pos.access and stocktakes.update (NO pos.sales.create)
        foreach (['pos.access', 'stocktakes.update'] as $perm) {
            $this->stockCounter->givePermissionTo(Permission::findOrCreate($perm, 'staff'));
        }

        $this->posDrawer = Treasury::create([
            'name' => 'Omar Drawer',
            'code' => 'DRW-OMAR',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        tap(Treasury::create([
            'name' => 'Cairo Main Safe',
            'code' => 'SAFE-CAIRO-5',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]), fn (Treasury $t) => $t->forceFill(['current_balance' => 10000])->save());

        $brand = Brand::create(['name_ar' => 'براند ت5', 'name_en' => 'Brand T5', 'slug' => 'brand-t5', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'أقسام', 'name_en' => 'Cats', 'slug' => 'cats', 'is_active' => true]);
        $prod = Product::create([
            'code' => 'PRD-T5-01',
            'name_ar' => 'منتج 1',
            'name_en' => 'Product 1',
            'slug' => 'product-1',
            'status' => ProductStatus::Published,
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->variant1 = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'VAR-T5-01',
            'barcode' => '6220000000001',
            'color_name_ar' => 'أزرق',
            'retail_price' => 100.00,
            'cost_price' => 50.00,
            'is_active' => true,
        ]);

        $this->variant2 = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'VAR-T5-02',
            'barcode' => '6220000000002',
            'color_name_ar' => 'أحمر',
            'retail_price' => 100.00,
            'cost_price' => 50.00,
            'is_active' => true,
        ]);

        $inv = app(InventoryService::class);
        $inv->recordMovement([
            'product_variant_id' => $this->variant1->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 100,
            'created_by' => $this->cashier->id,
        ]);
        $inv->recordMovement([
            'product_variant_id' => $this->variant2->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 100,
            'created_by' => $this->cashier->id,
        ]);

        $this->cashierToken = $this->cashier->createToken('pos', ['pos'])->plainTextToken;
        $this->counterToken = $this->stockCounter->createToken('pos', ['pos'])->plainTextToken;
    }

    private function openCashierShift(): int
    {
        $res = $this->withToken($this->cashierToken)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer->id,
            'opening_cash' => 500.00,
        ])->assertStatus(201);

        return (int) $res->json('data.id');
    }

    /**
     * [CRITICAL-01] Connected Component Traversal prevents tender cap inflation
     * across branched exchanges (A -> B & C, refund B, then refund C).
     */
    public function test_critical_01_connected_component_tender_caps(): void
    {
        $this->openCashierShift();

        // 1. Original Order A: 2 items of variant1 (Total 200, paid: 120 Cash, 80 Card)
        $saleARes = $this->withToken($this->cashierToken)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant1->id, 'qty' => 2],
            ],
            'payment_method' => 'mixed',
            'cash_amount' => 120.00,
            'card_amount' => 80.00,
        ], ['Idempotency-Key' => 'sale-a-mixed'])->assertStatus(201);
        $orderAId = $saleARes->json('data.id');

        // 2. Exchange 1: Return 1 item (100) from A, exchange into 1 item of variant2 (100) -> Order B
        $exchange1Res = $this->withToken($this->cashierToken)->postJson("/api/v1/pos/sales/{$orderAId}/exchange", [
            'returned_items' => [
                ['product_variant_id' => $this->variant1->id, 'qty' => 1],
            ],
            'exchange_items' => [
                ['product_variant_id' => $this->variant2->id, 'qty' => 1],
            ],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], ['Idempotency-Key' => 'exchange-1-to-b'])->assertStatus(200);
        $orderBId = $exchange1Res->json('data.exchange_order_id');

        // 3. Exchange 2: Return remaining 1 item (100) from A, exchange into 1 item of variant2 (100) -> Order C
        $exchange2Res = $this->withToken($this->cashierToken)->postJson("/api/v1/pos/sales/{$orderAId}/exchange", [
            'returned_items' => [
                ['product_variant_id' => $this->variant1->id, 'qty' => 1],
            ],
            'exchange_items' => [
                ['product_variant_id' => $this->variant2->id, 'qty' => 1],
            ],
            'cash_amount' => 0,
            'card_amount' => 0,
        ], ['Idempotency-Key' => 'exchange-2-to-c'])->assertStatus(200);
        $orderCId = $exchange2Res->json('data.exchange_order_id');

        // 4. Refund Order B: 100 refund.
        // Card cap in chain is 80, so B takes 80 Card + 20 Cash.
        $refundBRes = $this->withToken($this->cashierToken)->postJson("/api/v1/pos/sales/{$orderBId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant2->id, 'qty' => 1],
            ],
        ], ['Idempotency-Key' => 'refund-b-all'])->assertStatus(200);

        $this->assertEquals(80.00, $refundBRes->json('data.card_refund_amount'));
        $this->assertEquals(20.00, $refundBRes->json('data.cash_refund_amount'));

        // 5. Now refund Order C: 100 refund.
        // Total cash paid in chain was 120. Cash refunded so far on B is 20. Remaining cash cap is 100.
        // Total card paid was 80. Card refunded so far on B is 80. Remaining card cap is 0!
        // Therefore, Order C MUST get 100 Cash and 0 Card!
        // Under the old bug, C did not see B's refund, so it thought card refund was 0/80 and refunded card again!
        $refundCRes = $this->withToken($this->cashierToken)->postJson("/api/v1/pos/sales/{$orderCId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant2->id, 'qty' => 1],
            ],
        ], ['Idempotency-Key' => 'refund-c-all'])->assertStatus(200);

        $this->assertEquals(0.00, $refundCRes->json('data.card_refund_amount'), 'Card cap must be exhausted by B');
        $this->assertEquals(100.00, $refundCRes->json('data.cash_refund_amount'), 'Cash refund must exactly cover remaining 100');

        // Total card refunded across chain: 80 (B) + 0 (C) = 80 == total card paid!
        // Total cash refunded across chain: 20 (B) + 100 (C) = 120 == total cash paid!
    }

    /**
     * [CRITICAL-02] Stock counter cannot open shift or sell, but can scan without shift.
     */
    public function test_critical_02_stocktake_counter_permissions_and_shiftless_scan(): void
    {
        // 1. Stock counter cannot open a shift
        $this->withToken($this->counterToken)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer->id,
            'opening_cash' => 200.00,
        ])->assertStatus(403);

        // 2. Stock counter cannot create a sale
        $this->withToken($this->counterToken)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant1->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 100.00,
        ], ['Idempotency-Key' => 'counter-illegal-sale'])->assertStatus(403);

        // 3. Stock counter CAN scan in stocktake WITHOUT opening a shift!
        $stocktake = Stocktake::create([
            'branch_id' => $this->branch->id,
            'status' => StocktakeStatus::InProgress,
            'type' => 'full',
            'started_at' => now(),
            'created_by' => $this->cashier->id,
        ]);

        $this->withToken($this->counterToken)->getJson('/api/v1/pos/stocktakes/current')
            ->assertStatus(200);

        $this->withToken($this->counterToken)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", [
            'barcode' => $this->variant1->barcode,
            'scan_id' => 'scan-test-01',
        ])->assertStatus(200);
    }

    /**
     * [HIGH-02] Consuming held sale leased by another cashier throws 409 conflict.
     */
    public function test_high_02_held_sale_lease_conflict(): void
    {
        $this->openCashierShift();

        $heldSale = PosHeldSale::create([
            'hold_reference' => 'HOLD-TEST-01',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [
                ['product_variant_id' => $this->variant1->id, 'qty' => 1, 'unit_price' => 100],
            ],
            'resumed_by' => $this->stockCounter->id, // Another valid user
            'resumed_at' => now(), // Active 5-minute lease
        ]);

        $this->withToken($this->cashierToken)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant1->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 100.00,
            'held_sale_ids' => [$heldSale->id],
        ], ['Idempotency-Key' => 'sale-consume-leased-held'])
        ->assertStatus(409);
    }

    /**
     * [MEDIUM-01] Suspended customer is ignored in lookup.
     */
    public function test_medium_01_suspended_customer_in_lookup(): void
    {
        $this->openCashierShift();

        $customer = Customer::create([
            'name' => 'Suspended Wholesale',
            'phone' => '+201099998888',
            'status' => 'suspended',
            'is_active' => false,
        ]);

        $res = $this->withToken($this->cashierToken)->getJson("/api/v1/pos/products/lookup?q=VAR-T5-01&customer_id={$customer->id}")
            ->assertStatus(200);

        // Retail price should be returned, not customer-specific pricing
        $this->assertEquals(100.00, (float) $res->json('data.0.unit_price'));
    }

    /**
     * [MEDIUM-06] Idempotency-Key with invalid characters rejected with 422.
     */
    public function test_medium_06_idempotency_key_regex(): void
    {
        $this->openCashierShift();

        $this->withToken($this->cashierToken)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant1->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 100.00,
        ], ['Idempotency-Key' => 'bad key with spaces and special @#$%^'])
        ->assertStatus(422);
    }

    /**
     * [MEDIUM-04] formatShift does not crash when relation is null/soft-deleted.
     */
    public function test_medium_04_format_shift_safe_null_relation(): void
    {
        $shiftId = $this->openCashierShift();
        $shift = PosShift::findOrFail($shiftId);

        // Soft delete the cashier user
        $this->cashier->delete();

        // Loading current shift should still format cleanly without throwing ErrorException
        $res = $this->withToken($this->cashierToken)->getJson('/api/v1/pos/shifts/current')
            ->assertStatus(200);

        $this->assertNull($res->json('data.cashier'));
    }

    /**
     * [MEDIUM-05] POS sales use pos_invoice sequence (POS- prefix).
     */
    public function test_medium_05_pos_invoice_numbering(): void
    {
        $this->openCashierShift();

        $saleRes = $this->withToken($this->cashierToken)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant1->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 100.00,
        ], ['Idempotency-Key' => 'sale-pos-numbering'])->assertStatus(201);

        $orderNumber = $saleRes->json('data.order_number');
        $this->assertStringStartsWith('POS-', $orderNumber);
    }

    /**
     * [LOW-03] POS cashier expense route records drawer expense.
     */
    public function test_low_03_pos_expense_route(): void
    {
        $this->openCashierShift();

        $res = $this->withToken($this->cashierToken)->postJson('/api/v1/pos/sales/expenses', [
            'amount' => 50.00,
            'category' => 'مستلزمات نظافة',
            'notes' => 'شراء مناديل ومطهر للفرع',
        ], ['Idempotency-Key' => 'expense-test-01'])->assertStatus(201);

        $this->assertEquals(50.00, (float) $res->json('data.amount'));
    }
}
