<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PricingService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * انحدار جولة المراجعة الثالثة: C1 لقطة الجرد، H1 فروع fail-closed،
 * H2 عدم تكرار التسويات، H3 خصم المجموعة فوق القناة، M1 حمولة الاعتماد،
 * M2 حذف التحويلات ذات الحركات، M3 الصنف غير المُسعَّر.
 */
class SecurityReviewRound3Test extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'inventory.view', 'inventory.adjust', 'inventory.all_branches',
        'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
        'transfers.approve', 'transfers.ship', 'transfers.receive',
        'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
        'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
        'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
    ];

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class, PricingPermissionSeeder::class]);
        $this->actor = User::factory()->create();
    }

    // ---------- C1: لقطة الجرد مربوطة بلحظة العد ----------

    public function test_resaving_a_stocktake_does_not_refresh_snapshots_or_hide_sales(): void
    {
        // سياسة الرفض الصارمة (سلوك هذه الجولة) — الافتراضي من الجولة 5 هو
        // apply_difference، والرفض يظل متاحًا بالضبط (M-5 جولة 5).
        config(['inventory.stocktake_stale_policy' => 'reject']);
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);

        $service = app(StocktakeService::class);
        $stocktake = $service->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 10],
        ]], $staff->id);

        // بيع قطعتين بعد العد
        $this->movement($variant, $branch, -2, StockMovementType::AdjustmentOut);

        // إعادة حفظ بنفس الأعداد (الواجهة ترسل المصفوفة كاملة) — لا يجوز أن تجدد اللقطة
        $service->update($stocktake, ['items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 10],
        ]], $staff->id);
        $this->assertSame('10.0000', $stocktake->items()->firstOrFail()->system_qty);

        // الاعتماد يرفض ويرجع قائمة الأصناف المحتاجة إعادة عد، ولا يصنع فائضًا وهميًا
        try {
            $service->approve($stocktake, $staff->id);
            $this->fail('approve must reject a stale count');
        } catch (BusinessException $e) {
            $this->assertSame([$variant->id], $e->errors['recount_variant_ids']);
        }
        $this->assertSame('8.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);
        $this->assertSame(0, StockMovement::where('type', StockMovementType::StocktakeGain)->count());
    }

    public function test_recount_flag_and_changed_count_refresh_the_snapshot(): void
    {
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);

        $service = app(StocktakeService::class);
        $stocktake = $service->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 10],
        ]], $staff->id);
        $this->movement($variant, $branch, -2, StockMovementType::AdjustmentOut);

        // إعادة عد فعلية بنفس الرقم لكن بعلم recount: لقطة جديدة 8 وفرق +2 مشروع
        $updated = $service->update($stocktake, ['items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 10, 'recount' => true],
        ]], $staff->id);
        $this->assertSame('8.0000', $updated->items->first()->system_qty);

        $service->approve($updated, $staff->id);
        $this->assertSame('10.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);
    }

    // ---------- H1: الوصول للفروع fail-closed ----------

    public function test_branchless_staff_without_all_branches_permission_is_rejected(): void
    {
        $limited = $this->staffWithPermissions(['inventory.view', 'inventory.adjust']);
        $token = $limited->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();

        $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory')->assertForbidden();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertForbidden();

        $hq = $this->staffWithPermissions(['inventory.view', 'inventory.all_branches']);
        $hqToken = $hq->createToken('test')->plainTextToken;
        $this->requestWithToken($hqToken, 'GET', '/api/v1/admin/inventory')->assertOk();
    }

    // ---------- H2: التسويات لا تتكرر مع Idempotency-Key ----------

    public function test_adjustments_with_idempotency_key_apply_once(): void
    {
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $staff->createToken('test')->plainTextToken;
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $payload = ['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 5],
        ]];

        $first = $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', $payload, ['Idempotency-Key' => 'pos-retry-001'])->assertCreated();
        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $movementsAfterFirst = StockMovement::count();

        // إعادة الإرسال (retry): نفس الرد، بلا حركات جديدة، والرصيد لا يتضاعف
        $replay = $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', $payload, ['Idempotency-Key' => 'pos-retry-001'])->assertCreated();
        $this->assertSame($firstIds, collect($replay->json('data'))->pluck('id')->all());
        $this->assertSame($movementsAfterFirst, StockMovement::count());
        $this->assertSame('5.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);

        // نفس المفتاح بحمولة مختلفة: 409
        $changed = $payload;
        $changed['items'][0]['quantity'] = 7;
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', $changed, ['Idempotency-Key' => 'pos-retry-001'])
            ->assertStatus(409);
        $this->assertSame('5.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);
    }

    // ---------- H3 + M3: خصم المجموعة فوق القناة، وغير المُسعَّر يرفض ----------

    public function test_group_discount_applies_on_top_of_channel_list_price(): void
    {
        $variant = ProductVariant::factory()->create(['retail_price' => 100]);
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'VIP', 'discount_percentage' => 10]);
        $customer = $this->customer(CustomerType::Wholesale, $group->id);
        $wholesale = PriceList::create(['name' => 'W', 'type' => 'wholesale']);
        $wholesale->items()->create(['product_variant_id' => $variant->id, 'price' => 60]);

        $resolved = app(PricingService::class)->resolve($variant, $customer);
        $this->assertSame(54.0, $resolved['unit_price']); // 60 × 0.9 لا 100 × 0.9
        $this->assertSame('customer_group_discount', $resolved['source']);
        $this->assertSame($wholesale->id, $resolved['price_list_id']);
    }

    public function test_unpriced_variant_throws_instead_of_zero_price(): void
    {
        $variant = ProductVariant::factory()->create(['retail_price' => null]);

        try {
            app(PricingService::class)->resolve($variant);
            $this->fail('unpriced variant must not resolve to zero');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('غير مُسعَّر', $e->getMessage());
        }

        // لكن قائمة مطابقة تنقذه: السعر يأتي منها رغم غياب القطاعي
        $list = PriceList::create(['name' => 'R', 'type' => 'retail']);
        $list->items()->create(['product_variant_id' => $variant->id, 'price' => 50]);
        $this->assertSame(50.0, app(PricingService::class)->resolve($variant)['unit_price']);
    }

    // ---------- M1: حمولة اعتماد التحويل تُفحص تحت القفل ----------

    public function test_approve_rejects_item_ids_that_no_longer_belong_to_the_transfer(): void
    {
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);

        $transfers = app(TransferService::class);
        $transfer = $transfers->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ], $staff->id);
        $staleItemId = $transfer->items->firstOrFail()->id;

        // المُنشئ يعدّل المسودة: معرّفات جديدة تمامًا
        $transfers->update($transfer, ['items' => [
            ['product_variant_id' => $variant->id, 'requested_qty' => 6],
        ]], $staff->id);

        // المعتمد بحمولة قديمة: رفض 409 بدل تجاهل المعرّفات بصمت واعتماد ما لم يره
        try {
            $transfers->approve($transfer, [$staleItemId => 4], $staff->id);
            $this->fail('stale item ids must be rejected');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame([$staleItemId], $e->errors['unknown_item_ids']);
        }
        $this->assertSame('draft', $transfer->fresh()->status->value);
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Reserve)->count());
    }

    public function test_approve_rejects_stale_expected_updated_at(): void
    {
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $staff->createToken('test')->plainTextToken;
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$created['id']}/approve", [
            'expected_updated_at' => now()->subDay()->toIso8601String(),
            'items' => [['id' => $created['items'][0]['id'], 'approved_qty' => 4]],
        ])->assertStatus(409);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$created['id']}/approve", [
            'expected_updated_at' => $created['updated_at'],
            'items' => [['id' => $created['items'][0]['id'], 'approved_qty' => 4]],
        ])->assertOk();
    }

    // ---------- M2: حذف تحويل له حركات دفتر ممنوع ----------

    public function test_cancelled_after_approve_transfer_cannot_be_deleted(): void
    {
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $staff->createToken('test')->plainTextToken;
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$created['id']}/approve", [])->assertOk();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$created['id']}/cancel", [])->assertOk();

        // له حركتا حجز وفك حجز في الدفتر: الحذف يقطع أثر التدقيق
        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/transfers/{$created['id']}")->assertUnprocessable();
        $this->assertDatabaseHas('stock_transfers', ['id' => $created['id'], 'status' => 'cancelled']);
        $this->assertGreaterThan(0, StockMovement::where('reference_type', 'stock_transfer')->where('reference_id', $created['id'])->count());
    }

    // ---------- helpers ----------

    private function staffWithPermissions(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'staff');
        }

        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        Auth::forgetGuards();

        // M2: مفتاح عدم تكرار جديد لكل طلب — إلا إذا حدده الاختبار (إعادة تشغيل).
        if (! isset($headers['Idempotency-Key']) && ! isset($this->defaultHeaders['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->withToken($token)->withHeaders($headers)->json($method, $uri, $data);
    }

    private function movement(ProductVariant $variant, Branch $branch, float $qty, StockMovementType $type = StockMovementType::AdjustmentIn): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => $type, 'affected_bucket' => StockBucket::OnHand, 'quantity' => $qty,
            'created_by' => $this->actor->id,
        ]);
    }

    private function customer(CustomerType $type, ?int $groupId = null): Customer
    {
        return Customer::create([
            'name' => 'Buyer', 'email' => uniqid().'@example.com', 'phone' => fake()->unique()->numerify('010########'),
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'password' => 'password', 'type' => $type, 'status' => CustomerStatus::Approved,
            'customer_group_id' => $groupId,
        ]);
    }
}
