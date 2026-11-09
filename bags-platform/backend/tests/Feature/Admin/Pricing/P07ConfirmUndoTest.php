<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\VariantService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt items 1 (confirm guards on bulk_update_prices) and 6 (undo rules).
 */
class P07ConfirmUndoTest extends TestCase
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

    private function catalogStaff(): User
    {
        return $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.create', 'products.update', 'products.publish']);
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
    private function catalog(int $count = 5): array
    {
        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $n = 81000 + (++self::$seq);
            $product = Product::factory()->create([
                'category_id' => $cat->id, 'code' => 'P'.$n,
                'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
            ]);
            $variants[] = ProductVariant::factory()->create([
                'product_id' => $product->id, 'sku' => 'P'.$n.'-U',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 100, 'is_active' => true,
            ]);
        }

        return ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants];
    }

    private function proposeBulk(User $admin, AiConversation $conv, int $categoryId, array $extra = []): AiPendingAction
    {
        $prop = app(\App\Services\Ai\ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'bulk_update_prices',
            'args' => array_merge([
                'field' => 'retail_price', 'operation' => 'percent', 'value' => 10,
                'scope' => ['category_id' => $categoryId],
            ], $extra),
        ]);
        $this->assertSame('proposal', $prop->type);

        return AiPendingAction::findOrFail($prop->data['pending_action_id']);
    }

    private function confirm(User $u, AiPendingAction $action, ?string $typed = null): \Illuminate\Testing\TestResponse
    {
        $payload = [];
        if ($typed !== null) {
            $payload['typed_confirmation'] = $typed;
        }

        return $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", $payload);
    }

    // ── item 1a: another staff member cannot confirm ──
    public function test_confirm_by_other_staff_returns_403(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $this->catalog();
        $other = $this->catalogStaff();
        $action = $this->proposeBulk($admin, $conv, $cat->id);

        $this->confirm($other, $action, 'تنفيذ')->assertForbidden();
        $this->assertSame('pending', $action->fresh()->status);
        foreach ($variants as $v) {
            $this->assertSame(400.0, (float) $v->fresh()->retail_price);
        }
    }

    // ── item 1b: expired card → 409 and stays expired ──
    public function test_confirm_after_expiry_returns_409_and_stays_expired(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $this->catalog();
        $action = $this->proposeBulk($admin, $conv, $cat->id);
        $action->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->confirm($admin, $action, 'تنفيذ')->assertStatus(409);
        $this->assertSame('expired', $action->fresh()->status);
        foreach ($variants as $v) {
            $this->assertSame(400.0, (float) $v->fresh()->retail_price);
        }

        // A second attempt still fails and the card stays expired.
        $this->confirm($admin, $action->fresh(), 'تنفيذ')->assertStatus(409);
        $this->assertSame('expired', $action->fresh()->status);
    }

    // ── item 1c: tampered args in DB → 409 ──
    public function test_confirm_with_tampered_args_returns_409(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $this->catalog();
        $action = $this->proposeBulk($admin, $conv, $cat->id);
        $args = $action->args;
        $args['rows'][0]['new'] = 1.0;
        DB::table('ai_pending_actions')->where('id', $action->id)->update(['args' => json_encode($args)]);

        $this->confirm($admin, $action, 'تنفيذ')->assertStatus(409)
            ->assertJsonFragment(['message' => 'بيانات الإجراء اتغيرت.']);
        foreach ($variants as $v) {
            $this->assertSame(400.0, (float) $v->fresh()->retail_price);
        }
    }

    // ── item 1d/e: missing or wrong typed confirmation → 422, stays pending ──
    public function test_confirm_without_or_with_wrong_typed_confirmation_returns_422(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $this->catalog();

        $missing = $this->proposeBulk($admin, $conv, $cat->id);
        $this->confirm($admin, $missing)->assertStatus(422);
        $this->assertSame('pending', $missing->fresh()->status);

        $wrong = $this->proposeBulk($admin, $conv, $cat->id);
        $this->confirm($admin, $wrong, 'تأكيد')->assertStatus(422);
        $this->assertSame('pending', $wrong->fresh()->status);

        foreach ($variants as $v) {
            $this->assertSame(400.0, (float) $v->fresh()->retail_price);
        }
    }

    // ── item 1f: padded typed confirmation is normalized and succeeds ──
    public function test_confirm_with_padded_typed_confirmation_succeeds(): void
    {
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $this->catalog();
        $action = $this->proposeBulk($admin, $conv, $cat->id);

        $this->confirm($admin, $action, ' تنفيذ ')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('executed', $action->fresh()->status);
        foreach ($variants as $v) {
            $this->assertSame(440.0, (float) $v->fresh()->retail_price);
        }
    }

    /** Execute a set-price proposal over a category holding one null-priced variant. */
    private function executedWithNullVariant(): array
    {
        $env = $this->catalog(4);
        ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants] = $env;
        $n = 81900 + (++self::$seq);
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
        ]);
        $nullVariant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P'.$n.'-N',
            'retail_price' => null, 'wholesale_price' => null, 'cost_price' => 50, 'is_active' => true,
        ]);
        $variants[] = $nullVariant;

        $action = $this->proposeBulk($admin, $conv, $cat->id, ['operation' => 'set', 'value' => 500]);
        $this->confirm($admin, $action, 'تنفيذ')->assertOk();
        $this->assertSame('executed', $action->fresh()->status);

        return ['user' => $admin, 'conv' => $conv, 'category' => $cat, 'variants' => $variants, 'action' => $action, 'nullVariant' => $nullVariant];
    }

    private function undo(User $u, AiPendingAction $action): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/actions/{$action->id}/undo");
    }

    // ── item 6a: undo within 14 minutes restores exact prices including null ──
    public function test_undo_within_window_restores_exact_prices(): void
    {
        ['user' => $admin, 'action' => $action, 'variants' => $variants, 'nullVariant' => $nullVariant] = $this->executedWithNullVariant();
        $this->assertSame(500.0, (float) $nullVariant->fresh()->retail_price);

        $this->travel(14)->minutes();
        $this->undo($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('undone', $action->fresh()->status);

        foreach ($variants as $v) {
            if ($v->id === $nullVariant->id) {
                continue;
            }
            $this->assertSame(400.0, (float) $v->fresh()->retail_price);
        }
        $this->assertNull($nullVariant->fresh()->retail_price);
    }

    // ── item 6b: undo after the window → 409 ──
    public function test_undo_after_window_returns_409(): void
    {
        ['user' => $admin, 'action' => $action, 'variants' => $variants] = $this->executedWithNullVariant();

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        $this->undo($admin, $action)->assertStatus(409);
        $this->assertSame('executed', $action->fresh()->status);
        foreach ($variants as $v) {
            $this->assertSame(500.0, (float) $v->fresh()->retail_price);
        }
    }

    // ── item 6c: undo after an external edit → 409, external value kept ──
    public function test_undo_after_external_edit_returns_409(): void
    {
        ['user' => $admin, 'action' => $action, 'variants' => $variants] = $this->executedWithNullVariant();
        $victim = $variants[0]->fresh();
        app(VariantService::class)->update($victim, [
            'retail_price' => 511, 'expected_version' => (int) $victim->lock_version,
        ]);

        $this->undo($admin, $action)->assertStatus(409);
        $this->assertSame('executed', $action->fresh()->status);
        $this->assertSame(511.0, (float) $victim->fresh()->retail_price);
    }

    // ── item 6d: undo by another staff member → 403 ──
    public function test_undo_by_other_staff_returns_403(): void
    {
        ['action' => $action, 'variants' => $variants] = $this->executedWithNullVariant();
        $other = $this->catalogStaff();

        $this->undo($other, $action)->assertForbidden();
        $this->assertSame('executed', $action->fresh()->status);
        foreach ($variants as $v) {
            $this->assertSame(500.0, (float) $v->fresh()->retail_price);
        }
    }

    // ── item 6e: second undo → 409 ──
    public function test_double_undo_returns_409(): void
    {
        ['user' => $admin, 'action' => $action] = $this->executedWithNullVariant();

        $this->undo($admin, $action)->assertOk();
        $this->assertSame('undone', $action->fresh()->status);
        $this->undo($admin, $action->fresh())->assertStatus(409);
    }
}
