<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Actions\Branches\UpdateBranch;
use App\Enums\Inventory\BranchType;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\City;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Stocktake;
use App\Services\Inventory\InventoryService;
use Database\Seeders\InventoryPermissionSeeder;
use Spatie\Permission\Models\Permission;

/**
 * task4 slice: location/branch deactivation guards, inactive-location liquidation,
 * enum path in UpdateBranch, and locations.* permission seeding.
 */
class Task4LocationBranchSliceTest extends TaskTxtInventoryCase
{
    public function test_branch_city_label_tracks_renames_and_deletion(): void
    {
        $city = City::factory()->create(['name_ar' => 'الاسم الأول']);
        $branch = Branch::factory()->create(['city_id' => $city->id, 'city' => $city->name_ar]);
        $city->update(['name_ar' => 'الاسم الجديد']);
        $this->assertSame('الاسم الجديد', $branch->fresh()->city);
        $city->delete();
        $branch->refresh();
        $this->assertNull($branch->city_id);
        $this->assertNull($branch->city);
    }

    private function admin(): array
    {
        $admin = $this->staffWithPermissions([
            'locations.manage', 'branches.manage', 'inventory.all_branches', 'inventory.adjust',
        ]);

        return [$admin, $this->tokenFor($admin)];
    }

    public function test_inventory_permission_seeder_creates_location_permissions(): void
    {
        $this->seed(InventoryPermissionSeeder::class);

        foreach (['view', 'create', 'update', 'delete', 'manage'] as $action) {
            $this->assertTrue(
                Permission::query()->where('name', "locations.$action")->where('guard_name', 'staff')->exists(),
                "locations.$action missing"
            );
        }
    }

    public function test_location_cannot_be_deactivated_while_open_transfer_references_it(): void
    {
        [$admin, $token] = $this->admin();
        $from = Branch::factory()->create(['is_active' => true]);
        $to = Branch::factory()->create(['is_active' => true]);
        $location = $to->locations()->create(['name' => 'Dest', 'code' => 'D-1', 'type' => 'receiving']);
        $variant = ProductVariant::factory()->create();
        $transfer = StockTransfer::query()->create([
            'transfer_number' => 'TR-T4-1', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'status' => 'approved', 'created_by' => $admin->id,
        ]);
        $transfer->items()->create([
            'product_variant_id' => $variant->id, 'to_location_id' => $location->id,
            'requested_qty' => 1, 'approved_qty' => 1,
        ]);

        $uri = "/api/v1/admin/branches/{$to->id}/locations/{$location->id}";
        $this->requestWithToken($token, 'PATCH', $uri, ['is_active' => false])->assertStatus(422);
        $this->requestWithToken($token, 'PATCH', $uri, ['name' => 'Renamed', 'is_active' => false])->assertStatus(422);
        $this->assertTrue((bool) $location->fresh()->is_active);
    }

    public function test_location_cannot_be_deactivated_while_open_stocktake_targets_it(): void
    {
        [$admin, $token] = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $location = $branch->locations()->create(['name' => 'Aisle', 'code' => 'A-1', 'type' => 'shelf']);
        Stocktake::query()->create([
            'stocktake_number' => 'ST-T4-1', 'branch_id' => $branch->id, 'location_id' => $location->id,
            'status' => 'in_progress', 'created_by' => $admin->id,
        ]);

        $uri = "/api/v1/admin/branches/{$branch->id}/locations/{$location->id}";
        $this->requestWithToken($token, 'PATCH', $uri, ['is_active' => false])->assertStatus(422);
        $this->requestWithToken($token, 'PATCH', $uri, ['name' => 'X', 'is_active' => false])->assertStatus(422);
        $this->assertTrue((bool) $location->fresh()->is_active);
    }

    public function test_branch_cannot_be_deactivated_while_open_stocktake_exists(): void
    {
        [$admin] = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        Stocktake::query()->create([
            'stocktake_number' => 'ST-T4-2', 'branch_id' => $branch->id,
            'status' => 'draft', 'created_by' => $admin->id,
        ]);

        $this->expectException(BusinessException::class);
        app(UpdateBranch::class)($admin, $branch, ['is_active' => false]);
    }

    public function test_update_branch_accepts_branch_type_enum_instance(): void
    {
        [$admin] = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true, 'type' => 'branch']);

        $updated = app(UpdateBranch::class)($admin, $branch, ['type' => BranchType::Warehouse]);

        $this->assertSame('warehouse', $updated->fresh()->type->value);
    }

    public function test_outbound_movement_is_allowed_on_inactive_location_to_liquidate_stock(): void
    {
        [$admin] = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $location = $branch->locations()->create(['name' => 'Old', 'code' => 'O-1', 'type' => 'shelf']);
        $variant = ProductVariant::factory()->create();
        $service = app(InventoryService::class);

        $service->recordMovement([
            'branch_id' => $branch->id, 'location_id' => $location->id, 'product_variant_id' => $variant->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5, 'created_by' => $admin->id,
        ]);
        $location->forceFill(['is_active' => false])->save();

        $movement = $service->recordMovement([
            'branch_id' => $branch->id, 'location_id' => $location->id, 'product_variant_id' => $variant->id,
            'type' => StockMovementType::AdjustmentOut, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => -5, 'created_by' => $admin->id,
        ]);

        $this->assertSame(0.0, (float) $movement->balance_after);
    }

    public function test_inbound_movement_is_still_rejected_on_inactive_location(): void
    {
        [$admin] = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $location = $branch->locations()->create(['name' => 'Off', 'code' => 'F-1', 'type' => 'shelf', 'is_active' => false]);
        $variant = ProductVariant::factory()->create();

        $this->expectException(BusinessException::class);
        app(InventoryService::class)->recordMovement([
            'branch_id' => $branch->id, 'location_id' => $location->id, 'product_variant_id' => $variant->id,
            'type' => StockMovementType::AdjustmentIn, 'affected_bucket' => StockBucket::OnHand,
            'quantity' => 1, 'created_by' => $admin->id,
        ]);
    }
}
