<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task3 CRITICAL-02 / CRITICAL-03: prepare/execute key parity.
 *
 * prepare() stores promotion changes under value/applies_to/target_ids and
 * coupon changes under max_discount_amount/ends_at, but execute() filtered
 * args with mismatched keys — silently dropping money-critical updates while
 * reporting success.
 */
class Task3UpdateKeyParityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_pricing_tools_enabled', 'ai_promotions_tools_enabled'])->update(['value' => '1']);
        foreach (['ai_pricing_tools_enabled' => '1', 'ai_promotions_tools_enabled' => '1', 'ai_pricing_max_items_per_action' => '200'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['group' => 'ai', 'value' => $value]);
        }
        app(\App\Services\Shared\SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'coupons.view', 'coupons.create', 'coupons.update', 'promotions.view', 'promotions.create', 'promotions.update', 'inventory.all_branches'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_update_promotion_applies_value_change(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $promotion = Promotion::create([
            'name' => 'عرض اختبار',
            'type' => 'percentage_discount',
            'value' => 10,
            'is_active' => true,
        ]);

        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_promotion',
            'args' => ['promotion_id' => $promotion->id, 'value' => 25],
        ]);
        $this->assertSame('proposal', $prop->type);

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $res = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $res->type);
        $this->assertEquals(25, (float) $promotion->fresh()->value);
    }

    public function test_update_coupon_applies_cap_and_expiry_change(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $endsAt = now()->addMonth()->toDateTimeString();
        $coupon = Coupon::create([
            'code' => 'TASK3PARITY',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_coupon',
            'args' => ['coupon_id' => $coupon->id, 'max_discount_amount' => 50, 'ends_at' => $endsAt],
        ]);
        $this->assertSame('proposal', $prop->type);

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $res = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $res->type);
        $fresh = $coupon->fresh();
        $this->assertEquals(50, (float) $fresh->max_discount_amount);
        $this->assertNotNull($fresh->ends_at);
    }
}
