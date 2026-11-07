<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Branches\UpdateBranch;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Disabling a branch with approved/shipped transfers to or from it, or
 * with non-zero balances, must be refused — otherwise reservations freeze
 * (cancel path locked) and goods stay "in transit" forever.
 */
class BranchDeactivationGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->admin = User::factory()->create();
        foreach (['branches.update', 'branches.manage', 'inventory.all_branches'] as $p) {
            $this->admin->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
    }

    private function deactivate(Branch $branch): Branch
    {
        return app(UpdateBranch::class)($this->admin, $branch, ['is_active' => false]);
    }

    public function test_approved_transfer_from_branch_blocks_deactivation(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        StockTransfer::create([
            'transfer_number' => 'TR-M4-A', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'status' => 'approved', 'created_by' => $this->admin->id,
        ]);

        try {
            $this->deactivate($from);
            $this->fail('Expected branch deactivation to be refused.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('تحويلات', $e->getMessage());
        }
        $this->assertTrue((bool) $from->fresh()->is_active);
    }

    public function test_shipped_transfer_to_branch_blocks_deactivation(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        StockTransfer::create([
            'transfer_number' => 'TR-M4-S', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'status' => 'shipped', 'created_by' => $this->admin->id,
        ]);

        try {
            $this->deactivate($to);
            $this->fail('Expected branch deactivation to be refused.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('تحويلات', $e->getMessage());
        }
        $this->assertTrue((bool) $to->fresh()->is_active);
    }

    public function test_nonzero_balance_blocks_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand', 'quantity' => 3, 'created_by' => $this->admin->id,
        ]);

        try {
            $this->deactivate($branch);
            $this->fail('Expected branch deactivation to be refused.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('أرصدة', $e->getMessage());
        }
        $this->assertTrue((bool) $branch->fresh()->is_active);
    }

    public function test_clean_branch_deactivates(): void
    {
        $branch = Branch::factory()->create();
        $this->deactivate($branch);
        $this->assertFalse((bool) $branch->fresh()->is_active);
    }
}
