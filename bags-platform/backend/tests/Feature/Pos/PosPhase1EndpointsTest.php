<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\PosShiftStatus;
use App\Enums\ProductStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Treasury;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PosService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosPhase1EndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private Treasury $posDrawer1;

    private Treasury $posDrawer2;

    private Treasury $mainSafe;

    private Category $category;

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

        $this->posDrawer1 = Treasury::create([
            'name' => 'درج 1',
            'code' => 'DRW-01',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $this->posDrawer2 = Treasury::create([
            'name' => 'درج 2',
            'code' => 'DRW-02',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 125.50,
            'is_active' => true,
        ]);

        $this->mainSafe = Treasury::create([
            'name' => 'Cairo Main Safe',
            'code' => 'SAFE-CAIRO',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::MainSafe,
            'current_balance' => 1000,
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name_ar' => 'براند أ',
            'name_en' => 'Brand A',
            'slug' => 'brand-a',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name_ar' => 'شنط يد',
            'name_en' => 'Handbags',
            'slug' => 'hand',
            'is_active' => true,
        ]);

        $prod = Product::create([
            'code' => 'PRD-001',
            'name_ar' => 'شنطة جلد',
            'name_en' => 'Leather Bag',
            'slug' => 'leather-bag',
            'status' => ProductStatus::Published,
            'brand_id' => $brand->id,
            'category_id' => $this->category->id,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'BAG-001-RED',
            'barcode' => '6221234567890',
            'color_name_ar' => 'أحمر',
            'retail_price' => 150.00,
            'cost_price' => 80.00,
            'is_active' => true,
        ]);

        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 50,
            'created_by' => $this->cashier->id,
        ]);

        $this->token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;
    }

    public function test_available_drawers_returns_branch_pos_drawers_with_busy_and_balance(): void
    {
        // Inactive drawer and other branch drawer should be excluded
        Treasury::create([
            'name' => 'درج معطل',
            'code' => 'DRW-INACTIVE',
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => false,
        ]);

        $otherBranch = Branch::create([
            'name' => 'Alex Branch',
            'code' => 'BR-ALEX',
            'type' => 'branch',
            'is_active' => true,
        ]);
        Treasury::create([
            'name' => 'درج إسكندرية',
            'code' => 'DRW-ALEX',
            'branch_id' => $otherBranch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        // Initially both active drawers in Cairo are free
        $res = $this->withToken($this->token)->getJson('/api/v1/pos/treasuries/available');
        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->posDrawer1->id)
            ->assertJsonPath('data.0.name', 'درج 1')
            ->assertJsonPath('data.0.code', 'DRW-01')
            ->assertJsonPath('data.0.busy', false)
            ->assertJsonPath('data.0.balance', '0.00')
            ->assertJsonPath('data.1.id', $this->posDrawer2->id)
            ->assertJsonPath('data.1.busy', false)
            ->assertJsonPath('data.1.balance', '125.50');

        // Open shift on drawer 1 -> busy becomes true
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        $resAfterOpen = $this->withToken($this->token)->getJson('/api/v1/pos/treasuries/available');
        $resAfterOpen->assertOk()
            ->assertJsonPath('data.0.id', $this->posDrawer1->id)
            ->assertJsonPath('data.0.busy', true)
            ->assertJsonPath('data.0.balance', '200.00')
            ->assertJsonPath('data.1.id', $this->posDrawer2->id)
            ->assertJsonPath('data.1.busy', false);

        // Close shift (moves to closed_pending_approval) -> drawer 1 stays busy until approved
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => 200.00,
        ])->assertOk();

        $resAfterClose = $this->withToken($this->token)->getJson('/api/v1/pos/treasuries/available');
        $resAfterClose->assertOk()
            ->assertJsonPath('data.0.id', $this->posDrawer1->id)
            ->assertJsonPath('data.0.busy', true);
    }

    public function test_shift_current_summary_computes_live_totals_and_invoices_count(): void
    {
        // Requires active shift (pos.shift middleware)
        $this->withToken($this->token)
            ->getJson('/api/v1/pos/shifts/current/summary')
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_open_shift');

        // Open shift with 200 opening cash
        $openRes = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        $shiftId = $openRes->json('data.id');

        // Sale 1: 2 items @ 150 = 300 (100 cash + 200 card)
        $sale1 = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
            'payment_method' => 'mixed',
            'cash_amount' => 100.00,
            'card_amount' => 200.00,
        ])->assertCreated();

        // Sale 2: 1 item @ 150 = 150 cash
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 150.00,
            'card_amount' => 0,
        ])->assertCreated();

        // Return 1 item from Sale 1 (cash refund = 100 since sale1 only had 100 cash)
        $sale1Id = $sale1->json('data.id');
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale1Id}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'on_hand'],
            ],
        ])->assertOk();

        // Record petty cash expense of 30
        $shiftModel = PosShift::findOrFail($shiftId);
        app(PosService::class)->recordExpense($shiftModel, [
            'category' => 'ضيافة',
            'amount' => 30.00,
        ], $this->cashier->id);

        // Live summary BEFORE closing shift:
        // opening_cash = 200.00
        // cash_sales_total = 100 + 150 = 250.00
        // card_sales_total = 200.00
        // cash_returns_total = 100.00
        // cash_expenses_total = 30.00
        // expected_closing_cash = 200 + 250 - 100 - 30 = 320.00
        // invoices_count = 2 delivered orders
        $summaryRes = $this->withToken($this->token)->getJson('/api/v1/pos/shifts/current/summary');
        $summaryRes->assertOk()
            ->assertJsonPath('data.id', $shiftId)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_cash', '200.00')
            ->assertJsonPath('data.cash_sales_total', '250.00')
            ->assertJsonPath('data.card_sales_total', '200.00')
            ->assertJsonPath('data.cash_returns_total', '100.00')
            ->assertJsonPath('data.cash_expenses_total', '30.00')
            ->assertJsonPath('data.expected_closing_cash', '320.00')
            ->assertJsonPath('data.invoices_count', 2);
    }

    public function test_bootstrap_endpoint_returns_complete_startup_payload_with_and_without_shift(): void
    {
        // 1. Before opening a shift
        $resBefore = $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap');
        $resBefore->assertOk()
            ->assertJsonPath('data.user.id', $this->cashier->id)
            ->assertJsonPath('data.user.name', 'Cashier Ahmed')
            ->assertJsonPath('data.branch.id', $this->branch->id)
            ->assertJsonPath('data.branch.name', 'Cairo Branch')
            ->assertJsonPath('data.branch.code', 'BR-CAIRO')
            ->assertJsonPath('data.shift', null)
            ->assertJsonCount(2, 'data.drawers')
            ->assertJsonPath('data.categories.0.name', 'شنط يد')
            ->assertJsonPath('data.settings.currency', 'EGP')
            ->assertJsonPath('data.held_count', 0);

        $this->assertNotEmpty($resBefore->json('data.catalog_version'));
        $this->assertIsNumeric($resBefore->json('data.settings.max_discount_percent'));

        // 2. Open shift, make a sale, and park a held sale
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 150.00,
            'card_amount' => 0,
        ])->assertCreated();

        PosHeldSale::create([
            'hold_reference' => 'HOLD-TEST0001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'created_at' => now(),
        ]);

        $resAfter = $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap');
        $resAfter->assertOk()
            ->assertJsonPath('data.shift.status', 'open')
            ->assertJsonPath('data.shift.opening_cash', '200.00')
            ->assertJsonPath('data.shift.cash_sales_total', '150.00')
            ->assertJsonPath('data.shift.expected_closing_cash', '350.00')
            ->assertJsonPath('data.shift.invoices_count', 1)
            ->assertJsonPath('data.drawers.0.busy', true)
            ->assertJsonPath('data.held_count', 1);
    }

    public function test_unassigned_user_is_denied_access_to_bootstrap_and_available_drawers(): void
    {
        $unassigned = User::create([
            'name' => 'No Branch User',
            'email' => 'nobranch@example.com',
            'phone' => '+201055556666',
            'password' => Hash::make('secret123'),
            'branch_id' => null,
            'is_active' => true,
        ]);
        $unassigned->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $token = $unassigned->createToken('pos', ['pos'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/pos/treasuries/available')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/pos/bootstrap')->assertStatus(403);
    }

    public function test_catalog_version_changes_when_variant_or_stock_updates(): void
    {
        $posService = app(PosService::class);
        $v1 = $posService->computeCatalogVersion($this->branch->id);
        $this->assertSame(32, strlen($v1));

        $this->travel(5)->seconds();
        $this->variant->update(['retail_price' => 175.00]);

        $v2 = $posService->computeCatalogVersion($this->branch->id);
        $this->assertNotSame($v1, $v2);

        $this->travel(5)->seconds();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5,
            'created_by' => $this->cashier->id,
        ]);

        $v3 = $posService->computeCatalogVersion($this->branch->id);
        $this->assertNotSame($v2, $v3);
    }

    public function test_phase_0_endpoint_json_contracts_are_consistent(): void
    {
        // 1. GET /api/v1/pos/shifts/current (no open shift)
        $currentNone = $this->withToken($this->token)->getJson('/api/v1/pos/shifts/current');
        $currentNone->assertOk()
            ->assertExactJson(['data' => null, 'has_open_shift' => false]);

        // 2. POST /api/v1/pos/shifts/open
        $openRes = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ]);
        $openRes->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'shift_number',
                    'status',
                    'opened_at',
                    'closed_at',
                    'approved_at',
                    'opening_cash',
                    'cash_sales_total',
                    'card_sales_total',
                    'cash_returns_total',
                    'cash_expenses_total',
                    'expected_closing_cash',
                    'actual_closing_cash',
                    'cash_difference',
                    'notes',
                    'cashier' => ['id', 'name'],
                    'treasury' => ['id', 'name', 'code'],
                    'branch' => ['id', 'name'],
                ],
            ]);

        // 3. GET /api/v1/pos/shifts/current (with open shift)
        $currentOpen = $this->withToken($this->token)->getJson('/api/v1/pos/shifts/current');
        $currentOpen->assertOk()
            ->assertJsonPath('has_open_shift', true)
            ->assertJsonPath('data.id', $openRes->json('data.id'));

        // 4. GET /api/v1/pos/products/lookup?barcode=...
        $lookupRes = $this->withToken($this->token)->getJson('/api/v1/pos/products/lookup?barcode=6221234567890');
        $lookupRes->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'sku',
                        'barcode',
                        'product_name',
                        'variant_name',
                        'on_hand',
                        'available',
                        'unit_price',
                        'price_source',
                        'price_list_id',
                    ],
                ],
            ]);

        // 5. GET /api/v1/pos/bootstrap
        $bootstrapRes = $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap');
        $bootstrapRes->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'version', 'name', 'email', 'phone', 'branch_id', 'branch', 'is_active', 'must_change_password', 'roles', 'permissions'],
                    'branch' => ['id', 'name', 'code'],
                    'shift' => [
                        'id',
                        'shift_number',
                        'status',
                        'opened_at',
                        'opening_cash',
                        'cash_sales_total',
                        'card_sales_total',
                        'cash_returns_total',
                        'cash_expenses_total',
                        'expected_closing_cash',
                        'invoices_count',
                        'cashier',
                        'treasury',
                        'branch',
                    ],
                    'drawers' => [
                        '*' => ['id', 'name', 'code', 'busy', 'balance'],
                    ],
                    'categories' => [
                        '*' => ['id', 'name'],
                    ],
                    'settings' => ['max_discount_percent', 'currency'],
                    'catalog_version',
                    'held_count',
                ],
            ]);
    }

    public function test_sales_quote_matches_create_sale_totals_for_retail_wholesale_and_discounts(): void
    {
        $this->cashier->givePermissionTo(Permission::findOrCreate('pos.override_price', 'staff'));

        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        // 1. Retail quote with line discount
        $quoteRetail = $this->withToken($this->token)->postJson('/api/v1/pos/sales/quote', [
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 2,
                    'discount_amount' => 30.00,
                ],
            ],
        ]);

        $quoteRetail->assertOk()
            ->assertJsonPath('data.lines.0.product_variant_id', $this->variant->id)
            ->assertJsonPath('data.lines.0.qty', 2)
            ->assertJsonPath('data.lines.0.unit_price', '150.00')
            ->assertJsonPath('data.lines.0.discount_amount', '30.00')
            ->assertJsonPath('data.lines.0.line_total', '270.00')
            ->assertJsonPath('data.lines.0.available', 50)
            ->assertJsonPath('data.lines.0.error', null)
            ->assertJsonPath('data.subtotal', '300.00')
            ->assertJsonPath('data.discount_total', '30.00')
            ->assertJsonPath('data.grand_total', '270.00');

        $saleRetail = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 2,
                    'discount_amount' => 30.00,
                ],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 270.00,
            'card_amount' => 0,
        ])->assertCreated();

        $this->assertSame($quoteRetail->json('data.grand_total'), $saleRetail->json('data.grand_total'));

        // 2. Approved wholesale customer quote
        $this->variant->update(['wholesale_price' => 110.00]);
        $wholesaleCustomer = \App\Models\Customer::create([
            'name' => 'Wholesale Merchant',
            'phone' => '01022223333',
            'password' => 'secret123',
            'company_name' => 'Alfarid Wholesale Co.',
            'tax_number' => '123456789',
            'commercial_register' => '654321',
            'type' => \App\Enums\CustomerType::Wholesale,
            'status' => \App\Enums\CustomerStatus::Approved,
        ]);

        $quoteWholesale = $this->withToken($this->token)->postJson('/api/v1/pos/sales/quote', [
            'customer_id' => $wholesaleCustomer->id,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 3,
                    'discount_amount' => 15.00,
                ],
            ],
        ]);

        $quoteWholesale->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', '110.00')
            ->assertJsonPath('data.lines.0.discount_amount', '15.00')
            ->assertJsonPath('data.lines.0.line_total', '315.00')
            ->assertJsonPath('data.lines.0.available', 48)
            ->assertJsonPath('data.lines.0.error', null)
            ->assertJsonPath('data.subtotal', '330.00')
            ->assertJsonPath('data.discount_total', '15.00')
            ->assertJsonPath('data.grand_total', '315.00');

        $saleWholesale = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'customer_id' => $wholesaleCustomer->id,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 3,
                    'discount_amount' => 15.00,
                ],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 315.00,
            'card_amount' => 0,
        ])->assertCreated();

        $this->assertSame($quoteWholesale->json('data.grand_total'), $saleWholesale->json('data.grand_total'));
    }

    public function test_sales_quote_with_insufficient_stock_returns_200_with_line_error_message(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        $res = $this->withToken($this->token)->postJson('/api/v1/pos/sales/quote', [
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 60, // available is 50
                ],
            ],
        ]);

        $res->assertOk()
            ->assertJsonPath('data.lines.0.product_variant_id', $this->variant->id)
            ->assertJsonPath('data.lines.0.qty', 60)
            ->assertJsonPath('data.lines.0.available', 50)
            ->assertJsonPath('data.lines.0.unit_price', '150.00')
            ->assertJsonPath('data.lines.0.line_total', '9000.00');

        $this->assertNotNull($res->json('data.lines.0.error'));
        $this->assertStringContainsString('50', (string) $res->json('data.lines.0.error'));
    }

    public function test_sales_lookup_for_return_supports_arabic_digits_and_prefixless_numbers_and_enforces_branch(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        // Sell 2 items @ 150 with total discount 100 -> net unit price = (300 - 100) / 2 = 100.00
        $saleRes = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 2,
                    'discount_amount' => 100.00,
                ],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 200.00,
            'card_amount' => 0,
        ])->assertCreated();

        $saleId = $saleRes->json('data.id');
        $orderNumber = (string) $saleRes->json('data.order_number'); // e.g. ORD-001000

        // Return 1 item first so returned_qty = 1 and returnable_qty = 1
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'on_hand'],
            ],
        ])->assertOk();

        // Extract digits and convert to Arabic-Indic numerals
        $digitsOnly = preg_replace('/\D+/', '', $orderNumber);
        $arabicDigits = strtr($digitsOnly, array_flip(\App\Support\ArabicDigits::MAP));

        // 1. Lookup with Arabic digits without prefix
        $lookup1 = $this->withToken($this->token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode($arabicDigits));
        $lookup1->assertOk()
            ->assertJsonPath('data.id', $saleId)
            ->assertJsonPath('data.order_number', $orderNumber)
            ->assertJsonPath('data.grand_total', '200.00')
            ->assertJsonPath('data.items.0.product_variant_id', $this->variant->id)
            ->assertJsonPath('data.items.0.sku', 'BAG-001-RED')
            ->assertJsonPath('data.items.0.qty', 2)
            ->assertJsonPath('data.items.0.returned_qty', 1)
            ->assertJsonPath('data.items.0.returnable_qty', 1)
            ->assertJsonPath('data.items.0.unit_price', '150.00')
            ->assertJsonPath('data.items.0.discount_amount', '100.00')
            ->assertJsonPath('data.items.0.net_unit_price', '100.00');

        // 2. Lookup with POS- prefix or full order number
        $lookup2 = $this->withToken($this->token)->getJson('/api/v1/pos/sales/lookup?number='.urlencode('POS-'.$digitsOnly));
        $lookup2->assertOk()
            ->assertJsonPath('data.id', $saleId);

        // 3. Cashier from another branch gets 403
        $otherBranch = Branch::create([
            'name' => 'Alex Branch',
            'code' => 'BR-ALEX',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $otherDrawer = Treasury::create([
            'name' => 'Alex Drawer',
            'code' => 'DRW-ALEX-1',
            'branch_id' => $otherBranch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $otherCashier = User::create([
            'name' => 'Cashier Alex',
            'email' => 'alex@example.com',
            'phone' => '+201099998888',
            'password' => Hash::make('secret123'),
            'branch_id' => $otherBranch->id,
            'is_active' => true,
        ]);
        foreach (['pos.access', 'pos.sales.refund'] as $perm) {
            $otherCashier->givePermissionTo(Permission::findOrCreate($perm, 'staff'));
        }
        $otherToken = $otherCashier->createToken('pos', ['pos'])->plainTextToken;
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $otherDrawer->id,
            'opening_cash' => 0,
        ])->assertCreated();

        $this->withToken($otherToken)
            ->getJson('/api/v1/pos/sales/lookup?number='.urlencode($orderNumber))
            ->assertStatus(403);
    }

    public function test_held_sale_resume_and_delete_are_atomic_and_branch_scoped(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        // Hold sale 1
        $hold1 = $this->withToken($this->token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
            'notes' => 'Hold 1',
        ])->assertCreated();
        $heldId1 = $hold1->json('data.id');

        // Hold sale 2
        $hold2 = $this->withToken($this->token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
            'notes' => 'Hold 2',
        ])->assertCreated();
        $heldId2 = $hold2->json('data.id');

        // Other branch cashier setup
        $otherBranch = Branch::create([
            'name' => 'Alex Branch',
            'code' => 'BR-ALEX',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $otherDrawer = Treasury::create([
            'name' => 'Alex Drawer',
            'code' => 'DRW-ALEX-1',
            'branch_id' => $otherBranch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $otherCashier = User::create([
            'name' => 'Cashier Alex',
            'email' => 'alex@example.com',
            'phone' => '+201099998888',
            'password' => Hash::make('secret123'),
            'branch_id' => $otherBranch->id,
            'is_active' => true,
        ]);
        $otherCashier->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $otherToken = $otherCashier->createToken('pos', ['pos'])->plainTextToken;
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $otherDrawer->id,
            'opening_cash' => 0,
        ])->assertCreated();

        // Cross-branch resume and delete return 403
        $this->withToken($otherToken)->postJson("/api/v1/pos/sales/held/{$heldId1}/resume")->assertStatus(403);
        $this->withToken($otherToken)->deleteJson("/api/v1/pos/sales/held/{$heldId2}")->assertStatus(403);

        // Switch back to main cashier
        \Illuminate\Support\Facades\Auth::forgetGuards();

        // First resume succeeds and returns items_payload
        $resumeRes = $this->withToken($this->token)->postJson("/api/v1/pos/sales/held/{$heldId1}/resume");
        $resumeRes->assertOk()
            ->assertJsonPath('data.id', $heldId1)
            ->assertJsonPath('data.notes', 'Hold 1')
            ->assertJsonPath('data.items_payload.0.product_variant_id', $this->variant->id)
            ->assertJsonPath('data.items_payload.0.qty', 2);

        // Second resume to the same ID returns 404
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/held/{$heldId1}/resume")->assertStatus(404);

        // Delete held sale 2 succeeds, then subsequent delete returns 404
        $this->withToken($this->token)->deleteJson("/api/v1/pos/sales/held/{$heldId2}")->assertSuccessful();
        $this->withToken($this->token)->deleteJson("/api/v1/pos/sales/held/{$heldId2}")->assertStatus(404);
    }

    public function test_catalog_returns_active_variants_with_branch_stock_and_wholesale_prices_and_etag_304(): void
    {
        $this->variant->update([
            'wholesale_price' => 120.00,
            'reorder_level' => 10,
        ]);

        // Add branch price list item overriding retail to 140.00
        $retailList = \App\Models\PriceList::create([
            'name' => 'Cairo Promo',
            'type' => \App\Enums\PriceListType::Retail,
            'branch_id' => $this->branch->id,
            'priority' => 10,
            'is_active' => true,
        ]);
        \App\Models\PriceListItem::create([
            'price_list_id' => $retailList->id,
            'product_variant_id' => $this->variant->id,
            'min_qty' => 1,
            'price' => 140.00,
        ]);

        // Add inactive variant (should be excluded)
        ProductVariant::create([
            'product_id' => $this->variant->product_id,
            'sku' => 'BAG-001-INACTIVE',
            'barcode' => '6221234567999',
            'color_name_ar' => 'أسود',
            'retail_price' => 150.00,
            'cost_price' => 80.00,
            'is_active' => false,
        ]);

        $expectedVersion = app(PosService::class)->computeCatalogVersion($this->branch->id);

        // 1. Initial GET catalog (callable before opening shift)
        $res = $this->withToken($this->token)->getJson('/api/v1/pos/catalog');
        $res->assertOk()
            ->assertHeader('ETag', "\"{$expectedVersion}\"")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->variant->id)
            ->assertJsonPath('data.0.sku', 'BAG-001-RED')
            ->assertJsonPath('data.0.barcode', '6221234567890')
            ->assertJsonPath('data.0.name', 'شنطة جلد - أحمر')
            ->assertJsonPath('data.0.product_name', 'شنطة جلد')
            ->assertJsonPath('data.0.variant_name', 'أحمر')
            ->assertJsonPath('data.0.category_id', (string) $this->category->id)
            ->assertJsonPath('data.0.unit_price', 140)
            ->assertJsonPath('data.0.wholesale_price', 120)
            ->assertJsonPath('data.0.available', 50)
            ->assertJsonPath('data.0.on_hand', 50)
            ->assertJsonPath('data.0.reorder_point', 10)
            ->assertJsonPath('meta.catalog_version', $expectedVersion)
            ->assertJsonPath('meta.next_cursor', null);

        // 2. Request with matching If-None-Match (quoted) returns 304 with empty body
        $res304Quoted = $this->withToken($this->token)
            ->withHeaders(['If-None-Match' => "\"{$expectedVersion}\""])
            ->get('/api/v1/pos/catalog');
        $res304Quoted->assertStatus(304)
            ->assertHeader('ETag', "\"{$expectedVersion}\"");
        $this->assertSame('', $res304Quoted->getContent());

        // 3. Request with matching If-None-Match (unquoted) returns 304 with empty body
        $res304Unquoted = $this->withToken($this->token)
            ->withHeaders(['If-None-Match' => $expectedVersion])
            ->get('/api/v1/pos/catalog');
        $res304Unquoted->assertStatus(304)
            ->assertHeader('ETag', "\"{$expectedVersion}\"");
        $this->assertSame('', $res304Unquoted->getContent());
    }

    public function test_customers_index_and_store_search_by_arabic_digits_and_create_retail_or_wholesale(): void
    {
        \App\Models\Customer::create([
            'name' => 'مها فتحي',
            'phone' => '01012345678',
            'password' => 'secret123456',
            'company_name' => 'شركة مها',
            'tax_number' => '111222333',
            'commercial_register' => '444555',
            'type' => \App\Enums\CustomerType::Wholesale,
            'status' => \App\Enums\CustomerStatus::Approved,
        ]);

        \App\Models\Customer::create([
            'name' => 'كريم عبد الله',
            'phone' => '01155550912',
            'password' => 'secret123456',
            'type' => \App\Enums\CustomerType::Retail,
            'status' => \App\Enums\CustomerStatus::Approved,
        ]);

        // Suspended customer should be excluded
        \App\Models\Customer::create([
            'name' => 'عميل موقوف',
            'phone' => '01299998888',
            'password' => 'secret123456',
            'type' => \App\Enums\CustomerType::Retail,
            'status' => \App\Enums\CustomerStatus::Suspended,
        ]);

        // 1. Search by Arabic-Indic phone digits (callable before shift open)
        $resPhone = $this->withToken($this->token)->getJson('/api/v1/pos/customers?q='.urlencode('٠١٠١٢٣٤'));
        $resPhone->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مها فتحي')
            ->assertJsonPath('data.0.phone', '01012345678')
            ->assertJsonPath('data.0.type', 'wholesale')
            ->assertJsonPath('data.0.loyalty_points', 0)
            ->assertJsonPath('data.0.points', 0);

        // 2. Search by name
        $resName = $this->withToken($this->token)->getJson('/api/v1/pos/customers?q='.urlencode('كريم'));
        $resName->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'كريم عبد الله')
            ->assertJsonPath('data.0.type', 'retail');

        // 2b. Max 10 active customers returned when more than 10 exist
        for ($i = 1; $i <= 12; $i++) {
            \App\Models\Customer::create([
                'name' => "عميل تجريبي {$i}",
                'phone' => '0151111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'password' => 'secret123456',
                'type' => \App\Enums\CustomerType::Retail,
                'status' => \App\Enums\CustomerStatus::Approved,
            ]);
        }
        $resMax10 = $this->withToken($this->token)->getJson('/api/v1/pos/customers');
        $resMax10->assertOk()->assertJsonCount(10, 'data');

        // 3. POST customers requires active shift
        $this->withToken($this->token)->postJson('/api/v1/pos/customers', [
            'name' => 'أحمد علي',
            'phone' => '01066667777',
            'type' => 'retail',
        ])->assertStatus(403);

        // Open shift
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        // 4. Create retail customer (with Arabic digits in phone)
        $createRetail = $this->withToken($this->token)->postJson('/api/v1/pos/customers', [
            'name' => 'أحمد علي',
            'phone' => '٠١٠٦٦٦٦٧٧٧٧',
            'type' => 'retail',
        ]);
        $createRetail->assertStatus(201)
            ->assertJsonPath('data.name', 'أحمد علي')
            ->assertJsonPath('data.phone', '01066667777')
            ->assertJsonPath('data.type', 'retail')
            ->assertJsonPath('data.loyalty_points', 0);

        // 5. Create wholesale customer
        $createWholesale = $this->withToken($this->token)->postJson('/api/v1/pos/customers', [
            'name' => 'تاجر جملة جديد',
            'phone' => '01277778888',
            'type' => 'wholesale',
        ]);
        $createWholesale->assertStatus(201)
            ->assertJsonPath('data.name', 'تاجر جملة جديد')
            ->assertJsonPath('data.phone', '01277778888')
            ->assertJsonPath('data.type', 'wholesale');

        // 6. Duplicate phone returns 422
        $this->withToken($this->token)->postJson('/api/v1/pos/customers', [
            'name' => 'مكرر',
            'phone' => '+201066667777',
            'type' => 'retail',
        ])->assertStatus(422);
    }

    public function test_inventory_endpoints_incoming_transfers_sources_and_blind_stocktake(): void
    {
        $warehouse = Branch::create([
            'name' => 'المخزن الرئيسي',
            'code' => 'WH-MAIN',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $otherStore = Branch::create([
            'name' => 'فرع الإسكندرية',
            'code' => 'BR-ALEX',
            'type' => 'branch',
            'is_active' => true,
        ]);

        // Inactive branch should be excluded from sources
        Branch::create([
            'name' => 'فرع مغلق',
            'code' => 'BR-CLOSED',
            'type' => 'branch',
            'is_active' => false,
        ]);

        // Open shift for cashier
        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer1->id,
            'opening_cash' => 200.00,
        ])->assertCreated();

        // 1. GET /api/v1/pos/transfer-sources (excludes own branch and inactive branches)
        $sourcesRes = $this->withToken($this->token)->getJson('/api/v1/pos/transfer-sources');
        $sourcesRes->assertOk()
            ->assertJsonCount(2, 'data');

        $sourceIds = array_column($sourcesRes->json('data'), 'id');
        $this->assertContains($warehouse->id, $sourceIds);
        $this->assertContains($otherStore->id, $sourceIds);
        $this->assertNotContains($this->branch->id, $sourceIds);

        // 2. GET /api/v1/pos/transfers/incoming (only shipped transfers to this branch)
        $shippedTransfer = \App\Models\StockTransfer::create([
            'transfer_number' => 'TRF-001001',
            'from_branch_id' => $warehouse->id,
            'to_branch_id' => $this->branch->id,
            'status' => \App\Enums\TransferStatus::Shipped,
            'created_by' => $this->cashier->id,
            'approved_by' => $this->cashier->id,
            'shipped_at' => now(),
            'notes' => 'شحنة الصباح',
        ]);
        $transferItem = \App\Models\StockTransferItem::create([
            'stock_transfer_id' => $shippedTransfer->id,
            'product_variant_id' => $this->variant->id,
            'requested_qty' => 10,
            'approved_qty' => 10,
            'shipped_qty' => 8,
        ]);

        // Draft transfer to this branch should NOT appear in incoming
        \App\Models\StockTransfer::create([
            'transfer_number' => 'TRF-001002',
            'from_branch_id' => $warehouse->id,
            'to_branch_id' => $this->branch->id,
            'status' => \App\Enums\TransferStatus::Draft,
            'created_by' => $this->cashier->id,
        ]);

        $incomingRes = $this->withToken($this->token)->getJson('/api/v1/pos/transfers/incoming');
        $incomingRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shippedTransfer->id)
            ->assertJsonPath('data.0.transfer_number', 'TRF-001001')
            ->assertJsonPath('data.0.from_branch.id', $warehouse->id)
            ->assertJsonPath('data.0.from_branch.name', 'المخزن الرئيسي')
            ->assertJsonPath('data.0.items.0.id', $transferItem->id)
            ->assertJsonPath('data.0.items.0.product_variant_id', $this->variant->id)
            ->assertJsonPath('data.0.items.0.sku', 'BAG-001-RED');

        $this->assertEquals(8, (float) $incomingRes->json('data.0.items.0.shipped_qty'));
        $this->assertEquals(10, (float) $incomingRes->json('data.0.items.0.requested_qty'));

        // 3. GET /api/v1/pos/stocktakes/current when none exists -> { data: null }
        $this->withToken($this->token)
            ->getJson('/api/v1/pos/stocktakes/current')
            ->assertOk()
            ->assertExactJson(['data' => null]);

        // Create active stocktake
        $stocktake = \App\Models\Stocktake::create([
            'stocktake_number' => 'STK-001001',
            'branch_id' => $this->branch->id,
            'status' => \App\Enums\StocktakeStatus::InProgress,
            'created_by' => $this->cashier->id,
            'notes' => 'جرد مسائي',
        ]);
        \App\Models\StocktakeItem::create([
            'stocktake_id' => $stocktake->id,
            'product_variant_id' => $this->variant->id,
            'system_qty' => 50,
            'counted_qty' => 48,
        ]);

        // Regular cashier (without stocktakes.approve) MUST NOT see system_qty, difference, or variance_qty
        $stCashierRes = $this->withToken($this->token)->getJson('/api/v1/pos/stocktakes/current');
        $stCashierRes->assertOk()
            ->assertJsonPath('data.id', $stocktake->id)
            ->assertJsonPath('data.stocktake_number', 'STK-001001')
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.items.0.product_variant_id', $this->variant->id);

        $this->assertEquals(48, (float) $stCashierRes->json('data.items.0.counted_qty'));
        $itemArray = $stCashierRes->json('data.items.0');
        $this->assertArrayNotHasKey('system_qty', $itemArray);
        $this->assertArrayNotHasKey('difference', $itemArray);
        $this->assertArrayNotHasKey('variance_qty', $itemArray);

        // Grant stocktakes.approve -> system_qty becomes visible
        $this->cashier->givePermissionTo(Permission::findOrCreate('stocktakes.approve', 'staff'));
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $stApproverRes = $this->withToken($this->token)->getJson('/api/v1/pos/stocktakes/current');
        $stApproverRes->assertOk();
        $approverItem = $stApproverRes->json('data.items.0');
        $this->assertArrayHasKey('system_qty', $approverItem);
        $this->assertEquals(50, (float) $approverItem['system_qty']);
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if (strtoupper($method) === 'POST' && str_ends_with($uri, '/pos/sales') && ! isset($headers['Idempotency-Key']) && ! isset($this->defaultHeaders['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
