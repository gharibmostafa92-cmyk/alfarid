<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Events\StockLowDetected;
use App\Listeners\SuggestTransferRedistribution;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\InventoryReconcileRun;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Notifications\InventoryAnomalyDigestNotification;
use App\Notifications\TransferSuggestionNotification;
use App\Services\Ai\DomainRouter;
use App\Services\Ai\Inventory\InventoryToolProvider;
use App\Services\Ai\Inventory\InventoryWelcome;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolRegistry;
use App\Services\Inventory\InventoryService;
use App\Services\Finance\ReportService;
use App\Services\Shared\SettingService;
use App\Services\Shared\SpokenQuantity;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryAiToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_inventory_tools_enabled',
            'ai_action_ttl_minutes',
        ])->update(['value' => '1']);
        DB::table('settings')->where('key', 'ai_action_ttl_minutes')->update(['value' => '10']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_inventory_tools_are_registered_under_inventory_domain(): void
    {
        $defs = app(ToolRegistry::class)->definitions();
        foreach (['get_stock_levels', 'stock_matrix', 'list_movements', 'explain_balance', 'low_stock_list', 'dead_stock', 'inventory_valuation', 'list_transfers', 'get_transfer', 'list_stocktakes', 'get_stocktake', 'list_production_receipts', 'inventory_health'] as $tool) {
            $this->assertArrayHasKey($tool, $defs);
            $this->assertInstanceOf(InventoryToolProvider::class, $defs[$tool]['provider']);
        }
        $provider = app(InventoryToolProvider::class);
        $this->assertSame('inventory', $provider->domain());
        $this->assertFalse($provider->usesGlobalAdminLock());
    }

    public function test_inventory_keywords_route_to_inventory_domain(): void
    {
        $router = app(DomainRouter::class);
        $this->assertContains('inventory', $router->route('رصيد المخزن الرئيسي'));
        $this->assertContains('admin', $router->route('رصيد المخزن الرئيسي'));
        $this->assertContains('inventory', $router->route('التحويل اللي اتشحن امبارح وصل؟'));
        $this->assertContains('inventory', $router->route('عايز جرد فرع المعادي'));
    }

    public function test_get_stock_levels_returns_buckets(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'location_id' => null,
            'on_hand' => 10,
            'reserved' => 2,
            'damaged' => 1,
            'in_transit' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'get_stock_levels',
            'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $level = $outcome->data['levels'][0];
        $this->assertSame(10.0, (float) $level['on_hand']);
        $this->assertSame(7.0, (float) $level['available']);
        $this->assertSame(5.0, (float) $level['in_transit']);
    }

    public function test_cost_is_stripped_without_view_permission(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        StockMovement::create([
            'reference_number' => 'MOV-TEST-1',
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::Sale,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => -2,
            'balance_before' => 10,
            'balance_after' => 8,
            'unit_cost' => 150,
            'created_by' => $admin->id,
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'list_movements',
            'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['movements']);
        $this->assertArrayNotHasKey('unit_cost', $outcome->data['movements'][0]);
        $this->assertNotContains('تكلفة الوحدة', $outcome->uiBlock['columns']);
    }

    public function test_cost_is_masked_from_model_but_visible_in_ui_block(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.view_cost', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        StockMovement::create([
            'reference_number' => 'MOV-TEST-2',
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::Sale,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => -1,
            'balance_before' => 8,
            'balance_after' => 7,
            'unit_cost' => 150,
            'created_by' => $admin->id,
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'list_movements',
            'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertNull($outcome->data['movements'][0]['unit_cost']);
        $this->assertTrue($outcome->data['cost_hidden_from_model']);
        $this->assertContains('تكلفة الوحدة', $outcome->uiBlock['columns']);
        $this->assertContains('150', $outcome->uiBlock['rows'][0]);
    }

    public function test_every_inventory_tool_has_eval_coverage(): void
    {
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/ai-agent-egyptian.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(220, count($dataset['cases']));
        $byTool = [];
        foreach ($dataset['cases'] as $case) {
            if ($case['tool'] !== null) {
                $byTool[$case['tool']][] = $case['id'];
            }
        }
        $defs = app(ToolRegistry::class)->definitions();
        foreach (app(InventoryToolProvider::class)->tools() as $name => $tool) {
            $this->assertArrayHasKey($name, $defs);
            $this->assertNotEmpty($byTool[$name] ?? [], "inventory tool {$name} has no eval cases");
        }
    }

    public function test_adjust_stock_prepare_execute_and_undo(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'location_id' => null,
            'on_hand' => 10,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $conv = $this->conv($admin);

        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'adjust_stock',
            'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id, 'adjustment_type' => 'decrease', 'quantity' => 3, 'reason' => 'تالف'],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $this->assertSame('stock_diff', $proposal->uiBlock['type']);

        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $result = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $result->type);
        $level = StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $branch->id)->first();
        $this->assertSame(7.0, (float) $level->on_hand);

        $undone = app(PendingActionService::class)->undo($admin, $action->fresh());
        $this->assertSame('result', $undone->type);
        $level = StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $branch->id)->first();
        $this->assertSame(10.0, (float) $level->on_hand);
    }

    public function test_adjust_stock_requires_reason_picker(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'adjust_stock',
            'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id, 'adjustment_type' => 'decrease', 'quantity' => 3],
        ]);

        $this->assertSame('picker', $outcome->type);
    }

    public function test_adjust_stock_rejects_below_available(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.adjust', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'location_id' => null,
            'on_hand' => 3,
            'reserved' => 2,
            'damaged' => 0,
            'in_transit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'adjust_stock',
            'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id, 'adjustment_type' => 'decrease', 'quantity' => 3, 'reason' => 'فقد'],
        ]);

        $this->assertSame('denied', $outcome->type);
    }

    public function test_receive_production_prepare_execute_and_duplicate_batch(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'inventory.view_cost', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['cost_price' => 100, 'last_cost_price' => 100, 'is_active' => true]);
        $conv = $this->conv($admin);

        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'receive_production',
            'args' => ['branch_id' => $branch->id, 'batch_number' => 'BATCH-AI-1', 'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 110]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $this->assertSame('cost_impact', $proposal->uiBlock['type']);

        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $result = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $result->type);

        $variant->refresh();
        $this->assertEqualsWithDelta(110, (float) $variant->cost_price, 0.01);

        $retry = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'receive_production',
            'args' => ['branch_id' => $branch->id, 'batch_number' => 'batch-ai-1', 'items' => [['variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 100]]],
        ]);
        $this->assertSame('denied', $retry->type);
    }

    public function test_receive_production_denied_without_cost_access(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.adjust', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'receive_production',
            'args' => ['branch_id' => $branch->id, 'batch_number' => 'BATCH-X', 'items' => [['variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 50]]],
        ]);

        $this->assertSame('denied', $outcome->type);
    }

    private function transferStaff(): User
    {
        return $this->staff(['ai_agent.use', 'ai_agent.execute', 'transfers.create', 'transfers.update', 'transfers.approve', 'transfers.ship', 'transfers.receive', 'transfers.view', 'inventory.view', 'inventory.all_branches']);
    }

    private function seedTransferStock(Branch $from, ProductVariant $variant, float $qty = 20): void
    {
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id,
            'branch_id' => $from->id,
            'location_id' => null,
            'on_hand' => $qty,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDraft(User $admin, Branch $from, Branch $to, ProductVariant $variant, float $qty = 10): StockTransfer
    {
        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'create_transfer_draft',
            'args' => ['from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'items' => [['variant_id' => $variant->id, 'quantity' => $qty]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $result = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $result->type);

        return StockTransfer::findOrFail($result->data['transfer_id']);
    }

    public function test_transfer_full_lifecycle_with_shortage(): void
    {
        $admin = $this->transferStaff();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $this->seedTransferStock($from, $variant, 20);

        $transfer = $this->createDraft($admin, $from, $to, $variant, 10);
        $this->assertSame('draft', $transfer->status->value);
        $itemId = $transfer->items()->first()->id;

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'approve_transfer',
            'args' => ['transfer_id' => $transfer->id, 'items' => [['item_id' => $itemId, 'quantity' => 10]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $action)->type);
        $this->assertSame('approved', $transfer->fresh()->status->value);
        $reserved = (float) StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $from->id)->value('reserved');
        $this->assertSame(10.0, $reserved);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'ship_transfer',
            'args' => ['transfer_id' => $transfer->id, 'items' => [['item_id' => $itemId, 'quantity' => 10]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $action)->type);
        $this->assertSame('shipped', $transfer->fresh()->status->value);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'receive_transfer',
            'args' => ['transfer_id' => $transfer->id, 'items' => [['item_id' => $itemId, 'quantity' => 8]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $this->assertStringContainsString('عجز', (string) json_encode($proposal->uiBlock, JSON_UNESCAPED_UNICODE));
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $action)->type);
        $this->assertSame('received', $transfer->fresh()->status->value);
        $dest = (float) StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $to->id)->value('on_hand');
        $this->assertSame(8.0, $dest);
        $this->assertTrue(StockMovement::where('type', StockMovementType::TransferShortage)->where('reference_id', $transfer->id)->exists());
        $timeline = \App\Services\Ai\Inventory\TransferTimeline::build($transfer->fresh());
        $this->assertCount(4, $timeline);
        foreach ($timeline as $stage) {
            $this->assertTrue($stage['done']);
            $this->assertSame($admin->name, $stage['actor']);
            $this->assertNotEmpty($stage['at']);
        }
    }

    public function test_transfer_create_undo_and_cancel_approved(): void
    {
        $admin = $this->transferStaff();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $this->seedTransferStock($from, $variant, 20);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'create_transfer_draft',
            'args' => ['from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'items' => [['variant_id' => $variant->id, 'quantity' => 5]]],
        ]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $created = app(PendingActionService::class)->confirm($admin, $action);
        $transferId = $created->data['transfer_id'];
        $undone = app(PendingActionService::class)->undo($admin, $action->fresh());
        $this->assertSame('result', $undone->type);
        $this->assertNull(StockTransfer::find($transferId));

        $transfer = $this->createDraft($admin, $from, $to, $variant, 6);
        $itemId = $transfer->items()->first()->id;
        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'approve_transfer',
            'args' => ['transfer_id' => $transfer->id, 'items' => [['item_id' => $itemId, 'quantity' => 6]]],
        ]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        app(PendingActionService::class)->confirm($admin, $action);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'cancel_transfer',
            'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $action)->type);
        $this->assertSame('cancelled', $transfer->fresh()->status->value);
        $reserved = (float) StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $from->id)->value('reserved');
        $this->assertSame(0.0, $reserved);
    }

    public function test_transfer_wrong_state_denials(): void
    {
        $admin = $this->transferStaff();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $this->seedTransferStock($from, $variant, 20);

        $transfer = $this->createDraft($admin, $from, $to, $variant, 4);

        $ship = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'ship_transfer',
            'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertSame('denied', $ship->type);

        $itemId = $transfer->items()->first()->id;
        foreach (['approve_transfer', 'ship_transfer'] as $tool) {
            $conv = $this->conv($admin);
            $key = $tool === 'approve_transfer' ? ['transfer_id' => $transfer->id, 'items' => [['item_id' => $itemId, 'quantity' => 4]]] : ['transfer_id' => $transfer->id];
            $p = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => $tool, 'args' => $key]);
            $this->assertSame('proposal', $p->type);
            $a = AiPendingAction::findOrFail($p->data['pending_action_id']);
            $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $a)->type);
        }
        $this->assertSame('shipped', $transfer->fresh()->status->value);

        $cancel = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'cancel_transfer',
            'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertSame('denied', $cancel->type);
        $this->assertStringContainsString('اتشحن', $cancel->message);
    }

    public function test_stocktake_tools_do_not_reveal_open_counts_to_non_approvers(): void
    {
        $admin = $this->stocktakeStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $stocktake = $this->openStocktake($admin, $branch, $variant, 2);
        $counter = $this->staff(['ai_agent.use', 'stocktakes.view', 'inventory.all_branches']);
        $details = app(ToolRegistry::class)->dispatch($counter, $this->conv($counter), [
            'name' => 'get_stocktake', 'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $this->assertSame('data', $details->type);
        $this->assertArrayNotHasKey('system_qty', $details->data['items'][0]);
        $this->assertArrayNotHasKey('difference', $details->data['items'][0]);
        $this->assertSame(['الصنف', 'العد'], $details->uiBlock['columns']);
        $variance = app(ToolRegistry::class)->dispatch($counter, $this->conv($counter), [
            'name' => 'stocktake_variance', 'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $this->assertSame('denied', $variance->type);
    }

    public function test_what_if_cost_stays_in_ui_when_model_sharing_is_disabled(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.view_cost', 'inventory.all_branches']);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 123]);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'what_if_production_cost',
            'args' => ['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 150],
        ]);
        $this->assertSame('data', $outcome->type);
        foreach (['unit_cost', 'cost_before', 'cost_after'] as $key) {
            $this->assertNull($outcome->data[$key]);
            $this->assertIsFloat($outcome->uiBlock['rows'][0][$key]);
        }
    }

    public function test_variance_value_is_hidden_without_cost_permission(): void
    {
        $admin = $this->stocktakeStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 123]);
        $stocktake = $this->openStocktake($admin, $branch, $variant, 2);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'stocktake_variance', 'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $this->assertSame('data', $outcome->type);
        $this->assertArrayNotHasKey('value_impact', $outcome->data['top_by_value'][0]);
    }

    public function test_write_off_damaged_can_use_all_damaged_stock_and_be_undone(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 10]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => null,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 10, 'in_transit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'adjust_stock', 'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id,
                'adjustment_type' => 'write_off_damaged', 'quantity' => 10, 'reason' => 'تالف'],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $service = app(PendingActionService::class);
        $this->assertSame('result', $service->confirm($actor, $action)->type);
        $this->assertSame('result', $service->undo($actor, $action->fresh())->type);
        $level = StockLevel::where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertSame(10.0, (float) $level->on_hand);
        $this->assertSame(10.0, (float) $level->damaged);
    }

    public function test_high_value_adjustment_requires_typed_confirmation_without_disclosing_cost(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 60001]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'adjust_stock', 'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id,
                'adjustment_type' => 'increase', 'quantity' => 1, 'reason' => 'خطأ إدخال'],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $this->assertSame('high', $proposal->uiBlock['risk']);
        $this->assertNotEmpty($proposal->uiBlock['typed_confirmation'] ?? null);
        $this->assertStringNotContainsString('60001', json_encode($proposal->uiBlock));
    }

    public function test_matrix_sums_locations_and_low_stock_uses_branch_totals_and_net_sales(): void
    {
        $actor = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'reorder_level' => 10]);
        foreach ([3, 4] as $qty) {
            $location = Location::factory()->create(['branch_id' => $branch->id]);
            DB::table('stock_levels')->insert([
                'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
                'on_hand' => $qty, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['sale' => -30, 'sale_return' => 15] as $type => $quantity) {
            StockMovement::create([
                'reference_number' => 'NET-'.$type, 'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => $type, 'affected_bucket' => 'on_hand', 'quantity' => $quantity,
                'balance_before' => 100, 'balance_after' => 100 + $quantity, 'created_by' => $actor->id,
            ]);
        }
        $matrix = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'stock_matrix', 'args' => ['product_id' => $variant->product_id],
        ]);
        $this->assertSame(7.0, (float) $matrix->data['rows'][0]['cells'][$branch->id]['available']);
        $low = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'low_stock_list', 'args' => ['branch_id' => $branch->id],
        ]);
        $this->assertCount(1, $low->data['low_stock']);
        $this->assertSame(7.0, $low->data['low_stock'][0]['available']);
        $this->assertSame(14.0, $low->data['low_stock'][0]['coverage_days']);
    }

    public function test_welcome_does_not_show_dead_stock_value_without_cost_access(): void
    {
        $actor = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $reports = \Mockery::mock(ReportService::class);
        $reports->shouldReceive('inventoryReport')->andReturn(['dead_stock' => [['tied_value' => 60001]]]);
        $this->app->instance(ReportService::class, $reports);
        $card = app(InventoryWelcome::class)->card($actor);
        $this->assertStringNotContainsString('60001', json_encode($card));
    }

    public function test_inventory_only_staff_can_resolve_variant_mentions_with_catalog_disabled(): void
    {
        DB::table('settings')->where('key', 'ai_catalog_tools_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $actor = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $this->assertTrue(app(ToolRegistry::class)->canReadEntity($actor, 'variant'));
        $this->assertFalse(app(ToolRegistry::class)->canReadEntity($actor, 'product'));
    }

    public function test_fractional_production_quantity_is_rejected_instead_of_truncated(): void
    {
        $actor = $this->staff(['ai_agent.use', 'inventory.adjust', 'inventory.view_cost', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'receive_production', 'args' => ['branch_id' => $branch->id, 'batch_number' => 'FRACTION',
                'items' => [['variant_id' => $variant->id, 'quantity' => 1.5, 'unit_cost' => 10]]],
        ]);
        $this->assertSame('denied', $proposal->type);
        $this->assertDatabaseCount('production_receipts', 0);
    }

    public function test_balance_story_does_not_mix_reservations_with_physical_balances(): void
    {
        $actor = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        foreach ([['sale', 'on_hand', -3, 10, 7], ['reserve', 'reserved', 2, 0, 2]] as $i => [$type, $bucket, $quantity, $before, $after]) {
            \App\Models\StockMovement::create([
                'reference_number' => 'STORY-'.$i, 'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => $type, 'affected_bucket' => $bucket, 'quantity' => $quantity,
                'balance_before' => $before, 'balance_after' => $after, 'created_by' => $actor->id,
            ]);
        }
        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'explain_balance', 'args' => ['variant_id' => $variant->id, 'branch_id' => $branch->id],
        ]);
        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->uiBlock['timeline']);
        $this->assertSame(10.0, $outcome->uiBlock['start_balance']);
        $this->assertSame(7.0, $outcome->uiBlock['timeline'][0]['balance_after']);
    }

    public function test_what_if_transfer_cannot_expose_another_branchs_balance(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $actor = $this->staff(['ai_agent.use', 'inventory.view']);
        $actor->update(['branch_id' => $branch->id]);
        $variant = ProductVariant::factory()->create();
        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'what_if_transfer_coverage', 'args' => ['variant_id' => $variant->id,
                'from_branch_id' => $other->id, 'to_branch_id' => $branch->id, 'quantity' => 1],
        ]);
        $this->assertSame('denied', $outcome->type);
        $this->assertArrayNotHasKey('donor_available', $outcome->data);
    }

    private function stocktakeStaff(): User
    {
        return $this->staff(['ai_agent.use', 'ai_agent.execute', 'stocktakes.create', 'stocktakes.update', 'stocktakes.approve', 'stocktakes.view', 'inventory.view', 'inventory.all_branches']);
    }

    private function openStocktake(User $admin, Branch $branch, ProductVariant $variant, ?float $counted = null): Stocktake
    {
        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'create_stocktake',
            'args' => ['branch_id' => $branch->id, 'items' => [['variant_id' => $variant->id, 'counted_qty' => $counted]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $result = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $result->type);

        return Stocktake::findOrFail($result->data['stocktake_id']);
    }

    public function test_stocktake_lifecycle_with_typed_approval(): void
    {
        $admin = $this->stocktakeStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 100]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => null,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stocktake = $this->openStocktake($admin, $branch, $variant);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'record_counts',
            'args' => ['stocktake_id' => $stocktake->id, 'items' => [['variant_id' => $variant->id, 'counted_qty' => 8]]],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $action)->type);

        $variance = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'stocktake_variance',
            'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $this->assertSame('data', $variance->type);
        $this->assertSame(-2.0, (float) $variance->data['top_by_value'][0]['difference']);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'approve_stocktake',
            'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $this->assertStringContainsString('رقم الجرد', (string) ($proposal->uiBlock['typed_confirmation_hint'] ?? ''));
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        try {
            app(PendingActionService::class)->confirm($admin, $action);
            $this->fail('التأكيد بدون كتابة رقم الجرد لازم يترفض.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $result = app(PendingActionService::class)->confirm($admin, $action->fresh(), $stocktake->fresh()->stocktake_number);
        $this->assertSame('result', $result->type);
        $this->assertSame('approved', $stocktake->fresh()->status->value);
        $level = (float) StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $branch->id)->value('on_hand');
        $this->assertSame(8.0, $level);
    }

    public function test_approve_stocktake_with_stale_snapshot_returns_recount_checklist(): void
    {
        $admin = $this->stocktakeStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 50]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => null,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stocktake = $this->openStocktake($admin, $branch, $variant, 10);

        app(InventoryService::class)->applyAdjustments([
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 5, 'reason' => 'عينة']],
        ], $admin->id);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'approve_stocktake',
            'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $result = app(PendingActionService::class)->confirm($admin, $action->fresh(), $stocktake->fresh()->stocktake_number);
        $this->assertSame('failed', $result->type);
        $this->assertContains($variant->id, $result->data['recount_variant_ids']);
        $this->assertSame('count_sheet', $result->uiBlock['type']);
    }

    public function test_cancel_stocktake(): void
    {
        $admin = $this->stocktakeStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        $stocktake = $this->openStocktake($admin, $branch, $variant);

        $conv = $this->conv($admin);
        $proposal = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'cancel_stocktake',
            'args' => ['stocktake_id' => $stocktake->id],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('result', app(PendingActionService::class)->confirm($admin, $action)->type);
        $this->assertSame('cancelled', $stocktake->fresh()->status->value);
    }

    public function test_parse_count_sheet_builds_record_counts_proposal(): void
    {
        DB::table('settings')->where('key', 'ai_inventory_vision_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $admin = $this->stocktakeStaff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sku' => 'P00042-BRN']);
        $stocktake = $this->openStocktake($admin, $branch, $variant);
        $conv = $this->conv($admin);
        $fakeImage = UploadedFile::fake()->image('s1.jpg');
        Storage::disk('ai_private')->put('sheets/s1.jpg', $fakeImage->getContent());
        $att = AiAttachment::create([
            'conversation_id' => $conv->id, 'user_id' => $admin->id,
            'path' => 'sheets/s1.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'sha256' => 'x',
        ]);
        Http::fake(['*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"rows":[{"code":"P00042-BRN","quantity":7,"confidence":0.5},{"code":"UNKNOWN-X","quantity":2,"confidence":0.9}]}']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
        ], 200)]);

        app(ProposalBuilder::class)->setConversation($conv);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'parse_count_sheet',
            'args' => ['stocktake_id' => $stocktake->id, 'attachment_ids' => [$att->id]],
        ]);

        $this->assertSame('proposal', $outcome->type);
        $created = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame('record_counts', $created->tool);
        $this->assertSame('count_sheet', $outcome->uiBlock['type']);
        $low = array_filter($outcome->uiBlock['rows'], fn ($r) => ($r['confidence'] ?? 1) < 0.8);
        $this->assertNotEmpty($low);
    }

    public function test_spoken_quantity_parses_egyptian_words(): void
    {
        $this->assertSame(1.0, SpokenQuantity::parse('واحد'));
        $this->assertSame(2.0, SpokenQuantity::parse('اتنين'));
        $this->assertSame(3.0, SpokenQuantity::parse('تلاتة'));
        $this->assertSame(22.0, SpokenQuantity::parse('اتنين وعشرين'));
        $this->assertSame(105.0, SpokenQuantity::parse('مية وخمسة'));
        $this->assertSame(25.0, SpokenQuantity::parse('٢٥'));
        $this->assertSame(12.0, SpokenQuantity::parse('دستة'));
        $this->assertNull(SpokenQuantity::parse('كلام فاضي'));
        $this->assertNull(SpokenQuantity::parse(''));
    }

    private function saleMovements(ProductVariant $variant, Branch $branch, int $days, float $daily, int $by): void
    {
        for ($d = 0; $d < $days; $d++) {
            StockMovement::create([
                'reference_number' => 'POS-'.$variant->id.'-'.$branch->id.'-'.$d,
                'product_variant_id' => $variant->id,
                'branch_id' => $branch->id,
                'type' => StockMovementType::Sale,
                'affected_bucket' => StockBucket::OnHand,
                'quantity' => -$daily,
                'balance_before' => 1000,
                'balance_after' => 1000 - $daily,
                'created_by' => $by,
                'created_at' => now()->subDays($d),
            ]);
        }
    }

    public function test_redistribution_suggestion_fires_when_proactive_enabled(): void
    {
        $manager = $this->staff(['ai_agent.use', 'transfers.create', 'inventory.view', 'inventory.all_branches']);
        $low = Branch::factory()->create();
        $donor = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sku' => 'P-SUGGEST']);
        DB::table('stock_levels')->insert([
            ['product_variant_id' => $variant->id, 'branch_id' => $low->id, 'location_id' => null, 'on_hand' => 5, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_variant_id' => $variant->id, 'branch_id' => $donor->id, 'location_id' => null, 'on_hand' => 200, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->saleMovements($variant, $low, 30, 2, $manager->id);
        $this->saleMovements($variant, $donor, 30, 1, $manager->id);

        app(SuggestTransferRedistribution::class)->handle(
            new StockLowDetected($variant->id, $low->id, 5.0, 10.0)
        );
        $this->assertDatabaseMissing('notifications', ['type' => TransferSuggestionNotification::class]);

        DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        app(SuggestTransferRedistribution::class)->handle(
            new StockLowDetected($variant->id, $low->id, 5.0, 10.0)
        );
        $this->assertDatabaseHas('notifications', ['type' => TransferSuggestionNotification::class]);
        $note = DatabaseNotification::firstWhere('type', TransferSuggestionNotification::class);
        $this->assertSame('transfer_suggestion', $note->data['kind']);
        $this->assertNotEmpty($note->data['ai_prompt']);
        DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
    }

    public function test_inventory_welcome_card_lists_morning_items(): void
    {
        $admin = $this->stocktakeStaff();
        $admin->givePermissionTo(Permission::findOrCreate('transfers.view', 'staff'));
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-OLD-1', 'from_branch_id' => $branch->id, 'to_branch_id' => $other->id,
            'status' => 'draft', 'created_by' => $admin->id,
        ]);
        $transfer->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
        Stocktake::create([
            'stocktake_number' => 'STK-OLD-1', 'branch_id' => $branch->id, 'status' => 'draft',
            'created_by' => $admin->id,
        ])->forceFill(['created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)])->save();
        InventoryReconcileRun::create(['branch_id' => null, 'checked' => 100, 'problem_count' => 2, 'samples' => []]);

        $card = app(ToolRegistry::class)->welcomeBlock($admin);
        $this->assertSame('welcome_card', $card['type']);
        $labels = array_column($card['rows'], 'label');
        $this->assertContains('تحويلات مستنية الاعتماد من أكتر من 24 ساعة', $labels);
        $this->assertContains('جرود مفتوحة من أسبوع', $labels);
        $this->assertNotEmpty($card['quick_actions']);
    }

    public function test_detect_anomalies_notifies_logs_viewers(): void
    {
        $viewer = $this->staff(['ai_agent.logs.view']);
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            StockMovement::create([
                'reference_number' => 'ADJ-'.$i, 'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => StockMovementType::AdjustmentOut, 'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1, 'balance_before' => 100, 'balance_after' => 99, 'created_by' => $user->id,
            ]);
        }

        $this->artisan('inventory:detect-anomalies')->assertExitCode(0);
        $this->assertDatabaseMissing('notifications', ['type' => InventoryAnomalyDigestNotification::class]);

        DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        // مستخدمون آخرون بمعدل طبيعي حتى يوجد وسيط للمقارنة.
        foreach (range(1, 3) as $k) {
            $other = User::factory()->create();
            for ($i = 0; $i < 5; $i++) {
                StockMovement::create([
                    'reference_number' => "ADJ-{$k}-{$i}", 'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                    'type' => StockMovementType::AdjustmentOut, 'affected_bucket' => StockBucket::OnHand,
                    'quantity' => -1, 'balance_before' => 100, 'balance_after' => 99, 'created_by' => $other->id,
                ]);
            }
        }
        $heavy = User::factory()->create();
        for ($i = 0; $i < 60; $i++) {
            StockMovement::create([
                'reference_number' => 'HEAVY-'.$i, 'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => StockMovementType::AdjustmentOut, 'affected_bucket' => StockBucket::OnHand,
                'quantity' => -1, 'balance_before' => 100, 'balance_after' => 99, 'created_by' => $heavy->id,
            ]);
        }
        $this->artisan('inventory:detect-anomalies');
        $this->assertDatabaseHas('notifications', ['type' => InventoryAnomalyDigestNotification::class]);
        DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
    }

    public function test_detect_anomalies_scopes_digests_by_branch(): void
    {
        DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        try {
            $branchA = Branch::factory()->create();
            $branchB = Branch::factory()->create();
            $variant = ProductVariant::factory()->create();
            $scopedA = $this->staff(['ai_agent.logs.view']);
            $scopedA->update(['branch_id' => $branchA->id]);
            $scopedB = $this->staff(['ai_agent.logs.view']);
            $scopedB->update(['branch_id' => $branchB->id]);

            // Heavy adjustment activity in branch B only, with baseline users for a median.
            foreach (range(1, 3) as $k) {
                $base = User::factory()->create();
                for ($i = 0; $i < 5; $i++) {
                    StockMovement::create([
                        'reference_number' => "L8-{$k}-{$i}", 'product_variant_id' => $variant->id, 'branch_id' => $branchB->id,
                        'type' => StockMovementType::AdjustmentOut, 'affected_bucket' => StockBucket::OnHand,
                        'quantity' => -1, 'balance_before' => 100, 'balance_after' => 99, 'created_by' => $base->id,
                    ]);
                }
            }
            $heavy = User::factory()->create();
            for ($i = 0; $i < 60; $i++) {
                StockMovement::create([
                    'reference_number' => 'L8-H-'.$i, 'product_variant_id' => $variant->id, 'branch_id' => $branchB->id,
                    'type' => StockMovementType::AdjustmentOut, 'affected_bucket' => StockBucket::OnHand,
                    'quantity' => -1, 'balance_before' => 100, 'balance_after' => 99, 'created_by' => $heavy->id,
                ]);
            }

            $this->artisan('inventory:detect-anomalies')->assertExitCode(0);

            $for = fn (User $u) => DatabaseNotification::query()
                ->where('notifiable_type', $u->getMorphClass())->where('notifiable_id', $u->id)
                ->where('type', InventoryAnomalyDigestNotification::class)->get();
            $this->assertTrue($for($scopedB)->isNotEmpty(), 'branch B holder sees branch B notes');
            $this->assertTrue($for($scopedA)->isEmpty(), 'branch A holder must not see branch B notes');
            foreach ($for($scopedB) as $notification) {
                $this->assertArrayNotHasKey('branch_ids', $notification->data['notes'][0]);
            }
        } finally {
            DB::table('settings')->where('key', 'ai_inventory_proactive_enabled')->update(['value' => '0']);
            app(SettingService::class)->clearCache();
        }
    }

    public function test_what_if_tools_compute_without_writing(): void
    {
        DB::table('settings')->where('key', 'ai_inventory_share_cost_with_model')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $rich = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.view_cost', 'inventory.all_branches']);
        $plain = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 100]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => null,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cost = app(ToolRegistry::class)->dispatch($rich, $this->conv($rich), [
            'name' => 'what_if_production_cost',
            'args' => ['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 200],
        ]);
        $this->assertSame('data', $cost->type);
        $this->assertSame(150.0, (float) $cost->data['cost_after']);

        $denied = app(ToolRegistry::class)->dispatch($plain, $this->conv($plain), [
            'name' => 'what_if_production_cost',
            'args' => ['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 200],
        ]);
        $this->assertSame('denied', $denied->type);

        $this->saleMovements($variant, $branch, 30, 1, $rich->id);
        $cov = app(ToolRegistry::class)->dispatch($rich, $this->conv($rich), [
            'name' => 'what_if_transfer_coverage',
            'args' => ['variant_id' => $variant->id, 'quantity' => 5, 'from_branch_id' => $branch->id],
        ]);
        $this->assertSame('data', $cov->type);
        $this->assertSame(10.0, (float) $cov->data['donor_coverage_before']);
        $this->assertSame(5.0, (float) $cov->data['donor_coverage_after']);
    }

    public function test_bulk_adjust_and_approve_transfer(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.adjust', 'transfers.create', 'transfers.update', 'transfers.approve', 'inventory.view', 'inventory.all_branches']);
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $v1 = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 10]);
        $v2 = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 10]);
        foreach ([$v1, $v2] as $v) {
            DB::table('stock_levels')->insert([
                'product_variant_id' => $v->id, 'branch_id' => $from->id, 'location_id' => null,
                'on_hand' => 50, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $bulk = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'bulk_actions',
            'args' => ['items' => [
                ['tool' => 'adjust_stock', 'args' => ['variant_id' => $v1->id, 'branch_id' => $from->id, 'adjustment_type' => 'decrease', 'quantity' => 2, 'reason' => 'فقد']],
                ['tool' => 'adjust_stock', 'args' => ['variant_id' => $v2->id, 'branch_id' => $from->id, 'adjustment_type' => 'decrease', 'quantity' => 2, 'reason' => 'فقد']],
            ]],
        ]);
        $this->assertSame('proposal', $bulk->type);
        $this->assertSame('bulk_plan', $bulk->uiBlock['type']);

        $rich = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 100000]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $rich->id, 'branch_id' => $from->id, 'location_id' => null,
            'on_hand' => 50, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $denied = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'bulk_actions',
            'args' => ['items' => [
                ['tool' => 'adjust_stock', 'args' => ['variant_id' => $v1->id, 'branch_id' => $from->id, 'adjustment_type' => 'decrease', 'quantity' => 1, 'reason' => 'فقد']],
                ['tool' => 'adjust_stock', 'args' => ['variant_id' => $rich->id, 'branch_id' => $from->id, 'adjustment_type' => 'decrease', 'quantity' => 1, 'reason' => 'فقد']],
            ]],
        ]);
        $this->assertSame('denied', $denied->type);
    }

    public function test_inventory_health_reports_no_run_yet(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.view', 'inventory.all_branches']);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'inventory_health',
            'args' => [],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertFalse($outcome->data['has_run']);
    }
}
