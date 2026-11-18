<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Inventory\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use App\Services\Ai\Inventory\Tools\AdjustStockTool;
use App\Services\Ai\Inventory\Tools\CreateTransferDraftTool;
use App\Services\Ai\Inventory\Tools\ExplainBalanceTool;
use App\Services\Ai\Inventory\Tools\ListMovementsTool;
use App\Services\Ai\Inventory\Tools\ReceiveProductionTool;
use App\Services\Ai\Inventory\Tools\RecordCountsTool;
use App\Services\Ai\Inventory\Tools\StockMatrixTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Shared\SettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiInventoryHardeningAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function makeStaff(array $perms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (array_merge($perms, ['inventory.all_branches']) as $p) {
            Permission::findOrCreate($p, 'staff');
            $user->givePermissionTo($p);
        }

        return $user;
    }

    public function test_list_movements_and_receive_production_protect_cost_privacy(): void
    {
        Setting::updateOrCreate(['key' => 'ai_inventory_share_cost_with_model'], ['value' => '0', 'group' => 'ai', 'type' => 'boolean']);
        app(SettingService::class)->clearCache();

        $noCostStaff = $this->makeStaff(['inventory.view']);
        $costStaff = $this->makeStaff(['inventory.view', 'inventory.view_cost', 'production.receive']);
        $conv = AiConversation::create(['user_id' => $costStaff->id, 'title' => 'Inv', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $branch = Branch::create(['name' => 'WH', 'code' => 'WH-'.uniqid(), 'type' => 'warehouse', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'cat-'.uniqid(), 'is_active' => true]);
        $product = Product::create(['category_id' => $cat->id, 'code' => 'P-'.uniqid(), 'name_ar' => 'شنطة', 'name_en' => 'Bag', 'slug' => 'p-'.uniqid(), 'status' => ProductStatus::Draft]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid(), 'retail_price' => 200, 'cost_price' => 100, 'is_active' => true]);
        StockLevel::forceCreate(['product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'on_hand' => 10, 'reserved' => 0, 'damaged' => 0]);

        StockMovement::forceCreate([
            'reference_number' => 'MOV-'.uniqid(),
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand',
            'quantity' => 10,
            'balance_before' => 0,
            'balance_after' => 10,
            'unit_cost' => 123.45,
            'created_by' => $costStaff->id,
        ]);

        // ListMovementsTool for staff without inventory.view_cost must not expose unit_cost key
        $listTool = app(ListMovementsTool::class);
        $listOut = $listTool->handle($noCostStaff, ['branch_id' => $branch->id, 'variant_id' => $variant->id]);
        $this->assertSame('data', $listOut->type);
        $this->assertArrayNotHasKey('unit_cost', $listOut->data['movements'][0]);

        // ReceiveProductionTool when share_cost_with_model = false must not leak absolute cost_before/cost_after in changes string
        $recvTool = app(ReceiveProductionTool::class);
        $prep = $recvTool->prepare($costStaff, [
            'branch_id' => $branch->id,
            'batch_number' => 'BATCH-'.uniqid(),
            'items' => [['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 140]],
        ]);
        $this->assertSame('proposal', $prep->type);
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $changesText = implode(' ', $action->preview['after']['changes'] ?? []);
        $this->assertStringNotContainsString('100', $changesText);
        $this->assertStringNotContainsString('120', $changesText);
    }

    public function test_record_counts_detects_concurrent_count_update_and_adjust_stock_rejects_missing_version(): void
    {
        $staff = $this->makeStaff(['inventory.view', 'inventory.stocktake', 'inventory.adjust']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'ST', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $branch = Branch::create(['name' => 'WH', 'code' => 'WH-'.uniqid(), 'type' => 'warehouse', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'cat-'.uniqid(), 'is_active' => true]);
        $product = Product::create(['category_id' => $cat->id, 'code' => 'P-'.uniqid(), 'name_ar' => 'شنطة', 'name_en' => 'Bag', 'slug' => 'p-'.uniqid(), 'status' => ProductStatus::Draft]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid(), 'retail_price' => 200, 'is_active' => true]);

        $stocktake = Stocktake::create([
            'stocktake_number' => 'ST-'.uniqid(),
            'branch_id' => $branch->id,
            'status' => StocktakeStatus::InProgress,
            'created_by' => $staff->id,
        ]);
        $item = StocktakeItem::create([
            'stocktake_id' => $stocktake->id,
            'product_variant_id' => $variant->id,
            'system_qty' => 5,
            'counted_qty' => 4,
        ]);

        $recordTool = app(RecordCountsTool::class);
        $prep = $recordTool->prepare($staff, [
            'stocktake_id' => $stocktake->id,
            'items' => [['variant_id' => $variant->id, 'counted_qty' => 6]],
        ]);
        $this->assertSame('proposal', $prep->type);
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();

        // Simulate another staff member updating counted_qty concurrently
        $item->update(['counted_qty' => 7]);

        $caught = false;
        try {
            $recordTool->execute($staff, $action);
        } catch (BusinessException $e) {
            $caught = $e->status === 409;
        }
        $this->assertTrue($caught, 'Expected 409 BusinessException when stocktake item counted_qty changed concurrently');

        // AdjustStockTool::execute() with missing expected_versions key must throw 409 even when lastMovementId is null
        $adjustTool = app(AdjustStockTool::class);
        $adjPrep = $adjustTool->prepare($staff, [
            'branch_id' => $branch->id,
            'sku' => $variant->sku,
            'adjustment_type' => 'increase',
            'quantity' => 2,
            'reason' => 'خطأ إدخال',
        ]);
        $this->assertSame('proposal', $adjPrep->type, (string) ($adjPrep->message ?? ''));
        $adjAction = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $adjAction->forceFill(['expected_versions' => []])->save();

        $adjCaught = false;
        try {
            $adjustTool->execute($staff, $adjAction);
        } catch (BusinessException $e) {
            $adjCaught = $e->status === 409;
        }
        $this->assertTrue($adjCaught, 'Expected 409 when expected_versions key is missing in AdjustStockTool');
    }

    public function test_stock_matrix_returns_available_and_transfer_draft_does_not_pollute_expected_versions(): void
    {
        $staff = $this->makeStaff(['inventory.view', 'inventory.transfer']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Mat', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $b1 = Branch::create(['name' => 'WH1', 'code' => 'WH1-'.uniqid(), 'type' => 'warehouse', 'is_active' => true]);
        $b2 = Branch::create(['name' => 'WH2', 'code' => 'WH2-'.uniqid(), 'type' => 'warehouse', 'is_active' => true]);
        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'cat-'.uniqid(), 'is_active' => true]);
        $product = Product::create(['category_id' => $cat->id, 'code' => 'P-'.uniqid(), 'name_ar' => 'شنطة', 'name_en' => 'Bag', 'slug' => 'p-'.uniqid(), 'status' => ProductStatus::Draft]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid(), 'retail_price' => 200, 'is_active' => true]);
        StockLevel::forceCreate(['product_variant_id' => $variant->id, 'branch_id' => $b1->id, 'on_hand' => 15, 'reserved' => 3, 'damaged' => 2]);

        $matrix = app(StockMatrixTool::class)->handle($staff, ['product_id' => $product->id]);
        $this->assertSame('data', $matrix->type);
        $this->assertEquals(10.0, $matrix->data['rows'][0]['cells'][$b1->id]['available'] ?? null);

        // CreateTransferDraftTool must not store created_transfer_id in expected_versions
        $draftTool = app(CreateTransferDraftTool::class);
        $prep = $draftTool->prepare($staff, [
            'from_branch_id' => $b1->id,
            'to_branch_id' => $b2->id,
            'items' => [['sku' => $variant->sku, 'quantity' => 2]],
        ]);
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $draftTool->execute($staff, $action);
        $freshAction = $action->fresh();
        $this->assertArrayNotHasKey('created_transfer_id', $freshAction->expected_versions ?? []);
        $this->assertTrue($draftTool->canUndo($freshAction));

        // ExplainBalanceTool when StockLevel was deleted after movement at a location
        $loc = Location::create(['branch_id' => $b1->id, 'name' => 'Shelf A', 'code' => 'SH-'.uniqid(), 'type' => 'shelf', 'is_active' => true]);
        StockMovement::forceCreate([
            'reference_number' => 'MOV-LOC-'.uniqid(),
            'product_variant_id' => $variant->id,
            'branch_id' => $b1->id,
            'location_id' => $loc->id,
            'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand',
            'quantity' => 5,
            'balance_before' => 0,
            'balance_after' => 5,
            'created_by' => $staff->id,
        ]);
        StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $b1->id)->delete();
        $explain = app(ExplainBalanceTool::class)->handle($staff, ['branch_id' => $b1->id, 'variant_id' => $variant->id]);
        $this->assertSame('data', $explain->type);
        $this->assertGreaterThan(0, $explain->data['window_movements'] ?? 0);
    }
}
