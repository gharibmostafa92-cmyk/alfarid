<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
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
 * task.txt §2 tasks 13-14: category tree guards + undo window/safety.
 */
class TaskTxtUndoAndCategoriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_catalog_tools_enabled',
        ])->update(['value' => '1']);
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

    private function admin(): User
    {
        return $this->staff([
            'ai_agent.use', 'ai_agent.execute',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.publish',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        ]);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function cat(string $en, ?int $parent = null): Category
    {
        return Category::factory()->create([
            'name_en' => $en.' '.uniqid(),
            'is_active' => true,
            'parent_id' => $parent,
        ]);
    }

    // ── task 13: cycles and depth rejected, tree untouched ──
    public function test_task13_cycle_and_depth_rejected(): void
    {
        $token = $this->token($this->admin());
        $a = $this->cat('A');
        $b = $this->cat('B', $a->id);
        $c = $this->cat('C', $b->id);

        // Move A under its own child B → cycle.
        $res = $this->withToken($token)->putJson("/api/v1/admin/categories/{$a->id}", ['parent_id' => $b->id]);
        $this->assertContains($res->status(), [409, 422]);
        $this->assertNull($a->fresh()->parent_id);

        // Move A under grandchild C → depth 4.
        $res2 = $this->withToken($token)->putJson("/api/v1/admin/categories/{$a->id}", ['parent_id' => $c->id]);
        $this->assertContains($res2->status(), [409, 422]);
        $this->assertNull($a->fresh()->parent_id);

        // Create under level-3 C → depth 4.
        $res3 = $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name_ar' => 'مستوى رابع',
            'name_en' => 'Level Four '.uniqid(),
            'parent_id' => $c->id,
        ]);
        $this->assertContains($res3->status(), [409, 422]);

        // Reorder introducing a cycle (A↔B) → rejected.
        $res4 = $this->withToken($token)->putJson('/api/v1/admin/categories/reorder', [
            'items' => [
                ['id' => $a->id, 'parent_id' => $b->id, 'sort_order' => 0],
                ['id' => $b->id, 'parent_id' => $a->id, 'sort_order' => 0],
                ['id' => $c->id, 'parent_id' => $b->id, 'sort_order' => 0],
            ],
        ]);
        $this->assertContains($res4->status(), [409, 422]);
        $this->assertNull($a->fresh()->parent_id);

        // Reorder with unknown id → validation message, nothing applied.
        $res5 = $this->withToken($token)->putJson('/api/v1/admin/categories/reorder', [
            'items' => [['id' => 999999, 'parent_id' => null, 'sort_order' => 0]],
        ]);
        $this->assertContains($res5->status(), [404, 409, 422]);
    }

    // ── task 13b: delete guards ──
    public function test_task13b_delete_guards(): void
    {
        $token = $this->token($this->admin());

        // Category with children → rejected (business message, 409 or 422).
        $parent = $this->cat('P');
        $this->cat('CH', $parent->id);
        $this->assertContains(
            $this->withToken($token)->deleteJson("/api/v1/admin/categories/{$parent->id}")->status(),
            [409, 422]
        );
        $this->assertDatabaseHas('categories', ['id' => $parent->id, 'deleted_at' => null]);

        // Category with products → rejected.
        $withProducts = $this->cat('WP');
        Product::factory()->create([
            'category_id' => $withProducts->id,
            'status' => ProductStatus::Draft,
            'sales_channel' => SalesChannel::Retail,
        ]);
        $this->assertContains(
            $this->withToken($token)->deleteJson("/api/v1/admin/categories/{$withProducts->id}")->status(),
            [409, 422]
        );

        // Move a category holding published products under a disabled one → rejected.
        $disabled = $this->cat('DIS');
        $disabled->forceFill(['is_active' => false])->save();
        $holder = $this->cat('HOLD');
        Product::factory()->create([
            'category_id' => $holder->id,
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Retail,
        ]);
        $mv = $this->withToken($token)->putJson("/api/v1/admin/categories/{$holder->id}", ['parent_id' => $disabled->id]);
        $this->assertContains($mv->status(), [409, 422]);
        $this->assertNull($holder->fresh()->parent_id);
    }

    private function undoFixture(): array
    {
        $admin = $this->admin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        static $n = 96000;
        $n++;
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P'.$n.'-BLK',
            'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 50, 'is_active' => true,
        ]);
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_variant',
            'args' => ['variant_id' => $variant->id, 'retail_price' => 450],
        ]);
        $this->assertSame('proposal', $prop->type);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        app(PendingActionService::class)->confirm($admin, $action->fresh());

        return [$admin, $variant, $action->fresh()];
    }

    // ── task 14a: undo window boundaries ──
    public function test_task14a_undo_window_boundaries(): void
    {
        [$admin, $variant, $action] = $this->undoFixture();
        $token = $this->token($admin);

        $this->travel(14)->minutes();
        $this->travel(59)->seconds();
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk();
        $this->assertSame(400.0, (float) $variant->fresh()->retail_price);
        $this->assertSame('undone', $action->fresh()->status);
    }

    public function test_task14b_undo_after_window_rejected(): void
    {
        [$admin, $variant, $action] = $this->undoFixture();
        $token = $this->token($admin);

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")
            ->assertStatus(409);
        $this->assertSame(450.0, (float) $variant->fresh()->retail_price);
    }

    // ── task 14b: external edit after execution blocks undo ──
    public function test_task14c_undo_after_external_edit_rejected(): void
    {
        [$admin, $variant, $action] = $this->undoFixture();
        $token = $this->token($admin);

        app(\App\Services\Catalog\VariantService::class)->update($variant->fresh(), [
            'retail_price' => 460,
            'expected_version' => $variant->fresh()->lock_version,
        ]);

        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")
            ->assertStatus(409);
        $this->assertSame(460.0, (float) $variant->fresh()->retail_price);
    }

    // ── task 14c: double undo + unsupported tool ──
    public function test_task14d_double_undo_and_unsupported_tool_rejected(): void
    {
        [$admin, $variant, $action] = $this->undoFixture();
        $token = $this->token($admin);

        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertStatus(409);
        $this->assertSame(400.0, (float) $variant->fresh()->retail_price);

        // delete_product supports no undo.
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'delete_product',
            'args' => ['product_id' => $variant->product_id],
        ]);
        $this->assertSame('proposal', $prop->type);
        $del = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        app(PendingActionService::class)->confirm($admin, $del->fresh(), (string) $variant->product->code);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$del->id}/undo")->assertStatus(409);
    }
}
