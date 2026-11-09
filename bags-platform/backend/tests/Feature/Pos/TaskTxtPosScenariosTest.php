<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Pos\PosService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Using test-driven-development to cover task.txt critical POS scenarios.
 *
 * Maps to task.txt sections:
 * - test 1: idempotency replay keeps items + rejects different payload/missing key
 * - test 3: payment split must equal grand total down to the cent
 * - test 4: server enforces settings pos.max_discount_percent (not only config)
 * - test 10: voided expenses excluded from shift totals
 * - test 20: catalog version changes when price-list/products change
 */
class TaskTxtPosScenariosTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private Treasury $drawer;

    private ProductVariant $variant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Cairo Branch',
            'code' => 'BR-CAIRO',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier Ahmed',
            'email' => 'cashier@example.com',
            'phone' => '+201011112222',
            'password' => Hash::make('secret123'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $role = Role::findOrCreate('cashier', 'staff');
        $this->cashier->assignRole($role);
        foreach (['pos.access', 'pos.sales.refund', 'pos.discount'] as $permission) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        $this->drawer = Treasury::create([
            'name' => 'Ahmed Drawer',
            'code' => 'DRW-01',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        Treasury::create([
            'name' => 'Cairo Main Safe',
            'code' => 'SAFE-CAIRO',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::MainSafe,
            'current_balance' => 1000,
            'is_active' => true,
        ]);

        $brand = Brand::create(['name_ar' => 'براند أ', 'name_en' => 'Brand A', 'slug' => 'brand-a-'.Str::random(4), 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'bags-'.Str::random(4), 'is_active' => true]);
        $prod = Product::create([
            'code' => 'PRD-'.Str::random(6),
            'name_ar' => 'شنطة جلد',
            'name_en' => 'Leather Bag',
            'slug' => 'leather-bag-'.Str::random(6),
            'status' => ProductStatus::Published,
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'BAG-'.Str::random(6),
            'barcode' => '622'.random_int(1000000000, 1999999999),
            'color_name_ar' => 'أحمر',
            'retail_price' => 100.00,
            'cost_price' => 60.00,
            'is_active' => true,
        ]);

        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5,
            'created_by' => $this->cashier->id,
        ]);

        $this->token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;

        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->drawer->id,
            'opening_cash' => 0,
        ])->assertStatus(201);
    }

    private function salePayload(float $cash, float $card, int $qty = 1, float $discount = 0.0): array
    {
        return [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => $qty, 'discount_amount' => $discount],
            ],
            'payment_method' => 'mixed',
            'cash_amount' => $cash,
            'card_amount' => $card,
        ];
    }

    public function test_01_idempotent_replay_returns_same_order_with_items(): void
    {
        $key = 'idem-'.Str::random(16);

        $first = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0), ['Idempotency-Key' => $key]);
        $first->assertStatus(201);
        $orderNumber = $first->json('data.order_number');
        $this->assertNotEmpty($orderNumber);

        $replay = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0), ['Idempotency-Key' => $key]);
        $replay->assertStatus(201);
        $this->assertSame($orderNumber, $replay->json('data.order_number'));

        // Replay must include the sold items (find() without relations drops them today).
        $this->assertNotEmpty($replay->json('data.items'), 'replayed sale must include items');
        $this->assertSame($this->variant->id, $replay->json('data.items.0.product_variant_id'));

        $this->assertSame(1, Order::query()->where('order_number', $orderNumber)->count());
        $this->assertSame(4.0, (float) $this->variantStock());

        // Same key with different payload must be rejected.
        $conflict = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(50, 50), ['Idempotency-Key' => $key]);
        $conflict->assertStatus(409);
        $this->assertStringContainsString('مفتاح عدم التكرار', $conflict->json('message'));

        // Missing key must be rejected.
        $missing = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0));
        $missing->assertStatus(422);
        $this->assertStringContainsString('Idempotency-Key', $missing->json('message'));

        // Invalid key must be rejected.
        $invalid = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0), ['Idempotency-Key' => str_repeat('x', 129)]);
        $invalid->assertStatus(422);
        $this->assertStringContainsString('Idempotency-Key', $invalid->json('message'));
    }

    public function test_03_payment_split_must_match_grand_total_to_the_cent(): void
    {
        // Grand total is 100.00.
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0), $this->idemHeaders())->assertStatus(201);
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(0, 100), $this->idemHeaders())->assertStatus(201);
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(40, 60), $this->idemHeaders())->assertStatus(201);

        // One cent short.
        $short = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(40, 59.99), $this->idemHeaders());
        $short->assertStatus(422);
        $this->assertStringContainsString('لا يساوي إجمالي الفاتورة', $short->json('message'));

        // Sub-penny fraction is rejected (only 2 decimals allowed).
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 100.001,
            'card_amount' => 0,
        ], $this->idemHeaders())->assertStatus(422);

        // Negative cash rejected.
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(-1, 101), $this->idemHeaders())->assertStatus(422);

        // Treasury records cash only: find the last successful mixed sale (40/60).
        $order = Order::query()->where('cash_amount', 40)->where('card_amount', 60)->latest('id')->firstOrFail();
        $this->assertDatabaseHas('treasury_transactions', [
            'treasury_id' => $this->drawer->id,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'amount' => 40,
        ]);

        // Missing cash_amount defaults to full cash (grand total).
        $defaulted = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash',
        ], $this->idemHeaders());
        $defaulted->assertStatus(201);
        $this->assertSame('100.00', number_format((float) $defaulted->json('data.cash_amount'), 2, '.', ''));
    }

    public function test_04_settings_discount_cap_is_enforced(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pos.max_discount_percent'],
            ['group' => 'pos', 'key' => 'pos.max_discount_percent', 'value' => '10', 'type' => 'number'],
        );
        app(\App\Services\Shared\SettingService::class)->clearCache();
        config(['inventory.pos_max_discount_percent' => 100]);

        // 50% discount on a 100 unit must be rejected when settings cap is 10%.
        $quote = $this->withToken($this->token)->postJson('/api/v1/pos/sales/quote', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'discount_amount' => 50]],
        ]);
        $quote->assertStatus(422);
        $this->assertStringContainsString('الخصم', $quote->json('message'));

        $store = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'discount_amount' => 50]],
            'payment_method' => 'cash',
            'cash_amount' => 50,
            'card_amount' => 0,
        ], $this->idemHeaders());
        $store->assertStatus(422);

        // Without discount permission quote is 403 (separate cashier + drawer so the shift is open).
        $drawer2 = Treasury::create([
            'name' => 'Drawer 2',
            'code' => 'DRW-02',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $noDiscount = User::create([
            'name' => 'No Discount',
            'email' => 'nodiscount@example.com',
            'phone' => '+201022233344',
            'password' => Hash::make('secret123'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $noDiscount->assignRole(Role::findOrCreate('cashier', 'staff'));
        $noDiscount->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $noDiscountToken = $noDiscount->createToken('pos', ['pos'])->plainTextToken;
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($noDiscountToken)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $drawer2->id,
            'opening_cash' => 0,
        ])->assertStatus(201);
        $this->withToken($noDiscountToken)->postJson('/api/v1/pos/sales/quote', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'discount_amount' => 5]],
        ])->assertStatus(403);
        $this->postJson('/api/v1/pos/sales', $this->salePayload(95, 0, discount: 5), $this->idemHeaders())
            ->assertForbidden();

        $override = [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 80]],
            'override_price' => true,
        ];
        $payment = ['payment_method' => 'cash', 'cash_amount' => 80];
        $this->postJson('/api/v1/pos/sales/quote', $override)->assertForbidden();
        $this->postJson('/api/v1/pos/sales', $override + $payment, $this->idemHeaders())->assertForbidden();
        $this->assertDatabaseCount('orders', 0);

        $noDiscount->givePermissionTo(Permission::findOrCreate('pos.override_price', 'staff'));
        \Illuminate\Support\Facades\Auth::forgetGuards();
        app()->forgetScopedInstances();
        $this->postJson('/api/v1/pos/sales/quote', $override)->assertOk();
        $sale = $this->postJson('/api/v1/pos/sales', $override + $payment, $this->idemHeaders())->assertCreated();
        $this->assertDatabaseHas('orders', ['id' => $sale->json('data.id'), 'grand_total' => 80]);
    }

    public function test_10_voided_expense_excluded_from_shift_totals(): void
    {
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShiftStatus::Open)->firstOrFail();

        // One cash sale of 100.
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $this->salePayload(100, 0), $this->idemHeaders())->assertStatus(201);

        $expense = app(PosService::class)->recordExpense($shift->fresh(), [
            'category' => 'ضيافة',
            'amount' => 20,
        ], $this->cashier->id);

        $expectedWithExpense = 0 + 100 - 0 - 20;
        $this->assertSame($expectedWithExpense, (int) app(PosService::class)->computeShiftTotals($shift->fresh())['expected_closing_cash']);

        // Void the expense (the void endpoint itself is covered by ExpenseVoidTest).
        $expense->update(['voided_at' => now(), 'voided_by' => $this->cashier->id, 'void_reason' => 'خطأ إدخال']);

        $this->assertNotNull(Expense::findOrFail($expense->id)->voided_at);

        $totals = app(PosService::class)->computeShiftTotals($shift->fresh());
        $this->assertSame(0.0, (float) $totals['cash_expenses_total'], 'voided expense must not reduce expected cash');
        $this->assertSame(100.0, (float) $totals['expected_closing_cash']);
    }

    public function test_20_catalog_version_changes_with_price_list_and_product_status(): void
    {
        $this->freezeSecond();
        $product = $this->variant->product()->firstOrFail();
        $product->touch();
        $priceList = PriceList::create([
            'branch_id' => null,
            'name' => 'List A',
            'type' => \App\Enums\PriceListType::Retail,
            'is_active' => true,
        ]);
        $item = PriceListItem::create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $this->variant->id,
            'price' => 90,
        ]);
        StockLevel::query()->where('product_variant_id', $this->variant->id)->update(['updated_at' => now()]);

        $url = '/api/v1/pos/catalog';
        $catalog = $this->withToken($this->token)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.unit_price', 90);
        $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertNoContent(304);

        $this->postJson('/api/v1/pos/sales', $this->salePayload(90, 0), $this->idemHeaders())->assertCreated();
        $catalog = $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.0.available', 4);

        $item->update(['price' => 85]);
        $catalog = $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.0.unit_price', 85);

        $priceList->update(['is_active' => false]);
        $catalog = $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.0.unit_price', 100);

        $priceList->update(['is_active' => true, 'ends_at' => now()->addSecond()]);
        $catalog = $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.0.unit_price', 85);

        $product->update(['status' => ProductStatus::Archived]);
        $catalog = $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonCount(0, 'data');
        $product->update(['status' => ProductStatus::Published]);
        $catalog = $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.0.unit_price', 85);

        $this->travel(2)->seconds();
        $this->getJson($url, ['If-None-Match' => $catalog->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.0.unit_price', 100);
    }

    /** @return array<string, string> */
    private function idemHeaders(): array
    {
        return ['Idempotency-Key' => 'k-'.Str::random(16)];
    }

    private function variantStock(): float
    {
        return (float) \App\Models\StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)
            ->sum('on_hand');
    }
}
