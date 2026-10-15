<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PriceListService;
use App\Services\PricingService;
use App\Services\SettingService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Carbon\Carbon;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryReviewFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class, PricingPermissionSeeder::class]);
        $this->staff = $this->staff();
    }

    public function test_stale_stocktake_is_rejected_even_when_original_difference_was_zero(): void
    {
        foreach ([10, 7] as $count) {
            $branch = Branch::factory()->create();
            $variant = ProductVariant::factory()->create();
            $this->movement($variant, $branch, 10);
            $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [
                ['product_variant_id' => $variant->id, 'counted_qty' => $count],
            ]], $this->staff->id);
            $this->movement($variant, $branch, -2);
            $before = StockMovement::count();
            $this->api('POST', "stocktakes/{$stocktake->id}/approve")->assertUnprocessable();
            $this->assertSame('draft', $stocktake->fresh()->status->value);
            $this->assertSame('8.0000', StockLevel::where('product_variant_id', $variant->id)->firstOrFail()->on_hand);
            $this->assertSame($before, StockMovement::count());
        }
    }

    public function test_overlapping_stocktakes_cannot_apply_the_same_loss_twice(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $payload = ['branch_id' => $branch->id, 'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 7]]];
        $service = app(StocktakeService::class);
        $first = $service->create($payload, $this->staff->id);
        $second = $service->create($payload, $this->staff->id);
        $service->approve($first, $this->staff->id);
        $this->api('POST', "stocktakes/{$second->id}/approve")->assertUnprocessable();
        $this->assertSame('7.0000', StockLevel::firstOrFail()->on_hand);
    }

    public function test_damaged_stock_can_be_restored_or_written_off_and_then_counted(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $this->adjust($branch, $variant, 'mark_damaged', 4)->assertCreated();
        $this->adjust($branch, $variant, 'restore_damaged', 1)->assertCreated();
        $this->assertSame('3.0000', StockLevel::firstOrFail()->damaged);
        $this->adjust($branch, $variant, 'write_off_damaged', 3)->assertCreated()->assertJsonCount(2, 'data');
        $stock = StockLevel::firstOrFail();
        $this->assertSame('0.0000', $stock->damaged);
        $this->assertSame('7.0000', $stock->on_hand);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 2],
        ]], $this->staff->id);
        $this->api('POST', "stocktakes/{$stocktake->id}/approve")->assertOk();
        $this->assertSame('2.0000', $stock->fresh()->on_hand);
    }

    public function test_damage_overdraw_rolls_back_every_bucket(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $branch, 10);
        $this->adjust($branch, $variant, 'mark_damaged', 2)->assertCreated();
        $this->adjust($branch, $variant, 'write_off_damaged', 3)->assertUnprocessable();
        $this->assertSame('10.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame('2.0000', StockLevel::firstOrFail()->damaged);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_branch_staff_cannot_read_or_mutate_other_branch_stock_and_counts(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $staff = $this->staff($own);
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $other, 10);
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $other->id, 'items' => [
            ['product_variant_id' => $variant->id, 'counted_qty' => 9],
        ]], $this->staff->id);
        $this->adjust($other, $variant, 'increase', 1, $staff)->assertForbidden();
        $this->api('GET', 'inventory', [], $staff)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'inventory/movements', [], $staff)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'stocktakes', [], $staff)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "stocktakes/{$stocktake->id}", [], $staff)->assertForbidden();
        $this->api('POST', "stocktakes/{$stocktake->id}/approve", [], $staff)->assertForbidden();
        $this->api('PATCH', "stocktakes/{$stocktake->id}", ['notes' => 'tampered'], $staff)->assertForbidden();
        $this->api('DELETE', "stocktakes/{$stocktake->id}", [], $staff)->assertForbidden();
    }

    public function test_transfer_authorization_follows_sending_and_receiving_branch(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $sourceStaff = $this->staff($from);
        $destinationStaff = $this->staff($to);
        $unrelated = $this->staff(Branch::factory()->create());
        $variant = ProductVariant::factory()->create();
        $this->movement($variant, $from, 10);
        $transfer = app(TransferService::class)->create(['from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $this->staff->id);
        $this->api('GET', "transfers/{$transfer->id}", [], $unrelated)->assertForbidden();
        $this->api('POST', "transfers/{$transfer->id}/approve", [], $destinationStaff)->assertForbidden();
        $this->api('POST', "transfers/{$transfer->id}/approve", [], $sourceStaff)->assertOk();
        $this->api('POST', "transfers/{$transfer->id}/ship", [], $destinationStaff)->assertForbidden();
        $this->api('POST', "transfers/{$transfer->id}/ship", [], $sourceStaff)->assertOk();
        $this->api('POST', "transfers/{$transfer->id}/receive", [], $sourceStaff)->assertForbidden();
        $this->api('POST', "transfers/{$transfer->id}/receive", [], $destinationStaff)->assertOk();
        $this->assertSame(1, StockMovement::where('type', 'transfer_in')->count());
        $this->assertSame(1, StockMovement::where('type', 'transfer_in_transit')->count());
        $this->assertSame(1, StockMovement::where('type', 'transfer_transit_release')->count());
    }

    public function test_super_admin_can_work_across_branches(): void
    {
        $staff = $this->staff(Branch::factory()->create());
        $role = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $staff->assignRole($role);
        $this->adjust(Branch::factory()->create(), ProductVariant::factory()->create(), 'increase', 1, $staff)->assertCreated();
    }

    public function test_malformed_branch_values_return_validation_errors_in_every_location_scoped_request(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $variant = ProductVariant::factory()->create();
        $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id], $this->staff->id);
        $transfer = app(TransferService::class)->create(['from_branch_id' => $branch->id, 'to_branch_id' => $other->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ], $this->staff->id);
        foreach (['abc', ['1'], '999999999999999999999999999'] as $invalid) {
            $this->api('POST', 'inventory/adjustments', ['branch_id' => $invalid, 'location_id' => $location->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'adjustment_type' => 'increase']],
            ])->assertUnprocessable();
            $this->api('POST', 'stocktakes', ['branch_id' => $invalid, 'location_id' => $location->id])->assertUnprocessable();
            $this->api('PATCH', "stocktakes/{$stocktake->id}", ['branch_id' => $invalid, 'location_id' => $location->id])->assertUnprocessable();
            $items = [['product_variant_id' => $variant->id, 'from_location_id' => $location->id, 'requested_qty' => 1]];
            $this->api('POST', 'transfers', ['from_branch_id' => $invalid, 'to_branch_id' => $other->id, 'items' => $items])->assertUnprocessable();
            $this->api('PATCH', "transfers/{$transfer->id}", ['from_branch_id' => $invalid, 'items' => $items])->assertUnprocessable();
        }
    }

    public function test_zero_approvals_and_duplicate_transfer_lines_are_rejected(): void
    {
        $payload = ['from_branch_id' => Branch::factory()->create()->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => ProductVariant::factory()->create()->id, 'requested_qty' => 1]],
        ];
        $transfer = app(TransferService::class)->create($payload, $this->staff->id);
        $this->api('POST', "transfers/{$transfer->id}/approve", ['items' => [['id' => $transfer->items->first()->id, 'approved_qty' => 0]]])->assertUnprocessable();
        $payload['items'][] = $payload['items'][0];
        $this->api('POST', 'transfers', $payload)->assertUnprocessable();
        $this->api('PATCH', "transfers/{$transfer->id}", ['items' => $payload['items']])->assertUnprocessable();
        $this->assertSame(1, $transfer->items()->count());
    }

    public function test_skipped_item_in_nonempty_shipment_releases_its_entire_reservation(): void
    {
        $from = Branch::factory()->create();
        $variants = ProductVariant::factory()->count(2)->create();
        foreach ($variants as $variant) {
            $this->movement($variant, $from, 10);
        }
        $transfer = app(TransferService::class)->create(['from_branch_id' => $from->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => $variants->map(fn ($variant) => ['product_variant_id' => $variant->id, 'requested_qty' => 2])->all(),
        ], $this->staff->id);
        app(TransferService::class)->approve($transfer, [], $this->staff->id);
        app(TransferService::class)->ship($transfer, [$transfer->items->first()->id => 0], $this->staff->id);
        $this->assertSame(0.0, (float) StockLevel::where('branch_id', $from->id)->sum('reserved'));
        $this->assertSame('10.0000', StockLevel::where('branch_id', $from->id)->where('product_variant_id', $variants->first()->id)->firstOrFail()->on_hand);
    }

    public function test_inactive_groups_and_unapproved_customers_do_not_get_personalized_prices(): void
    {
        $variant = ProductVariant::factory()->create(['retail_price' => 100]);
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'VIP', 'is_active' => false, 'discount_percentage' => 10]);
        $customer = $this->customer($group);
        $groupList = PriceList::create(['name' => 'VIP', 'type' => 'customer_group', 'customer_group_id' => $group->id]);
        $groupList->items()->create(['product_variant_id' => $variant->id, 'price' => 20]);
        $pricing = app(PricingService::class);
        $this->assertSame(100.0, $pricing->resolve($variant, $customer)['unit_price']);
        $specific = PriceList::create(['name' => 'Special', 'type' => 'customer_specific', 'customer_id' => $customer->id]);
        $specific->items()->create(['product_variant_id' => $variant->id, 'price' => 10]);
        foreach ([CustomerStatus::Pending, CustomerStatus::Blocked, CustomerStatus::Rejected] as $status) {
            $customer->update(['status' => $status]);
            $this->assertSame(100.0, $pricing->resolve($variant, $customer)['unit_price']);
        }
    }

    public function test_group_discount_is_a_fallback_without_stacking_on_explicit_group_price(): void
    {
        $variant = ProductVariant::factory()->create(['retail_price' => 100]);
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'VIP', 'discount_percentage' => 10]);
        $customer = $this->customer($group);
        $pricing = app(PricingService::class);
        $this->assertSame(90.0, $pricing->resolve($variant, $customer)['unit_price']);
        $this->assertSame('customer_group_discount', $pricing->resolve($variant, $customer)['source']);
        $list = PriceList::create(['name' => 'VIP', 'type' => 'customer_group', 'customer_group_id' => $group->id]);
        $list->items()->create(['product_variant_id' => $variant->id, 'price' => 80]);
        $this->assertSame(80.0, $pricing->resolve($variant, $customer)['unit_price']);
    }

    public function test_csv_export_neutralizes_formulas_and_roundtrips_safely(): void
    {
        $variant = ProductVariant::factory()->create(['sku' => '=FORMULA']);
        $variant->product->update(['name_ar' => '+FORMULA']);
        $list = PriceList::create(['name' => 'CSV', 'type' => 'retail']);
        $list->items()->create(['product_variant_id' => $variant->id, 'price' => 10]);
        $csv = $this->api('GET', "price-lists/{$list->id}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=FORMULA", $csv);
        $this->assertStringContainsString("'+FORMULA", $csv);
        $report = app(PriceListService::class)->importCsv($list, UploadedFile::fake()->createWithContent('prices.csv', $csv));
        $this->assertSame(1, $report['updated']);
        $this->assertSame(0, $report['skipped']);
    }

    public function test_conflicting_csv_identifiers_and_bad_dates_never_change_prices(): void
    {
        $first = ProductVariant::factory()->create();
        $second = ProductVariant::factory()->create();
        $list = PriceList::create(['name' => 'CSV', 'type' => 'retail']);
        $csv = "sku,variant_id,price,starts_at\n{$second->sku},{$first->id},10,\n{$first->sku},{$first->id},10,not-a-date\n";
        $report = app(PriceListService::class)->importCsv($list, UploadedFile::fake()->createWithContent('prices.csv', $csv));
        $this->assertSame(2, $report['skipped']);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_oversized_csv_is_rejected_before_any_write(): void
    {
        $variant = ProductVariant::factory()->create();
        $list = PriceList::create(['name' => 'CSV', 'type' => 'retail']);
        $file = UploadedFile::fake()->createWithContent('prices.csv', "sku,price\n".str_repeat("{$variant->sku},1\n", 2001));
        Auth::forgetGuards();
        $this->withToken($this->staff->createToken('csv')->plainTextToken)->post("/api/v1/admin/price-lists/{$list->id}/import", ['file' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_archiving_variant_preserves_cost_in_existing_transfer_but_blocks_new_operations(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['cost_price' => 12.5]);
        $this->movement($variant, $branch, 5);
        $transfer = app(TransferService::class)->create(['from_branch_id' => $branch->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ], $this->staff->id);
        $variant->delete();
        $this->api('POST', "transfers/{$transfer->id}/approve")->assertOk();
        $this->api('POST', "transfers/{$transfer->id}/ship")->assertOk();
        $this->assertSame('12.5000', StockMovement::where('type', 'transfer_out')->firstOrFail()->unit_cost);
        $this->adjust($branch, $variant, 'increase', 1)->assertUnprocessable();
        $list = PriceList::create(['name' => 'Retail', 'type' => 'retail']);
        $this->api('POST', "price-lists/{$list->id}/items", ['product_variant_id' => $variant->id, 'price' => 1])->assertUnprocessable();
    }

    public function test_inactive_branches_and_locations_cannot_receive_new_adjustments(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $variant = ProductVariant::factory()->create();
        $this->adjust($branch, $variant, 'increase', 1)->assertUnprocessable();
        $branch->update(['is_active' => true]);
        $location = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => false]);
        $this->api('POST', 'inventory/adjustments', ['branch_id' => $branch->id, 'location_id' => $location->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'adjustment_type' => 'increase']],
        ])->assertUnprocessable();
    }

    public function test_ledger_semantics_reject_wrong_bucket_or_sign(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        foreach ([['sale', 'on_hand', 1], ['reserve', 'on_hand', 1], ['release_reserve', 'reserved', 1]] as [$type, $bucket, $qty]) {
            try {
                app(InventoryService::class)->recordMovement(['product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                    'type' => $type, 'affected_bucket' => $bucket, 'quantity' => $qty, 'created_by' => $this->staff->id]);
                $this->fail('Invalid semantics must be rejected.');
            } catch (BusinessException) {
                $this->assertDatabaseCount('stock_movements', 0);
            }
        }
    }

    public function test_movement_sequence_is_monotonic_across_rollbacks_and_counter_changes(): void
    {
        $settings = app(SettingService::class);
        $before = $settings->get('movement_next_number');
        DB::beginTransaction();
        $first = $settings->generateNextNumber('movement');
        DB::rollBack();
        $second = $settings->generateNextNumber('movement');
        $this->assertNotSame($first, $second);
        $this->assertSame($before + 2, $settings->get('movement_next_number'));
        $settings->updateBatch(['movement_next_number' => $before]);
        $this->assertSame($before + 2, $settings->get('movement_next_number'));
        $settings->updateBatch(['movement_next_number' => $before + 50]);
        $this->assertStringEndsWith((string) ($before + 50), $settings->generateNextNumber('movement'));
    }

    public function test_price_owner_is_validated_by_service_and_cannot_be_orphaned_by_deletion(): void
    {
        try {
            app(PriceListService::class)->create(['name' => 'Missing', 'type' => 'customer_specific']);
            $this->fail('Owner is required by the service.');
        } catch (BusinessException) {
            $this->assertDatabaseCount('price_lists', 0);
        }
        $customer = $this->customer();
        PriceList::create(['name' => 'Owned', 'type' => 'customer_specific', 'customer_id' => $customer->id]);
        try {
            DB::transaction(fn () => $customer->delete());
            $this->fail('Deleting the owner must be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        }
    }

    public function test_stocktake_loads_snapshots_and_variant_costs_in_batches(): void
    {
        $branch = Branch::factory()->create();
        $variants = ProductVariant::factory()->count(12)->create();
        $items = $variants->map(fn ($variant) => ['product_variant_id' => $variant->id, 'counted_qty' => 1])->all();
        DB::enableQueryLog();
        try {
            $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id, 'items' => $items], $this->staff->id);
            $snapshotQueries = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'from "stock_levels"'));
            $this->assertCount(1, $snapshotQueries);
            DB::flushQueryLog();
            app(StocktakeService::class)->approve($stocktake, $this->staff->id);
            $variantQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'from "product_variants"'));
            $this->assertCount(2, $variantQueries);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_movement_date_filter_includes_entire_end_day_and_excludes_next_midnight(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        try {
            // حدود الفلتر بأيام العمل (Africa/Cairo) والتخزين UTC: الحركة الثانية في
            // آخر ثانية من يوم القاهرة، والثالثة في أول ثانية من اليوم التالي (خارج النطاق).
            $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
            $this->movement($variant, $branch, 1);
            $this->travelTo(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-02 23:59:59', 'Africa/Cairo')->utc());
            $this->movement($variant, $branch, 1);
            $this->travelTo(Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-03 00:00:00', 'Africa/Cairo')->utc());
            $this->movement($variant, $branch, 1);
            $this->api('GET', 'inventory/movements?from_date=2026-10-02&to_date=2026-10-02')->assertOk()->assertJsonCount(1, 'data');
        } finally {
            $this->travelBack();
        }
    }

    private function customer(?CustomerGroup $group = null): Customer
    {
        return Customer::create(['name' => 'Buyer', 'email' => uniqid().'@example.com', 'phone' => uniqid(),
            'password' => 'password', 'type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved,
            'customer_group_id' => $group?->id]);
    }

    private function staff(?Branch $branch = null): User
    {
        $staff = User::factory()->create(['branch_id' => $branch?->id]);
        // موظف مرتبط بفرع لا يأخذ امتياز كل الفروع، وإلا انكسر تقييد نطاقه.
        $permissions = Permission::where('guard_name', 'staff')
            ->when($branch !== null, fn ($query) => $query->where('name', '!=', 'inventory.all_branches'))
            ->get();
        $staff->givePermissionTo($permissions);

        return $staff;
    }

    private function movement(ProductVariant $variant, Branch $branch, float $qty): void
    {
        app(InventoryService::class)->recordMovement(['product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => $qty > 0 ? 'adjustment_in' : 'adjustment_out', 'affected_bucket' => 'on_hand', 'quantity' => $qty,
            'created_by' => $this->staff->id]);
    }

    private function adjust(Branch $branch, ProductVariant $variant, string $type, float $qty, ?User $staff = null): TestResponse
    {
        return $this->api('POST', 'inventory/adjustments', ['branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => $type, 'quantity' => $qty]],
        ], $staff);
    }

    private function api(string $method, string $path, array $data = [], ?User $staff = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken(($staff ?? $this->staff)->createToken('review')->plainTextToken)->json($method, '/api/v1/admin/'.$path, $data);
    }
}
