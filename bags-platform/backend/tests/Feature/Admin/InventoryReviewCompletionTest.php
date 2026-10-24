<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\InventoryService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Carbon\Carbon;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryReviewCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class, PricingPermissionSeeder::class]);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(Permission::where('guard_name', 'staff')->get());
    }

    public function test_same_second_item_replacement_rejects_stale_version_and_legacy_timestamp(): void
    {
        $this->freezeTime();
        $from = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);
        $created = $this->api('POST', 'transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ])->assertCreated()->json('data');
        $updated = $this->api('PATCH', "transfers/{$created['id']}", [
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ])->assertOk()->json('data');
        $this->assertGreaterThan($created['version'], $updated['version']);
        $this->assertNotSame($created['updated_at'], $updated['updated_at']);
        $this->api('POST', "transfers/{$created['id']}/approve", ['expected_version' => $created['version']])->assertConflict();
        $this->api('POST', "transfers/{$created['id']}/approve", ['expected_updated_at' => $created['updated_at']])->assertConflict();
        $this->assertSame('0.0000', StockLevel::where('branch_id', $from->id)->firstOrFail()->reserved);
        $this->api('POST', "transfers/{$created['id']}/approve", ['expected_version' => $updated['version']])->assertOk();
        $this->assertSame('4.0000', StockLevel::where('branch_id', $from->id)->firstOrFail()->reserved);
    }

    public function test_timestamp_comparison_accepts_an_equivalent_timezone_offset(): void
    {
        $from = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);
        $transfer = $this->transfer($variant, $from);
        $this->api('POST', "transfers/{$transfer->id}/approve", [
            'expected_updated_at' => Carbon::parse($transfer->updated_at)->setTimezone('Africa/Cairo')->toIso8601String(),
        ])->assertOk();
    }

    public function test_stocktake_gain_on_inactive_variant_is_recorded(): void
    {
        // H3: الفائض بضاعة موجودة فعلًا فيُسجَّل ولو عُطّل الصنف — المنع
        // القديم كان يحبس الجرد كاملًا بسبب صنف واحد.
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 5);
        $stocktake = $this->stocktake($variant, $branch, 7);
        $variant->update(['is_active' => false]);
        $this->api('POST', "stocktakes/{$stocktake->id}/approve")->assertOk();
        $this->assertSame('approved', $stocktake->fresh()->status->value);
        $this->assertSame('7.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_transfer_receipt_into_inactive_location_succeeds(): void
    {
        // H3: البضاعة خرجت فعلًا من المصدر فيجب استلامها أينما كانت —
        // الرفض القديم كان يعلق التحويل في Shipped بلا مخرج إلا الشطب الوهمي.
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $to->id]);
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 5);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'to_location_id' => $location->id, 'requested_qty' => 2]],
        ], $this->staff->id);
        app(TransferService::class)->approve($transfer, [], $this->staff->id);
        app(TransferService::class)->ship($transfer, [], $this->staff->id);
        $location->update(['is_active' => false]);
        $this->api('POST', "transfers/{$transfer->id}/receive")->assertOk();
        $this->assertSame('received', $transfer->fresh()->status->value);
        $destination = StockLevel::where('branch_id', $to->id)->firstOrFail();
        $this->assertSame('0.0000', $destination->in_transit);
        $this->assertSame('2.0000', $destination->on_hand);
    }

    public function test_apply_difference_policy_rejects_second_overlapping_stocktake(): void
    {
        config(['inventory.stocktake_stale_policy' => 'apply_difference']);
        $this->freezeTime();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $first = $this->stocktake($variant, $branch, 7);
        $second = $this->stocktake($variant, $branch, 7);
        $this->api('POST', "stocktakes/{$first->id}/approve")->assertOk();
        $this->api('POST', "stocktakes/{$second->id}/approve")->assertUnprocessable();
        $this->assertSame('7.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame(1, StockMovement::where('type', 'stocktake_loss')->count());
    }

    public function test_apply_difference_checks_projected_balance_against_reservations(): void
    {
        config(['inventory.stocktake_stale_policy' => 'apply_difference']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $stocktake = $this->stocktake($variant, $branch, 9);
        $this->movement($variant, $branch, 10);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'reserve', 'affected_bucket' => 'reserved', 'quantity' => 12, 'created_by' => $this->staff->id,
        ]);
        $this->api('POST', "stocktakes/{$stocktake->id}/approve")->assertOk();
        $this->assertSame('19.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame('12.0000', StockLevel::firstOrFail()->reserved);
    }

    public function test_changing_stocktake_location_refreshes_snapshot_for_unchanged_count(): void
    {
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $stocktake = $this->stocktake($variant, $branch, 7);
        $this->api('PATCH', "stocktakes/{$stocktake->id}", [
            'location_id' => $location->id, 'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 7]],
        ])->assertOk()->assertJsonPath('data.items.0.system_qty', '0.0000');
        $this->api('POST', "stocktakes/{$stocktake->id}/approve")->assertOk();
        $this->assertSame('10.0000', StockLevel::whereNull('location_id')->firstOrFail()->on_hand);
        $this->assertSame('7.0000', StockLevel::where('location_id', $location->id)->firstOrFail()->on_hand);
    }

    public function test_branch_staff_cannot_change_global_customer_group_discounts(): void
    {
        $branchStaff = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $branchStaff->givePermissionTo(['customer_groups.create', 'customer_groups.update', 'customer_groups.delete']);
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'VIP', 'discount_percentage' => 5]);
        $this->api('POST', 'customer-groups', ['name' => 'Hijack', 'code' => 'HIJACK'], $branchStaff)->assertForbidden();
        $this->api('PATCH', "customer-groups/{$group->id}", ['discount_percentage' => 100], $branchStaff)->assertForbidden();
        $this->api('DELETE', "customer-groups/{$group->id}", [], $branchStaff)->assertForbidden();
        $this->assertSame('5.00', $group->fresh()->discount_percentage);
        $this->api('PATCH', "customer-groups/{$group->id}", ['discount_percentage' => 10])->assertOk();
    }

    public function test_date_only_prices_start_at_business_midnight_and_ambiguous_filters_fail(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 14:37:45', 'UTC'));
        $response = $this->api('POST', 'price-lists', [
            'name' => 'Midnight', 'type' => 'retail', 'starts_at' => '2026-11-01',
        ])->assertCreated();
        $list = PriceList::findOrFail($response->json('data.id'));
        $this->assertSame('2026-10-31 22:00:00', $list->starts_at->format('Y-m-d H:i:s'));
        $this->api('GET', 'inventory/movements?from_date=01/02/2026')->assertUnprocessable();
        $this->api('GET', 'inventory/movements?to_date=2026-11-01')->assertOk();
    }

    public function test_numeric_notes_are_distinct_idempotency_payloads(): void
    {
        $branch = Branch::factory()->create();
        $payload = ['branch_id' => $branch->id, 'items' => [[
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'adjustment_type' => 'increase', 'quantity' => 1, 'reason' => '001',
        ]]];
        $this->withHeader('Idempotency-Key', 'numeric-notes');
        $this->api('POST', 'inventory/adjustments', $payload)->assertCreated();
        $payload['items'][0]['reason'] = '1';
        $this->api('POST', 'inventory/adjustments', $payload)->assertConflict();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_idempotency_replays_recheck_current_branch_access(): void
    {
        $branch = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->givePermissionTo('inventory.adjust');
        $payload = ['branch_id' => $branch->id, 'items' => [[
            'product_variant_id' => ProductVariant::factory()->create()->id, 'adjustment_type' => 'increase', 'quantity' => 2,
        ]]];
        $this->withHeader('Idempotency-Key', 'scope-change');
        $this->api('POST', 'inventory/adjustments', $payload, $staff)->assertCreated();
        $staff->update(['branch_id' => Branch::factory()->create()->id]);
        app()->forgetInstance(BranchAccess::class);
        $this->api('POST', 'inventory/adjustments', $payload, $staff)->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_reconciliation_detects_missing_balance_rows_and_chain_breaks(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 5);
        DB::table('stock_levels')->where('product_variant_id', $variant->id)->update(['on_hand' => 8]);
        $this->movement($variant, $branch, 1);
        $this->artisan('inventory:reconcile')->expectsOutputToContain('chain')->assertFailed();
        DB::table('stock_levels')->where('product_variant_id', $variant->id)->delete();
        $this->artisan('inventory:reconcile')->expectsOutputToContain('missing_level')->assertFailed();
    }

    public function test_shipping_and_receiving_retries_return_saved_quantities_without_new_movements(): void
    {
        $from = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 5);
        $transfer = $this->transfer($variant, $from);
        $this->api('POST', "transfers/{$transfer->id}/approve")->assertOk();
        $shipped = $this->api('POST', "transfers/{$transfer->id}/ship")->assertOk()->json('data');
        $before = StockMovement::count();
        $this->api('POST', "transfers/{$transfer->id}/ship")->assertOk()->assertJsonPath('data.version', $shipped['version']);
        $this->assertSame($before, StockMovement::count());
        $this->api('POST', "transfers/{$transfer->id}/ship", ['items' => [
            ['id' => $transfer->items->first()->id, 'shipped_qty' => 1],
        ]])->assertConflict();
        $this->api('POST', "transfers/{$transfer->id}/receive")->assertOk();
        $before = StockMovement::count();
        $this->api('POST', "transfers/{$transfer->id}/receive")->assertOk();
        $this->assertSame($before, StockMovement::count());
    }

    public function test_lock_conflicts_outside_stock_level_locking_return_409(): void
    {
        $payload = [
            'from_branch_id' => Branch::factory()->create()->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'requested_qty' => 1]],
        ];
        $event = 'eloquent.creating: '.StockTransfer::class;
        foreach (['55P03', '40P01'] as $sqlState) {
            Event::listen($event, function () use ($sqlState): void {
                $previous = new \PDOException('Lock conflict');
                $previous->errorInfo = [$sqlState, 7, 'Lock conflict'];
                throw new QueryException('pgsql', 'insert into stock_transfers', [], $previous);
            });
            try {
                $this->api('POST', 'transfers', $payload)->assertConflict();
                $this->assertDatabaseCount('stock_transfers', 0);
            } finally {
                Event::forget($event);
            }
        }
    }

    public function test_serialized_iso_dates_can_be_sent_back_without_changing_the_instant(): void
    {
        $list = $this->api('POST', 'price-lists', [
            'name' => 'ISO', 'type' => 'retail', 'starts_at' => '2026-11-01T00:00:00+02:00',
        ])->assertCreated()->json('data');
        $this->api('PATCH', "price-lists/{$list['id']}", ['starts_at' => $list['starts_at']])
            ->assertOk()->assertJsonPath('data.starts_at', $list['starts_at']);
    }

    private function stocktake(ProductVariant $variant, Branch $branch, float $count): Stocktake
    {
        return app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [[
            'product_variant_id' => $variant->id, 'counted_qty' => $count,
        ]]], $this->staff->id);
    }

    private function transfer(ProductVariant $variant, Branch $from): StockTransfer
    {
        return app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $this->staff->id);
    }

    private function movement(ProductVariant $variant, Branch $branch, float $qty): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand', 'quantity' => $qty, 'created_by' => $this->staff->id,
        ]);
    }

    private function api(string $method, string $path, array $data = [], ?User $staff = null, array $headers = []): TestResponse
    {
        Auth::forgetGuards();

        // M2: مفتاح عدم تكرار جديد لكل طلب — إلا إذا حدده الاختبار (إعادة تشغيل).
        if (! isset($headers['Idempotency-Key']) && ! isset($this->defaultHeaders['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->withToken(($staff ?? $this->staff)->createToken('completion')->plainTextToken)
            ->json($method, '/api/v1/admin/'.$path, $data, $headers);
    }
}
