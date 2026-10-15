<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventorySystemTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'inventory.view', 'inventory.adjust',
        'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
        'transfers.approve', 'transfers.ship', 'transfers.receive',
        'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class); // مفاتيح الترقيم لازمة لتوليد أرقام الحركات والمستندات
    }

    // ---------- InventoryService / Ledger ----------

    public function test_record_movement_creates_level_and_ledger_entry(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $movement = app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 10,
            'created_by' => $admin->id,
        ]);

        $this->assertSame('10.0000', $movement->balance_after);
        $this->assertSame('0.0000', $movement->balance_before);
        $this->assertStringStartsWith('MOV-', $movement->reference_number);

        $level = StockLevel::query()->where('product_variant_id', $variant->id)->where('branch_id', $branch->id)->first();
        $this->assertSame('10.0000', $level->on_hand);
        $this->assertSame(10.0, $level->available);
    }

    public function test_record_movement_rejects_zero_quantity_and_negative_balance(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $service = app(InventoryService::class);
        $base = ['product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'created_by' => User::factory()->create()->id];

        try {
            $service->recordMovement([...$base, 'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 0]);
            $this->fail('zero quantity must be rejected');
        } catch (BusinessException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(BusinessException::class);
        $service->recordMovement([...$base, 'type' => 'adjustment_out', 'affected_bucket' => 'on_hand', 'quantity' => -1]);
    }

    public function test_reserve_cannot_exceed_available(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $service = app(InventoryService::class);
        $base = ['product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'created_by' => User::factory()->create()->id];

        $service->recordMovement([...$base, 'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 5]);

        $this->expectException(BusinessException::class);
        $service->recordMovement([...$base, 'type' => 'reserve', 'affected_bucket' => 'reserved', 'quantity' => 6]);
    }

    public function test_movement_ledger_is_immutable(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $movement = app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand',
            'quantity' => 3,
            'created_by' => User::factory()->create()->id,
        ]);

        try {
            $movement->update(['quantity' => 99]);
            $this->fail('ledger update must be blocked');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    // ---------- Adjustments endpoint ----------

    public function test_adjustments_flow_and_atomic_rollback(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 10, 'reason' => 'رصيد افتتاحي']],
        ])->assertCreated();

        // عنصر صالح + عنصر مستحيل: الكل يتلغي
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 4],
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 100],
            ],
        ])->assertUnprocessable();

        $level = StockLevel::query()->where('product_variant_id', $variant->id)->first();
        $this->assertSame('10.0000', $level->on_hand);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_mark_damaged_reduces_available_only(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 10],
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'mark_damaged', 'quantity' => 4],
            ],
        ])->assertCreated();

        $response = $this->requestWithToken($token, 'GET', "/api/v1/admin/inventory?branch_id={$branch->id}");
        $response->assertOk()
            ->assertJsonPath('data.0.on_hand', '10.0000')
            ->assertJsonPath('data.0.damaged', '4.0000')
            ->assertJsonPath('data.0.available', '6.0000');
    }

    public function test_movements_endpoint_filters_by_type_and_orders_latest_first(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 10],
                ['product_variant_id' => $variant->id, 'adjustment_type' => 'mark_damaged', 'quantity' => 2],
            ],
        ])->assertCreated();

        $response = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements?type=damage');
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'damage');

        $all = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements');
        $this->assertSame('damage', $all->json('data.0.type')); // الأحدث أولًا
    }

    public function test_inventory_endpoints_require_permissions(): void
    {
        $viewer = $this->staffWithPermissions([]);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory')->assertForbidden();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => 1, 'items' => [],
        ])->assertForbidden();
    }

    // ---------- Transfers ----------

    public function test_transfer_full_lifecycle_with_shortage(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();

        $this->seedStock($token, $branch = $from, $variant, 20);

        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 8]],
        ])->assertCreated()->json('data');
        $transferId = $transfer['id'];

        // الاعتماد: حجز 8 عند المرسل
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transferId}/approve", [])->assertOk();
        $levelFrom = $this->level($variant->id, $from->id);
        $this->assertSame('8.0000', $levelFrom->reserved);
        $this->assertSame(12.0, $levelFrom->available);

        // الشحن: خروج فعلي وفك حجز و in_transit عند المستلم
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transferId}/ship", [])->assertOk();
        $levelFrom = $this->level($variant->id, $from->id);
        $this->assertSame('12.0000', $levelFrom->on_hand);
        $this->assertSame('0.0000', $levelFrom->reserved);
        $this->assertSame('8.0000', $this->level($variant->id, $to->id)->in_transit);

        // الاستلام بعجز: شُحن 8 واستُلم 6
        $itemId = $transfer['items'][0]['id'];
        $response = $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transferId}/receive", [
            'items' => [['id' => $itemId, 'received_qty' => 6]],
        ])->assertOk();

        $levelTo = $this->level($variant->id, $to->id);
        $this->assertSame('0.0000', $levelTo->in_transit);
        $this->assertSame('6.0000', $levelTo->on_hand);
        $this->assertSame('received', $response->json('data.status'));
        $this->assertStringContainsString('عجز', (string) $response->json('data.notes'));

        $shortage = StockMovement::query()
            ->where('type', StockMovementType::TransferShortage)
            ->where('affected_bucket', StockBucket::InTransit)
            ->first();
        $this->assertNotNull($shortage);
        $this->assertSame('-2.0000', $shortage->quantity);
    }

    public function test_transfer_partial_ship_releases_unshipped_reservation(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();

        $this->seedStock($token, $from, $variant, 20);

        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 10]],
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/approve", [])->assertOk();

        $itemId = $transfer['items'][0]['id'];
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$transfer['id']}/ship", [
            'items' => [['id' => $itemId, 'shipped_qty' => 4]],
        ])->assertOk();

        $levelFrom = $this->level($variant->id, $from->id);
        $this->assertSame('16.0000', $levelFrom->on_hand);
        $this->assertSame('0.0000', $levelFrom->reserved); // الباقي المحجوز اتفك
        $this->assertSame('4.0000', $this->level($variant->id, $to->id)->in_transit);
    }

    public function test_transfer_state_guards_and_cancel_releases_reserve(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();

        $this->seedStock($token, $from, $variant, 10);

        $transfer = $this->requestWithToken($token, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 5]],
        ])->assertCreated()->json('data');
        $id = $transfer['id'];

        // شحن قبل الاعتماد ممنوع
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$id}/ship", [])->assertUnprocessable();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$id}/approve", [])->assertOk();
        $this->assertSame('5.0000', $this->level($variant->id, $from->id)->reserved);

        // اعتماد مرتين ممنوع، وتعديل المعتمد ممنوع
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$id}/approve", [])->assertUnprocessable();
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/transfers/{$id}", ['notes' => 'x'])->assertUnprocessable();

        // الإلغاء يفك الحجز
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$id}/cancel", [])->assertOk();
        $this->assertSame('0.0000', $this->level($variant->id, $from->id)->reserved);
        $this->assertSame(10.0, $this->level($variant->id, $from->id)->available);
    }

    // ---------- Stocktakes ----------

    public function test_stocktake_snapshot_difference_and_approve_applies_movements(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $this->seedStock($token, $branch, $variant, 10);

        $stocktake = $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 7]],
        ])->assertCreated()->json('data');

        $this->assertSame('10.0000', $stocktake['items'][0]['system_qty']);
        $this->assertSame('-3.0000', $stocktake['items'][0]['difference']);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$stocktake['id']}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('7.0000', $this->level($variant->id, $branch->id)->on_hand);
        $loss = StockMovement::query()->where('type', StockMovementType::StocktakeLoss)->first();
        $this->assertNotNull($loss);
        $this->assertSame('-3.0000', $loss->quantity);

        // بعد الاعتماد: لا تعديل ولا اعتماد تاني
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/stocktakes/{$stocktake['id']}", ['notes' => 'x'])->assertUnprocessable();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$stocktake['id']}/approve", [])->assertUnprocessable();
    }

    public function test_stocktake_approve_blocked_until_all_counted_and_gain_applies(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $admin->createToken('test')->plainTextToken;
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();

        $this->seedStock($token, $branch, $variant, 5);

        $stocktake = $this->requestWithToken($token, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id]],
        ])->assertCreated()->json('data');

        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$stocktake['id']}/approve", [])->assertUnprocessable();

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/stocktakes/{$stocktake['id']}", [
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 9]],
        ])->assertOk();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/stocktakes/{$stocktake['id']}/approve", [])->assertOk();
        $this->assertSame('9.0000', $this->level($variant->id, $branch->id)->on_hand);
        $gain = StockMovement::query()->where('type', StockMovementType::StocktakeGain)->first();
        $this->assertSame('4.0000', $gain->quantity);
    }

    // ---------- Helpers ----------

    private function seedStock(string $token, Branch $branch, ProductVariant $variant, float $qty): void
    {
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => $qty]],
        ])->assertCreated();
    }

    private function level(int $variantId, int $branchId): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->whereNull('location_id')
            ->firstOrFail();
    }

    private function staffWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
