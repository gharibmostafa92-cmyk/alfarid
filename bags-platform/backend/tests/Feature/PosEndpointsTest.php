<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PosShiftStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PosService;
use App\Services\TreasuryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private Treasury $posDrawer;
    private Treasury $mainSafe;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\SettingSeeder::class);

        $this->branch = Branch::create([
            'name'      => 'Cairo Branch',
            'code'      => 'BR-CAIRO',
            'type'      => 'branch',
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name'      => 'Cashier Ahmed',
            'email'     => 'cashier@example.com',
            'phone'     => '+201011112222',
            'password'  => Hash::make('secret123'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->posDrawer = Treasury::create([
            'name'            => 'Ahmed Drawer',
            'code'            => 'DRW-01',
            'branch_id'       => $this->branch->id,
            'type'            => TreasuryType::PosDrawer,
            'current_balance' => 0,
            'is_active'       => true,
        ]);

        $this->mainSafe = Treasury::create([
            'name'            => 'Cairo Main Safe',
            'code'            => 'SAFE-CAIRO',
            'branch_id'       => $this->branch->id,
            'type'            => TreasuryType::MainSafe,
            'current_balance' => 1000,
            'is_active'       => true,
        ]);

        $brand = Brand::create(['name_ar' => 'براند أ', 'name_en' => 'Brand A', 'slug' => 'brand-a', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'bags', 'is_active' => true]);
        $prod = Product::create([
            'code'         => 'PRD-001',
            'name_ar'      => 'شنطة جلد',
            'name_en'      => 'Leather Bag',
            'slug'         => 'leather-bag',
            'status'       => \App\Enums\ProductStatus::Published,
            'brand_id'     => $brand->id,
            'category_id'  => $cat->id,
            'is_active'    => true,
            'published_at' => now(),
        ]);

        $this->variant = ProductVariant::create([
            'product_id'    => $prod->id,
            'sku'           => 'BAG-001-RED',
            'barcode'       => '6221234567890',
            'color_name_ar' => 'أحمر',
            'retail_price'  => 150.00,
            'cost_price'    => 80.00,
            'is_active'     => true,
        ]);

        // ضخ رصيد مخزون أولي في الفرع
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id'          => $this->branch->id,
            'type'               => StockMovementType::AdjustmentIn,
            'affected_bucket'    => StockBucket::OnHand,
            'quantity'           => 50,
            'created_by'         => $this->cashier->id,
        ]);
    }

    public function test_pos_login_requires_matching_branch(): void
    {
        // فرع غير مطابق
        $otherBranch = Branch::create([
            'name'      => 'Alex Branch',
            'code'      => 'BR-ALEX',
            'type'      => 'branch',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/v1/pos/auth/login', [
            'email'     => 'cashier@example.com',
            'password'  => 'secret123',
            'branch_id' => $otherBranch->id,
        ]);

        $res->assertStatus(401);

        // فرع صحيح
        $resValid = $this->postJson('/api/v1/pos/auth/login', [
            'email'     => 'cashier@example.com',
            'password'  => 'secret123',
            'branch_id' => $this->branch->id,
        ]);

        $resValid->assertOk()->assertJsonStructure(['data' => ['id', 'name'], 'token']);
    }

    public function test_pos_sale_requires_active_open_shift(): void
    {
        $token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;

        // محاولة بيع بدون فتح وردية
        $saleRes = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
            'cash_amount' => 150.00,
        ]);

        $saleRes->assertStatus(403)
            ->assertJsonPath('code', 'no_open_shift');
    }

    public function test_shift_lifecycle_and_direct_pos_sale_with_immediate_inventory_deduction(): void
    {
        $token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;

        // 1. فتح الوردية
        $openRes = $this->withToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id'  => $this->posDrawer->id,
            'opening_cash' => 200.00,
        ]);

        $openRes->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_cash', '200.00');

        $this->assertEquals(200.00, (float) $this->posDrawer->fresh()->current_balance);

        // 2. البحث بالباركود
        $lookupRes = $this->withToken($token)->getJson('/api/v1/pos/products/lookup?barcode=6221234567890');
        $lookupRes->assertOk()
            ->assertJsonPath('data.0.sku', 'BAG-001-RED')
            ->assertJsonPath('data.0.on_hand', 50)
            ->assertJsonPath('data.0.unit_price', 150);

        // 3. بيع فوري مباشر (2 حبة = 300) بدفع مختلط (100 كاش + 200 بطاقة)
        $saleRes = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
            'payment_method' => 'mixed',
            'cash_amount'    => 100.00,
            'card_amount'    => 200.00,
        ]);

        $saleRes->assertStatus(201)
            ->assertJsonPath('data.channel', 'pos')
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.grand_total', '300.00')
            ->assertJsonPath('data.cash_amount', '100.00')
            ->assertJsonPath('data.card_amount', '200.00');

        // التحقق من خصم المخزون الفوري من on_hand
        $stock = StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')
            ->first();

        $this->assertEquals(48.00, (float) $stock->on_hand);
        $this->assertEquals(48.00, (float) $stock->available);

        // التحقق من زيادة رصيد الدرج بالكاش فقط (200 فتح + 100 كاش = 300)
        $this->assertEquals(300.00, (float) $this->posDrawer->fresh()->current_balance);

        // 4. إغلاق الوردية
        // expected_closing_cash = 200 فتح + 100 كاش = 300
        // إذا الكاشير عَدّ 320 -> يوجد زيادة +20
        $closeRes = $this->withToken($token)->postJson('/api/v1/pos/shifts/close', [
            'actual_closing_cash' => 320.00,
            'notes'               => 'جرد سليم مع زيادة 20',
        ]);

        $closeRes->assertOk()
            ->assertJsonPath('data.status', 'closed_pending_approval')
            ->assertJsonPath('data.expected_closing_cash', '300.00')
            ->assertJsonPath('data.actual_closing_cash', '320.00')
            ->assertJsonPath('data.cash_difference', '20.00');

        $admin = User::create([
            'name'      => 'Finance Manager',
            'email'     => 'admin@example.com',
            'password'  => Hash::make('secret123'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $permission = Permission::findOrCreate('pos_shifts.approve', 'staff');
        $admin->givePermissionTo($permission);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        \Illuminate\Support\Facades\Auth::forgetGuards();

        $shiftId = $closeRes->json('data.id');
        $approveRes = $this->withToken($adminToken)->postJson("/api/v1/admin/shifts/{$shiftId}/approve");

        // تم ترحيل رصيد الدرج المتاح 300 إلى الخزنة الرئيسية
        $this->assertEquals(1300.00, (float) $this->mainSafe->fresh()->current_balance);
        $this->assertEquals(0.00, (float) $this->posDrawer->fresh()->current_balance);
    }

    public function test_pos_held_sales_and_returns(): void
    {
        $token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;

        // فتح وردية جديدة
        $this->withToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id'  => $this->posDrawer->id,
            'opening_cash' => 500.00,
        ])->assertCreated();

        // 1. تعليق فاتورة
        $holdRes = $this->withToken($token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
            'notes' => 'Customer is getting more items',
        ]);
        $holdRes->assertCreated()->assertJsonStructure(['hold_reference']);

        // فحص قائمة المعلقات
        $heldList = $this->withToken($token)->getJson('/api/v1/pos/sales/held');
        $heldList->assertOk()->assertJsonCount(1, 'data');

        // 2. بيع فاتورة نقدية
        $saleRes = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
            'payment_method' => 'cash',
            'cash_amount'    => 300.00,
            'card_amount'    => 0,
        ]);
        $saleRes->assertCreated();
        $saleId = $saleRes->json('data.id');

        // 3. مرتجع حبة واحدة
        $returnRes = $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1, 'return_to' => 'on_hand'],
            ],
        ]);
        $returnRes->assertOk()
            ->assertJsonPath('data.refund_amount', 150);

        // التحقق من عودة الحبة للمخزون (50 - 2 + 1 = 49)
        $stock = StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')
            ->first();
        $this->assertEquals(49.00, (float) $stock->on_hand);
    }
}
