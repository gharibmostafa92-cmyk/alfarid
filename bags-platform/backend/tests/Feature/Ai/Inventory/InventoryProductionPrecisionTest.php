<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Inventory;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Inventory\InventoryService;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryProductionPrecisionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function proposal(array $items): array
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_inventory_tools_enabled'])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $actor = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'inventory.view_cost', 'inventory.all_branches'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 50]);
        $branch = Branch::factory()->create();
        $conversation = AiConversation::create(['user_id' => $actor->id, 'model' => 'test']);
        $result = app(ToolRegistry::class)->dispatch($actor, $conversation, ['name' => 'receive_production', 'args' => [
            'branch_id' => $branch->id, 'batch_number' => 'PRECISION-1',
            'items' => array_map(fn ($item) => ['variant_id' => $variant->id, ...$item], $items),
        ]]);
        $this->assertSame('proposal', $result->type);

        return [$actor, $variant, $result, AiPendingAction::findOrFail($result->data['pending_action_id'])];
    }

    public function test_duplicate_production_lines_use_exact_merged_cost_in_preview_and_execution(): void
    {
        [$actor, $variant, $result, $action] = $this->proposal([
            ['quantity' => 1, 'unit_cost' => '10.0001'], ['quantity' => 2, 'unit_cost' => '10.0002'], ['quantity' => 3, 'unit_cost' => '10.0003'],
        ]);
        $this->assertCount(1, $result->uiBlock['rows']);
        $this->assertSame(6, $result->uiBlock['rows'][0]['quantity']);
        $this->assertSame(10.0002, $result->uiBlock['rows'][0]['unit_cost']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($actor, $action)->type);
        $this->assertSame('10.0002', $variant->fresh()->cost_price);
    }

    public function test_revoked_cost_access_blocks_execution_of_an_existing_receipt_proposal(): void
    {
        [$actor, , , $action] = $this->proposal([['quantity' => 1, 'unit_cost' => 10]]);
        $actor->revokePermissionTo('inventory.view_cost');
        $outcome = app(PendingActionService::class)->confirm($actor->fresh(), $action);
        $this->assertSame('failed', $outcome->type);
        $this->assertDatabaseCount('production_receipts', 0);
    }

    public function test_stock_movement_after_cost_preview_requires_a_new_proposal(): void
    {
        [$actor, $variant, , $action] = $this->proposal([['quantity' => 10, 'unit_cost' => 100]]);
        app(InventoryService::class)->applyAdjustments([
            'branch_id' => $action->args['branch_id'],
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 5, 'reason' => 'عينة']],
        ], $actor->id, 'precision-stock-change');
        $this->assertSame((int) $action->expected_versions['variant:'.$variant->id], (int) $variant->fresh()->lock_version);
        try {
            app(PendingActionService::class)->confirm($actor, $action);
            $this->fail('A changed stock denominator must invalidate the reviewed cost preview.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('production_receipts', 0);
    }
}
