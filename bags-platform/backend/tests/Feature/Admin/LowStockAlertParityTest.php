<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * L6: the low_stock list filter must match alert conditions — a variant
 * threshold compares against the branch total, not each row.
 */
class LowStockAlertParityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->staff = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->staff->givePermissionTo(Permission::findOrCreate('inventory.view', 'staff'));
    }

    private function list(): array
    {
        Auth::forgetGuards();

        return $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->getJson('/api/v1/admin/inventory?low_stock=1&per_page=100')
            ->assertOk()
            ->json('data');
    }

    public function test_variant_threshold_uses_branch_total_not_rows(): void
    {
        $variant = ProductVariant::factory()->create(['reorder_level' => 10]);
        foreach (range(1, 3) as $i) {
            $location = Location::factory()->create(['branch_id' => $this->branch->id, 'code' => 'L6-'.$i]);
            app(InventoryService::class)->recordMovement([
                'product_variant_id' => $variant->id, 'branch_id' => $this->branch->id,
                'location_id' => $location->id, 'type' => 'adjustment_in',
                'affected_bucket' => 'on_hand', 'quantity' => 5, 'created_by' => $this->staff->id,
            ]);
        }

        // 5 per row but 15 branch-wide: no alert, so the list must be empty.
        $this->assertCount(0, $this->list());
    }

    public function test_row_threshold_still_flags_its_own_row(): void
    {
        $variant = ProductVariant::factory()->create(['reorder_level' => 0]);
        $location = Location::factory()->create(['branch_id' => $this->branch->id, 'code' => 'L6-R']);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $this->branch->id,
            'location_id' => $location->id, 'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand', 'quantity' => 5, 'created_by' => $this->staff->id,
        ]);
        \App\Models\StockLevel::query()
            ->where('branch_id', $this->branch->id)
            ->where('product_variant_id', $variant->id)
            ->update(['reorder_level' => 6]);

        $rows = $this->list();
        $this->assertCount(1, $rows);
        $this->assertSame($variant->id, (int) $rows[0]['product_variant_id']);
    }
}
