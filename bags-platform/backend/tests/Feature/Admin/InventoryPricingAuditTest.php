<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PriceListService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryPricingAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class, PricingPermissionSeeder::class]);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(Permission::where('guard_name', 'staff')->get());
        $this->token = $this->staff->createToken('audit')->plainTextToken;
    }

    public function test_fractional_movements_reach_exact_zero(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $this->movement($variant, $branch, 0.3);
        $this->movement($variant, $branch, -0.1);
        $this->movement($variant, $branch, -0.2);
        $this->assertSame('0.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame('0.0000', StockMovement::latest('id')->firstOrFail()->balance_after);
    }

    public function test_inventory_and_ledger_remain_readable_after_variant_is_archived(): void
    {
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, Branch::factory()->create(), 1);
        $variant->delete();
        $this->api('GET', 'inventory')->assertOk()->assertJsonPath('data.0.variant.sku', $variant->sku);
        $this->api('GET', 'inventory/movements')->assertOk()->assertJsonPath('data.0.variant.sku', $variant->sku);
    }

    public function test_direct_ledger_update_delete_and_truncate_are_blocked_by_database(): void
    {
        $movement = $this->movement(ProductVariant::factory()->create(), Branch::factory()->create(), 1);
        foreach (['update', 'delete', 'truncate'] as $action) {
            try {
                DB::transaction(function () use ($action, $movement): void {
                    match ($action) {
                        'update' => DB::table('stock_movements')->where('id', $movement->id)->update(['notes' => 'tampered']),
                        'delete' => DB::table('stock_movements')->where('id', $movement->id)->delete(),
                        'truncate' => DB::statement('TRUNCATE stock_movements'),
                    };
                });
                $this->fail('Ledger mutation must be blocked: '.$action);
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id, 'notes' => null]);
    }

    public function test_zero_shipment_is_rejected_and_cancel_releases_all_reserved_quantity(): void
    {
        $variant = ProductVariant::factory()->create();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $this->movement($variant, $from, 10);
        $service = app(TransferService::class);
        $transfer = $service->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 5]],
        ], $this->staff->id);
        $service->approve($transfer, [], $this->staff->id);
        $this->api('POST', "transfers/{$transfer->id}/ship", ['items' => [['id' => $transfer->items->first()->id, 'shipped_qty' => 0]]])->assertUnprocessable();
        $this->assertSame('5.0000', StockLevel::where('branch_id', $from->id)->firstOrFail()->reserved);
        $service->cancel($transfer, $this->staff->id);
        $level = StockLevel::where('branch_id', $from->id)->firstOrFail();
        $this->assertSame('0.0000', $level->reserved);
        $this->assertSame('10.0000', $level->on_hand);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'transfer_out']);
    }

    public function test_transfer_cannot_change_to_same_branch_or_retain_locations_from_old_branch(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $from->id]);
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [[
                'product_variant_id' => ProductVariant::factory()->create()->id,
                'from_location_id' => $location->id, 'requested_qty' => 1,
            ]],
        ], $this->staff->id);
        $this->api('PATCH', "transfers/{$transfer->id}", ['to_branch_id' => $from->id])->assertUnprocessable();
        $this->api('PATCH', "transfers/{$transfer->id}", ['from_branch_id' => Branch::factory()->create()->id])->assertUnprocessable();
        $this->assertSame($from->id, $transfer->fresh()->from_branch_id);
    }

    public function test_transfer_rejects_missing_quantities_and_duplicate_item_ids(): void
    {
        $transfer = app(TransferService::class)->create([
            'from_branch_id' => Branch::factory()->create()->id,
            'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'requested_qty' => 1]],
        ], $this->staff->id);
        $id = $transfer->items->first()->id;
        $this->api('POST', "transfers/{$transfer->id}/approve", ['items' => [['id' => $id]]])->assertUnprocessable();
        $this->api('POST', "transfers/{$transfer->id}/approve", ['items' => [
            ['id' => $id, 'approved_qty' => 0], ['id' => $id, 'approved_qty' => 0],
        ]])->assertUnprocessable();
    }

    public function test_stocktake_scope_change_requires_new_counts_and_valid_location(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $stocktake = app(StocktakeService::class)->create([
            'branch_id' => $branch->id, 'location_id' => $location->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'counted_qty' => 2]],
        ], $this->staff->id);
        $this->api('PATCH', "stocktakes/{$stocktake->id}", ['branch_id' => $other->id])->assertUnprocessable();
        $this->api('PATCH', "stocktakes/{$stocktake->id}", ['location_id' => null])->assertUnprocessable();
        $this->assertSame($location->id, $stocktake->fresh()->location_id);
    }

    public function test_stocktake_recounts_take_a_new_system_snapshot(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $service = app(StocktakeService::class);
        $stocktake = $service->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 7],
        ]], $this->staff->id);
        $this->movement($variant, $branch, 5);
        $updated = $service->update($stocktake, ['items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 14],
        ]], $this->staff->id);
        $this->assertSame('15.0000', $updated->items->first()->system_qty);
        $this->assertSame('-1.0000', $updated->items->first()->difference);
        $service->approve($updated, $this->staff->id);
        $this->assertSame('14.0000', StockLevel::firstOrFail()->on_hand);
    }

    public function test_empty_stocktake_cannot_be_approved(): void
    {
        $stocktake = app(StocktakeService::class)->create(['branch_id' => Branch::factory()->create()->id], $this->staff->id);
        $this->api('POST', "stocktakes/{$stocktake->id}/approve")->assertUnprocessable();
    }

    public function test_price_item_defaults_and_scoped_binding(): void
    {
        $list = PriceList::create(['name' => 'Retail', 'type' => 'retail']);
        $other = PriceList::create(['name' => 'Other', 'type' => 'retail']);
        $variant = ProductVariant::factory()->create();
        $item = $this->api('POST', "price-lists/{$list->id}/items", [
            'product_variant_id' => $variant->id, 'price' => 10,
        ])->assertCreated()->assertJsonPath('data.min_qty', '1.0000')->json('data.id');
        $this->api('GET', "price-lists/{$other->id}/items/{$item}")->assertNotFound();
        $this->api('PATCH', "price-lists/{$other->id}/items/{$item}", ['price' => 1])->assertNotFound();
        $this->api('DELETE', "price-lists/{$other->id}/items/{$item}")->assertNotFound();
    }

    public function test_partial_date_updates_cannot_invert_list_or_item_validity(): void
    {
        $list = PriceList::create(['name' => 'Retail', 'type' => 'retail', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31']);
        $item = $list->items()->create([
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'price' => 10, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31',
        ]);
        $this->api('PATCH', "price-lists/{$list->id}", ['starts_at' => '2026-11-01'])->assertUnprocessable();
        $this->api('PATCH', "price-lists/{$list->id}/items/{$item->id}", ['ends_at' => '2026-09-01'])->assertUnprocessable();
        $this->assertSame('2026-10-01', $list->fresh()->starts_at->toDateString());
    }

    public function test_price_validity_can_have_only_an_end_date(): void
    {
        $variant = ProductVariant::factory()->create();
        $listId = $this->api('POST', 'price-lists', [
            'name' => 'End only', 'type' => 'retail', 'ends_at' => '2026-12-31',
        ])->assertCreated()->json('data.id');
        $this->api('POST', "price-lists/{$listId}/items", [
            'product_variant_id' => $variant->id, 'price' => 10, 'ends_at' => '2026-12-31',
        ])->assertCreated();
        $csv = "sku,min_qty,price,ends_at\n{$variant->sku},2,9,2026-12-31\n";
        $report = app(PriceListService::class)->importCsv(PriceList::findOrFail($listId), UploadedFile::fake()->createWithContent('prices.csv', $csv));
        $this->assertSame(1, $report['created']);
        $this->assertSame(0, $report['skipped']);
    }

    public function test_customer_group_search_keeps_active_filter(): void
    {
        CustomerGroup::create(['name' => 'match active', 'code' => 'ACTIVE', 'is_active' => true]);
        CustomerGroup::create(['name' => 'match inactive', 'code' => 'INACTIVE', 'is_active' => false]);
        $this->api('GET', 'customer-groups?search=match&is_active=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ACTIVE');
    }

    public function test_csv_accepts_bom_and_rejects_invalid_dates_precision_and_overflows(): void
    {
        $variant = ProductVariant::factory()->create();
        $list = PriceList::create(['name' => 'Retail', 'type' => 'retail']);
        $csv = "\xEF\xBB\xBFsku,min_qty,price,starts_at,ends_at\n"
            ."{$variant->sku},1,10,,\n"
            ."{$variant->sku},2,20,2026-10-31,2026-10-01\n"
            ."{$variant->sku},3,30.00001,,\n"
            ."{$variant->sku},4,100000000,,\n"
            ."{$variant->sku},0.00001,10,,\n";
        $report = app(PriceListService::class)->importCsv($list, UploadedFile::fake()->createWithContent('prices.csv', $csv));
        $this->assertSame(1, $report['created']);
        $this->assertSame(4, $report['skipped']);
        $this->assertDatabaseCount('price_list_items', 1);
    }

    public function test_adjustment_rejects_quantity_below_storage_precision(): void
    {
        $this->api('POST', 'inventory/adjustments', [
            'branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'adjustment_type' => 'increase', 'quantity' => 0.00001]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_service_rejects_location_from_another_branch(): void
    {
        $this->expectException(BusinessException::class);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'branch_id' => Branch::factory()->create()->id,
            'location_id' => Location::factory()->create()->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 1, 'created_by' => $this->staff->id,
        ]);
    }

    private function movement(ProductVariant $variant, Branch $branch, float $qty): StockMovement
    {
        return app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => $qty > 0 ? 'adjustment_in' : 'adjustment_out',
            'affected_bucket' => 'on_hand', 'quantity' => $qty, 'created_by' => $this->staff->id,
        ]);
    }

    private function api(string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        Auth::forgetGuards();

        // M2: مفتاح عدم تكرار جديد لكل طلب — إلا إذا حدده الاختبار (إعادة تشغيل).
        if (! isset($headers['Idempotency-Key']) && ! isset($this->defaultHeaders['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->withToken($this->token)->json($method, '/api/v1/admin/'.$path, $data, $headers);
    }
}
