<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Inventory report must not leak unit-cost-derived fields to staff
 * without products.view_cost / inventory.view_cost.
 */
class InventoryReportCostGatingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->branch = Branch::factory()->create();

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 50]);
        StockLevel::forceCreate(['branch_id' => $this->branch->id, 'product_variant_id' => $variant->id, 'on_hand' => 10]);
    }

    private function asUser(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    public function test_inventory_report_hides_cost_fields_without_cost_permission(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->givePermissionTo('reports.view');

        $response = $this->asUser($user)->getJson('/api/v1/admin/reports/inventory?dead_days=90')->assertOk();
        $data = $response->json('data');

        $this->assertArrayNotHasKey('total_value', $data);
        foreach ($data['valuation'] as $row) {
            $this->assertArrayNotHasKey('total_value', $row);
        }
        foreach ($data['dead_stock'] as $row) {
            $this->assertArrayNotHasKey('tied_value', $row);
        }
        // Quantities are not secret and must stay visible.
        $this->assertSame(10.0, (float) $data['valuation'][0]['total_qty']);
    }

    public function test_inventory_report_keeps_cost_fields_with_cost_permission(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->givePermissionTo('reports.view', 'inventory.view_cost');

        $response = $this->asUser($user)->getJson('/api/v1/admin/reports/inventory?dead_days=90')->assertOk();
        $data = $response->json('data');

        $this->assertSame(500.0, (float) $data['total_value']);
        $this->assertSame(500.0, (float) $data['valuation'][0]['total_value']);
        $this->assertSame(500.0, (float) $data['dead_stock'][0]['tied_value']);
    }

    public function test_cost_visible_cache_is_never_served_to_cost_blind_callers(): void
    {
        $viewer = User::factory()->create(['branch_id' => $this->branch->id]);
        $viewer->givePermissionTo('reports.view', 'inventory.view_cost');
        $blind = User::factory()->create(['branch_id' => $this->branch->id]);
        $blind->givePermissionTo('reports.view');

        // Warm the cost-visible entry first.
        $this->asUser($viewer)->getJson('/api/v1/admin/reports/inventory?dead_days=90')->assertOk();

        $data = $this->asUser($blind)->getJson('/api/v1/admin/reports/inventory?dead_days=90')->assertOk()->json('data');
        $this->assertArrayNotHasKey('total_value', $data);
        $this->assertSame(10.0, (float) $data['valuation'][0]['total_qty']);
    }

    public function test_valuation_is_ordered_by_value_with_truncation_flag(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->givePermissionTo('reports.view', 'inventory.view_cost');

        $data = $this->asUser($user)->getJson('/api/v1/admin/reports/inventory?dead_days=90')->assertOk()->json('data');
        $this->assertFalse($data['valuation_truncated']);
        $this->assertSame(500.0, (float) $data['valuation'][0]['total_value']);
    }

    public function test_inventory_cache_is_separate_for_each_branch_and_dead_stock_window(): void
    {
        $otherBranch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        StockLevel::forceCreate(['branch_id' => $otherBranch->id, 'product_variant_id' => $variant->id, 'on_hand' => 3]);
        $first = User::factory()->create(['branch_id' => $this->branch->id]);
        $second = User::factory()->create(['branch_id' => $otherBranch->id]);
        $first->givePermissionTo('reports.view');
        $second->givePermissionTo('reports.view');
        (new StockMovement)->forceFill([
            'reference_number' => 'MOV-DEAD-STOCK-CACHE', 'product_variant_id' => $variant->id,
            'branch_id' => $otherBranch->id, 'type' => 'sale', 'affected_bucket' => 'on_hand',
            'quantity' => -1, 'balance_before' => 4, 'balance_after' => 3, 'created_by' => $second->id,
            'created_at' => now()->subDays(30),
        ])->save();

        $this->asUser($first)->getJson('/api/v1/admin/reports/inventory?dead_days=14')->assertOk()
            ->assertJsonPath('data.valuation.0.branch_id', $this->branch->id);
        $this->asUser($second)->getJson('/api/v1/admin/reports/inventory?dead_days=14')->assertOk()
            ->assertJsonPath('data.valuation.0.branch_id', $otherBranch->id)
            ->assertJsonPath('data.dead_stock.0.product_variant_id', $variant->id);
        $this->asUser($second)->getJson('/api/v1/admin/reports/inventory?dead_days=60')->assertOk()
            ->assertJsonPath('data.valuation.0.branch_id', $otherBranch->id)
            ->assertJsonCount(0, 'data.dead_stock');
    }
}
