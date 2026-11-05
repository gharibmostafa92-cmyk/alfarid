<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Jobs\PruneAiConversations;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt item 25 (bulk prepare guards/limits) plus section-3 bulk edges:
 * selected_items validation, partial failure, stuck-confirmed prune.
 */
class P07BulkGuardsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);
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

    private function catalogStaff(array $extra = []): User
    {
        return $this->staff(array_merge(
            ['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.create', 'products.update', 'products.publish'],
            $extra
        ));
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private static int $seq = 0;

    /** @return array{user: User, conv: AiConversation, category: Category, variants: ProductVariant[]} */
    private function catalog(int $count = 3, array $variantOverrides = []): array
    {
        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $n = 83000 + (++self::$seq);
            $product = Product::factory()->create([
                'category_id' => $cat->id, 'code' => 'P'.$n,
                'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
            ]);
            $variants[] = ProductVariant::factory()->create(array_merge([
                'product_id' => $product->id, 'sku' => 'P'.$n.'-G',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 100, 'is_active' => true,
            ], $variantOverrides));
        }

        return ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants];
    }

    private function dispatch(User $admin, AiConversation $conv, array $args): \App\Services\Ai\ToolOutcome
    {
        return app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_update_prices', 'args' => $args]);
    }

    // ── item 25: missing scope ──
    public function test_prepare_without_scope_is_denied(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat] = $this->catalog(1);

        $out = $this->dispatch($admin, $conv, ['field' => 'retail_price', 'operation' => 'percent', 'value' => 10, 'scope' => []]);
        $this->assertSame('denied', $out->type);
        $this->assertSame(0, AiPendingAction::count());
    }

    // ── item 25: scope matches nothing ──
    public function test_prepare_with_empty_scope_result_is_denied(): void
    {
        ['user' => $admin, 'conv' => $conv] = $this->catalog(1);
        $empty = Category::factory()->create(['is_active' => true]);

        $out = $this->dispatch($admin, $conv, [
            'field' => 'retail_price', 'operation' => 'percent', 'value' => 10, 'scope' => ['category_id' => $empty->id]]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('مفيش متغيرات نشطة', $out->message);
    }

    // ── item 25: 201 variants exceed the 200 cap ──
    public function test_prepare_with_201_variants_exceeds_cap(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat] = $this->catalog(201);

        $out = $this->dispatch($admin, $conv, [
            'field' => 'retail_price', 'operation' => 'percent', 'value' => 10, 'scope' => ['category_id' => $cat->id]]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('201', $out->message);
    }

    // ── item 25: null current price blocks percent but allows set ──
    public function test_prepare_null_price_blocks_percent_but_allows_set(): void
    {
        $env = $this->catalog(0);
        ['user' => $admin, 'conv' => $conv, 'category' => $cat] = $env;
        $n = 84000 + (++self::$seq);
        $product = Product::factory()->create(['category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
        $nullVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-N',
            'retail_price' => null, 'wholesale_price' => null, 'cost_price' => 50, 'is_active' => true]);

        $denied = $this->dispatch($admin, $conv, ['field' => 'retail_price', 'operation' => 'percent', 'value' => 10,
            'scope' => ['product_ids' => [$product->id]]]);
        $this->assertSame('denied', $denied->type);

        $ok = $this->dispatch($admin, $conv, ['field' => 'retail_price', 'operation' => 'set', 'value' => 500,
            'scope' => ['product_ids' => [$product->id]]]);
        $this->assertSame('proposal', $ok->type);
        // prepare() writes nothing: prices stay untouched until confirm.
        $this->assertNull($nullVariant->fresh()->retail_price);
    }

    // ── item 25: below-cost guard hides the number without cost permission ──
    public function test_prepare_below_cost_reason_depends_on_cost_permission(): void
    {
        // One blocked row (400→200 vs cost 390) plus one passing row (cost 50),
        // so the proposal succeeds and carries the blocked-row reasons.
        $blocked = $this->catalog(1, ['retail_price' => 400, 'wholesale_price' => 100, 'cost_price' => 390]);
        $cat = $blocked['category'];
        $n = 84500 + (++self::$seq);
        $product = Product::factory()->create(['category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-P',
            'retail_price' => 400, 'wholesale_price' => 100, 'cost_price' => 50, 'is_active' => true]);

        $args = ['field' => 'retail_price', 'operation' => 'percent', 'value' => -50, 'scope' => ['category_id' => $cat->id]];

        $noCostView = $this->catalogStaff();
        $this->assertFalse(\App\Services\CostAccess::canView($noCostView));
        $out = $this->dispatch($noCostView, $this->conv($noCostView), $args);
        $this->assertSame('proposal', $out->type);
        $blockedReasons = json_encode(AiPendingAction::findOrFail($out->data['pending_action_id'])->preview['blocked'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('محمية', $out->message.$blockedReasons);
        $this->assertStringNotContainsString('390', $blockedReasons);

        $withCostView = $this->catalogStaff(['products.view_cost']);
        $this->assertTrue(\App\Services\CostAccess::canView($withCostView));
        $out2 = $this->dispatch($withCostView, $this->conv($withCostView), $args);
        $this->assertSame('proposal', $out2->type);
        $blocked2 = json_encode(AiPendingAction::findOrFail($out2->data['pending_action_id'])->preview['blocked'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('390', $blocked2);
    }

    // ── item 25: wholesale must stay below retail ──
    public function test_prepare_wholesale_at_or_above_retail_is_denied(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat] = $this->catalog(1, ['retail_price' => 400, 'wholesale_price' => 390]);

        $out = $this->dispatch($admin, $conv, ['field' => 'wholesale_price', 'operation' => 'percent', 'value' => 10,
            'scope' => ['category_id' => $cat->id]]);
        $this->assertSame('denied', $out->type);
    }

    // ── item 25: value=-100, disabled guard, all-blocked, versions/inverse ──
    public function test_prepare_value_and_guard_matrix(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $this->catalog(2);

        // -100% → zero price is blocked.
        $neg = $this->dispatch($admin, $conv, ['field' => 'retail_price', 'operation' => 'percent', 'value' => -100,
            'scope' => ['category_id' => $cat->id]]);
        $this->assertSame('denied', $neg->type);

        // guard.never_below_cost=false lets a below-cost proposal through.
        $env = $this->catalog(0);
        $cat2 = Category::factory()->create(['is_active' => true]);
        $n = 85000 + (++self::$seq);
        $product = \App\Models\Product::factory()->create(['category_id' => $cat2->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
        $cheap = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-C',
            'retail_price' => 400, 'wholesale_price' => 100, 'cost_price' => 390, 'is_active' => true]);
        $bypass = $this->dispatch($env['user'], $env['conv'], ['field' => 'retail_price', 'operation' => 'percent',
            'value' => -50, 'guard' => ['never_below_cost' => false], 'scope' => ['category_id' => $cat2->id]]);
        $this->assertSame('proposal', $bypass->type);
        $this->assertSame(400.0, (float) $cheap->fresh()->retail_price);

        // expected_versions and inverse snapshot the pre-execution state.
        $action = AiPendingAction::findOrFail($bypass->data['pending_action_id']);
        $this->assertSame((int) $cheap->fresh()->lock_version, $action->expected_versions['variant:'.$cheap->id]);
        $this->assertSame(400.0, (float) $action->inverse['prices'][(string) $cheap->id]);

        // A scope where every row is cost-blocked is denied, not half-built.
        $allBlocked = $this->dispatch($admin, $conv, ['field' => 'retail_price', 'operation' => 'percent', 'value' => -50,
            'scope' => ['product_ids' => [$variants[0]->product_id, $variants[1]->product_id]]]);
        $this->assertSame('denied', $allBlocked->type);
    }

    // ── section 3: selected_items validation on bulk confirm ──
    public function test_bulk_selected_items_validation(): void
    {
        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $v = [];
        for ($i = 0; $i < 2; $i++) {
            $n = 86000 + (++self::$seq);
            $product = Product::factory()->create(['category_id' => Category::factory()->create(['is_active' => true])->id,
                'code' => 'P'.$n, 'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
            $v[] = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-B',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 100, 'is_active' => true]);
        }
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'update_variant', 'args' => ['variant_id' => $v[0]->id, 'retail_price' => 410]],
            ['tool' => 'update_variant', 'args' => ['variant_id' => $v[1]->id, 'retail_price' => 420]],
        ]]]);
        $parent = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $ids = $parent->args['action_ids'];
        $this->assertCount(2, $ids);

        $url = "/api/v1/admin/ai/actions/{$parent->id}/confirm";
        $token = $this->token($admin);

        // Foreign id, duplicates, and empty selection are all rejected.
        $this->withToken($token)->postJson($url, ['typed_confirmation' => 'تنفيذ', 'selected_items' => [$ids[0], (string) \Illuminate\Support\Str::ulid()]])->assertStatus(422);
        $this->withToken($token)->postJson($url, ['typed_confirmation' => 'تنفيذ', 'selected_items' => [$ids[0], $ids[0]]])->assertStatus(422);
        $this->withToken($token)->postJson($url, ['typed_confirmation' => 'تنفيذ', 'selected_items' => []])->assertStatus(422);
        $this->assertSame('pending', $parent->fresh()->status);

        // A valid subset executes only the chosen child; the other is cancelled in the same transaction.
        $this->withToken($token)->postJson($url, ['typed_confirmation' => 'تنفيذ', 'selected_items' => [$ids[0]]])->assertOk();
        $this->assertSame(410.0, (float) $v[0]->fresh()->retail_price);
        $this->assertSame(400.0, (float) $v[1]->fresh()->retail_price);
        $this->assertSame('cancelled', AiPendingAction::findOrFail($ids[1])->status);
    }

    // ── section 3: one failing child does not cancel the successful one ──
    public function test_bulk_partial_failure_keeps_successes(): void
    {
        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $v = [];
        for ($i = 0; $i < 2; $i++) {
            $n = 87000 + (++self::$seq);
            $product = Product::factory()->create(['category_id' => Category::factory()->create(['is_active' => true])->id,
                'code' => 'P'.$n, 'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
            $v[] = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-F',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 100, 'is_active' => true]);
        }
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'update_variant', 'args' => ['variant_id' => $v[0]->id, 'retail_price' => 410]],
            ['tool' => 'update_variant', 'args' => ['variant_id' => $v[1]->id, 'retail_price' => 420]],
        ]]]);
        $parent = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $ids = $parent->args['action_ids'];

        // Tamper the second child's args so only it fails.
        $child2 = AiPendingAction::findOrFail($ids[1]);
        DB::table('ai_pending_actions')->where('id', $child2->id)->update(['args' => json_encode(['variant_id' => $v[1]->id, 'retail_price' => 1])]);

        $res = $this->withToken($this->token($admin))->postJson(
            "/api/v1/admin/ai/actions/{$parent->id}/confirm",
            ['typed_confirmation' => 'تنفيذ', 'selected_items' => $ids]);
        $res->assertOk();
        $this->assertSame(410.0, (float) $v[0]->fresh()->retail_price);
        $this->assertSame(400.0, (float) $v[1]->fresh()->retail_price);
        $this->assertSame('failed', AiPendingAction::findOrFail($ids[1])->status);
        $this->assertSame('executed', $parent->fresh()->status);
        $items = $parent->fresh()->result['items'];
        $this->assertTrue(collect($items)->contains(fn ($i) => $i['action_id'] === $ids[0] && $i['ok'] === true));
        $this->assertTrue(collect($items)->contains(fn ($i) => $i['action_id'] === $ids[1] && $i['ok'] === false));
    }

    // ── section 3: prune recovers a stuck confirmed bulk (deterministic side) ──
    public function test_prune_fails_stuck_confirmed_bulk_and_cancels_children(): void
    {
        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $variants = [];
        for ($i = 0; $i < 2; $i++) {
            $n = 88000 + (++self::$seq);
            $product = Product::factory()->create(['category_id' => Category::factory()->create(['is_active' => true])->id,
                'code' => 'P'.$n, 'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
            $variants[] = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'P'.$n.'-S',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 100, 'is_active' => true]);
        }
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'update_variant', 'args' => ['variant_id' => $variants[0]->id, 'retail_price' => 410]],
            ['tool' => 'update_variant', 'args' => ['variant_id' => $variants[1]->id, 'retail_price' => 420]],
        ]]]);
        $this->assertSame('proposal', $prop->type);
        $parent = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $childIds = $parent->args['action_ids'];

        // Simulate a claim that crashed mid-flight 6 minutes ago.
        $parent->forceFill(['status' => 'confirmed', 'updated_at' => now()->subMinutes(6)])->save();

        app(PruneAiConversations::class)->handle(app(SettingService::class));

        $this->assertSame('failed', $parent->fresh()->status);
        foreach ($childIds as $childId) {
            $this->assertSame('cancelled', AiPendingAction::findOrFail($childId)->status);
        }
        foreach ($variants as $variant) {
            $this->assertSame(400.0, (float) $variant->fresh()->retail_price);
        }
    }
}
