<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Enums\ProductStatus;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Pricing\PriceListService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class PricingWriteToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_pricing_tools_enabled', 'ai_promotions_tools_enabled'])->update(['value' => '1']);
        foreach (['ai_pricing_tools_enabled' => '1', 'ai_promotions_tools_enabled' => '1', 'ai_pricing_max_items_per_action' => '200', 'ai_coupon_high_risk_percent' => '30', 'ai_price_below_cost_policy' => 'block', 'ai_pricing_vision_enabled' => '0'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['group' => 'ai', 'value' => $value]);
        }
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms, ?int $branchId = null): User
    {
        $u = User::factory()->create(['branch_id' => $branchId]);
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function pricingAdmin(?int $branchId = null): User
    {
        return $this->staff([
            'ai_agent.use', 'ai_agent.execute',
            'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
            'price_lists.import', 'price_lists.export',
            'customers.view', 'customer_groups.view', 'coupons.view', 'promotions.view',
            'inventory.all_branches',
        ], $branchId);
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function variant(float $retail = 100, float $wholesale = 80, ?float $cost = null): ProductVariant
    {
        $category = Category::query()->firstOrCreate(['slug' => 'test-cat'], ['name_ar' => 'قسم', 'name_en' => 'Cat', 'sort_order' => 1, 'is_active' => true]);
        $product = Product::query()->create([
            'code' => 'P'.uniqid(), 'category_id' => $category->id, 'name_ar' => 'منتج', 'name_en' => 'P',
            'slug' => 'p-'.uniqid(), 'status' => ProductStatus::Published, 'sales_channel' => 'both',
        ]);

        $data = [
            'product_id' => $product->id, 'sku' => 'SKU-'.uniqid(), 'retail_price' => $retail,
            'wholesale_price' => $wholesale, 'is_active' => true,
        ];
        if ($cost !== null) {
            $data['cost_price'] = $cost;
        }

        return ProductVariant::query()->create($data);
    }

    private function priceList(PriceListType $type, ?int $customerId = null, ?int $branchId = null, int $priority = 0, ?int $groupId = null): PriceList
    {
        return PriceList::query()->create([
            'name' => 'List '.uniqid(), 'type' => $type, 'customer_id' => $customerId,
            'customer_group_id' => $groupId, 'branch_id' => $branchId, 'priority' => $priority,
        ]);
    }

    // ── create: prepare لا يكتب، confirm ينفذ، ولا undo ──
    public function test_create_price_list_propose_confirm_and_no_undo(): void
    {
        $admin = $this->pricingAdmin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pendingService = app(PendingActionService::class);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'create_price_list',
            'args' => ['name' => 'قائمة تست', 'type' => 'retail'],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertDatabaseMissing('price_lists', ['name' => 'قائمة تست']);

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $res = $pendingService->confirm($admin, $action);
        $this->assertSame('result', $res->type);
        $this->assertDatabaseHas('price_lists', ['name' => 'قائمة تست']);

        $this->assertFalse($registry->canUndo($action->fresh()));
        try {
            $pendingService->undo($admin, $action->fresh());
            $this->fail('Create must not be undoable');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // ثاني confirm يرجع "اتنفذ قبل كده."
        $again = $pendingService->confirm($admin, $action->fresh());
        $this->assertSame('اتنفذ قبل كده.', $again->message);
    }

    public function test_update_price_list_conflict_and_undo(): void
    {
        $admin = $this->pricingAdmin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pendingService = app(PendingActionService::class);
        $list = $this->priceList(PriceListType::Retail);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'update_price_list',
            'args' => ['price_list_id' => $list->id, 'name' => 'اسم جديد'],
        ]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);

        // تعديل متزامن من الـ service يرفع البصمة → 409
        app(PriceListService::class)->update($list->fresh(), ['name' => 'تعديل مباشر'], $admin);
        try {
            $pendingService->confirm($admin, $action);
            $this->fail('Expected 409 on concurrent modification');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('failed', $action->fresh()->status);

        // اقتراح جديد بعد التعديل ينفذ ويتراجع
        $prop2 = $registry->dispatch($admin, $conv, [
            'name' => 'update_price_list',
            'args' => ['price_list_id' => $list->id, 'name' => 'اسم جديد'],
        ]);
        $action2 = AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        $pendingService->confirm($admin, $action2);
        $this->assertSame('اسم جديد', $list->fresh()->name);

        $undo = $pendingService->undo($admin, $action2->fresh());
        $this->assertSame('result', $undo->type);
        $this->assertSame('تعديل مباشر', $list->fresh()->name);
    }

    public function test_set_price_list_status_warns_and_undoes(): void
    {
        $admin = $this->pricingAdmin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pendingService = app(PendingActionService::class);
        $group = \App\Models\CustomerGroup::query()->create(['name' => 'G', 'code' => 'G1']);
        Customer::query()->create([
            'name' => 'تاجر', 'email' => uniqid().'@e.com', 'phone' => '010'.random_int(10000000, 99999999),
            'company_name' => 'C', 'tax_number' => '1', 'commercial_register' => '2', 'password' => Hash::make('p'),
            'type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved, 'customer_group_id' => $group->id,
        ]);
        $list = $this->priceList(PriceListType::CustomerGroup, groupId: $group->id);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'set_price_list_status',
            'args' => ['price_list_id' => $list->id, 'is_active' => false],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertNotEmpty($prop->uiBlock['warnings']);

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $pendingService->confirm($admin, $action);
        $this->assertFalse($list->fresh()->is_active);
        $pendingService->undo($admin, $action->fresh());
        $this->assertTrue($list->fresh()->is_active);
    }

    public function test_upsert_items_guards_undo_and_single_snapshot_dispatch(): void
    {
        $admin = $this->pricingAdmin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pendingService = app(PendingActionService::class);
        $variant = $this->variant(100, 80, 60);
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);

        // حراس: سعر ≤ 0 وتحت التكلفة (block) يظهروا في blocked
        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'upsert_price_list_items',
            'args' => ['price_list_id' => $list->id, 'rows' => [
                ['sku' => $variant->sku, 'min_qty' => 1, 'price' => 95],
                ['sku' => $variant->sku, 'min_qty' => 5, 'price' => 0],
                ['sku' => $variant->sku, 'min_qty' => 10, 'price' => 50],
            ]],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertSame('price_diff', $prop->uiBlock['type']);
        $this->assertCount(2, $prop->uiBlock['blocked']);
        $this->assertSame(95.0, $prop->uiBlock['rows'][0]['new_price']);

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        Queue::fake();
        $pendingService->confirm($admin, $action);
        Queue::assertPushed(RefreshCatalogRetailPrices::class, 1);
        $this->assertSame(95.0, (float) $list->items()->where('min_qty', 1)->firstOrFail()->price);
        $this->assertSame(1, $list->items()->count());

        // التراجع بعد تعديل يدوي → 409
        $item = $list->items()->where('min_qty', 1)->firstOrFail();
        app(PriceListService::class)->updateItem($item, ['price' => 99], $admin);
        try {
            $pendingService->undo($admin, $action->fresh());
            $this->fail('Expected 409 after manual edit');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_upsert_undo_restores_and_expires_after_15_minutes(): void
    {
        $admin = $this->pricingAdmin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pendingService = app(PendingActionService::class);
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'upsert_price_list_items',
            'args' => ['price_list_id' => $list->id, 'rows' => [['sku' => $variant->sku, 'price' => 95]]],
        ]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $pendingService->confirm($admin, $action);
        $this->assertSame(95.0, (float) $list->items()->firstOrFail()->price);

        $undo = $pendingService->undo($admin, $action->fresh());
        $this->assertSame('result', $undo->type);
        $this->assertSame(90.0, (float) $list->items()->firstOrFail()->price);

        // بعد 15 دقيقة التراجع مرفوض
        $prop2 = $registry->dispatch($admin, $conv, [
            'name' => 'upsert_price_list_items',
            'args' => ['price_list_id' => $list->id, 'rows' => [['sku' => $variant->sku, 'price' => 97]]],
        ]);
        $action2 = AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        $pendingService->confirm($admin, $action2);
        $action2->fresh()->forceFill(['executed_at' => now()->subMinutes(16)])->save();
        try {
            $pendingService->undo($admin, $action2->fresh());
            $this->fail('Expected 409 after 15 minutes');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_restricted_branch_user_cannot_modify_other_branch_list(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $admin = $this->pricingAdmin($branchB->id);
        $admin->revokePermissionTo('inventory.all_branches');
        $list = $this->priceList(PriceListType::Retail, branchId: $branchA->id);
        $conv = $this->conv($admin);

        $denied = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_price_list',
            'args' => ['price_list_id' => $list->id, 'name' => 'X'],
        ]);
        $this->assertSame('denied', $denied->type);
    }

    public function test_cost_never_leaks_in_write_previews(): void
    {
        $admin = $this->pricingAdmin();
        DB::table('settings')->where('key', 'ai_price_below_cost_policy')->update(['value' => 'warn']);
        app(SettingService::class)->clearCache();
        $conv = $this->conv($admin);
        $variant = $this->variant(100, 80, 87.55);
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 95]);

        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'upsert_price_list_items',
            'args' => ['price_list_id' => $list->id, 'rows' => [['sku' => $variant->sku, 'price' => 85]]],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertStringContainsString('محمية', json_encode($prop->uiBlock, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('87.55', json_encode($prop->uiBlock, JSON_UNESCAPED_UNICODE));
    }

    public function test_delete_clone_schedule_and_refresh(): void
    {
        $admin = $this->pricingAdmin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pendingService = app(PendingActionService::class);
        $variant = $this->variant();
        $source = $this->priceList(PriceListType::Wholesale);
        $item = $source->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 70]);

        // clone بخصم 10% مع تقريب
        $propClone = $registry->dispatch($admin, $conv, [
            'name' => 'clone_price_list',
            'args' => ['source_price_list_id' => $source->id, 'name' => 'موزعين', 'type' => 'distributor', 'percent' => -10, 'rounding' => 'nearest_5'],
        ]);
        $this->assertSame('proposal', $propClone->type);
        $cloneAction = AiPendingAction::findOrFail($propClone->data['pending_action_id']);
        $cloneRes = $pendingService->confirm($admin, $cloneAction, 'موزعين');
        $this->assertSame('result', $cloneRes->type);
        $cloned = PriceList::findOrFail($cloneRes->data['price_list_id']);
        $this->assertSame(65.0, (float) $cloned->items()->firstOrFail()->price);

        // delete + undo يرجع البند
        $propDel = $registry->dispatch($admin, $conv, [
            'name' => 'delete_price_list_items',
            'args' => ['price_list_id' => $source->id, 'item_ids' => [$item->id]],
        ]);
        $delAction = AiPendingAction::findOrFail($propDel->data['pending_action_id']);
        $pendingService->confirm($admin, $delAction, $source->name);
        $this->assertSame(0, $source->items()->count());
        $pendingService->undo($admin, $delAction->fresh());
        $this->assertSame(1, $source->items()->count());

        // schedule بتاريخ ماضي مرفوضة، ومستقبلية تنفذ
        $pastDenied = $registry->dispatch($admin, $conv, [
            'name' => 'schedule_price_change',
            'args' => ['source_price_list_id' => $source->id, 'starts_at' => now()->subDay()->toDateTimeString(), 'rows' => [['variant_id' => $variant->id, 'price' => 75]]],
        ]);
        $this->assertSame('denied', $pastDenied->type);

        $propSched = $registry->dispatch($admin, $conv, [
            'name' => 'schedule_price_change',
            'args' => ['source_price_list_id' => $source->id, 'starts_at' => now()->addDays(7)->toDateTimeString(), 'rows' => [['variant_id' => $variant->id, 'price' => 75]]],
        ]);
        $schedAction = AiPendingAction::findOrFail($propSched->data['pending_action_id']);
        $schedRes = $pendingService->confirm($admin, $schedAction, $schedAction->fresh()->args['header']['name']);
        $this->assertSame('result', $schedRes->type);

        // refresh: dispatch واحد
        Queue::fake();
        $propRef = $registry->dispatch($admin, $conv, [
            'name' => 'refresh_catalog_prices',
            'args' => ['product_ids' => [$variant->product_id]],
        ]);
        $refAction = AiPendingAction::findOrFail($propRef->data['pending_action_id']);
        $pendingService->confirm($admin, $refAction);
        Queue::assertPushed(RefreshCatalogRetailPrices::class, 1);
    }
}
