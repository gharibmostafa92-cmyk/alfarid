<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Treasury;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * انحدار الإصلاحات الحرجة C1–C2 من مراجعة ملف c:
 * - C1: الخصم يُحتسب مرة واحدة (subtotal إجمالي، grand = مجموع line_total).
 * - C2: المرتجع بالسعر الصافي، بلا تجاوز للكمية، وبلا "إرجاع المرتجع".
 */
class PosCriticalFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private Treasury $posDrawer;

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
        foreach (['pos.access', 'pos.sales.refund'] as $permission) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        $this->posDrawer = Treasury::create([
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

        $brand = Brand::create(['name_ar' => 'براند أ', 'name_en' => 'Brand A', 'slug' => 'brand-a', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'bags', 'is_active' => true]);
        $prod = Product::create([
            'code' => 'PRD-001',
            'name_ar' => 'شنطة جلد',
            'name_en' => 'Leather Bag',
            'slug' => 'leather-bag',
            'status' => ProductStatus::Published,
            'brand_id' => $brand->id,
            'category_id' => $cat->id,
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

        $this->cashier->givePermissionTo(Permission::findOrCreate('pos.discount', 'staff'));
        $this->token = $this->cashier->createToken('pos', ['pos'])->plainTextToken;

        $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $this->posDrawer->id,
            'opening_cash' => 200.00,
        ])->assertStatus(201);
    }

    public function test_discounted_sale_totals_count_discount_once(): void
    {
        // سعر 150 وخصم 60: الإجمالي الصحيح 90 (الكود القديم كان يعطي 30).
        $saleRes = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1, 'discount_amount' => 60],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 90.00,
            'card_amount' => 0,
        ]);

        $saleRes->assertStatus(201)
            ->assertJsonPath('data.grand_total', '90.00')
            ->assertJsonPath('data.cash_amount', '90.00')
            ->assertJsonPath('data.items.0.line_total', '90.00')
            ->assertJsonPath('data.items.0.discount_amount', '60.00');
    }

    public function test_return_uses_net_price_and_rejects_excess_quantity(): void
    {
        $saleRes = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2, 'discount_amount' => 100],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 200.00,
            'card_amount' => 0,
        ]);
        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('data.id');

        // الصافي للقطعة = (300-100)/2 = 100، فإرجاع قطعة يرد 100 لا 150.
        $retRes = $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $retRes->assertOk();
        $this->assertEquals(100.00, (float) $retRes->json('data.refund_amount'));

        // المتبقي قطعة واحدة: إرجاع قطعتين مرفوض، والسطران المكرران مجموعهما قطعتان مرفوض أيضًا.
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertStatus(422);

        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
                ['product_variant_id' => $this->variant->id, 'qty' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_return_of_return_is_rejected(): void
    {
        $saleRes = $this->withToken($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 150.00,
            'card_amount' => 0,
        ]);
        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('data.id');

        $retRes = $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $retRes->assertOk();
        $returnOrderId = $retRes->json('data.return_order_id');
        $this->assertNotNull($returnOrderId);

        // "إرجاع المرتجع" مرفوض: طلب المرتجع حالته Returned وليست Delivered.
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$returnOrderId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if (strtoupper($method) === 'POST' && str_ends_with($uri, '/pos/sales') && ! isset($headers['Idempotency-Key']) && ! isset($this->defaultHeaders['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
