<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class PricingCouponPromoToolsTest extends TestCase
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

    // ── create single coupon (medium risk), confirm, no undo ──
    public function test_create_single_coupon_and_no_undo(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'create_coupons',
            'args' => ['type' => 'fixed', 'value' => 25, 'usage_limit' => 100, 'ends_at' => now()->addMonth()->toDateTimeString()],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertSame('coupon_batch', $prop->uiBlock['type']);
        $this->assertCount(1, $prop->uiBlock['rows']);
        $this->assertDatabaseMissing('coupons', ['code' => $prop->uiBlock['rows'][0]['code']]);

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $res = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $res->type);
        $this->assertDatabaseHas('coupons', ['code' => $res->data['codes'][0], 'used_count' => 0]);

        $this->assertFalse($registry->canUndo($action->fresh()));
        try {
            app(PendingActionService::class)->undo($admin, $action->fresh());
            $this->fail('Coupon creation must not be undoable');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // ── open coupon: high risk, typed code, social-leak warning ──
    public function test_open_coupon_requires_typed_code_and_warns(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pending = app(PendingActionService::class);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'create_coupons',
            'args' => ['type' => 'percentage', 'value' => 50],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertStringContainsString('السوشيال', json_encode($prop->uiBlock, JSON_UNESCAPED_UNICODE));
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);

        try {
            $pending->confirm($admin, $action);
            $this->fail('Expected typed confirmation error');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $code = $prop->uiBlock['rows'][0]['code'];
        $res = $pending->confirm($admin, $action->fresh(), $code);
        $this->assertSame('result', $res->type);
        $this->assertSame([$code], $res->data['codes']);
    }

    public function test_create_batch_with_prefix_and_duplicate_code_denied(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pending = app(PendingActionService::class);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'create_coupons',
            'args' => ['prefix' => 'RAMADAN', 'count' => 3, 'type' => 'fixed', 'value' => 10, 'usage_limit' => 50, 'ends_at' => now()->addMonth()->toDateTimeString()],
        ]);
        $codes = array_column($prop->uiBlock['rows'], 'code');
        $this->assertCount(3, $codes);
        foreach ($codes as $code) {
            $this->assertStringStartsWith('RAMADAN-', $code);
        }
        $pending->confirm($admin, AiPendingAction::findOrFail($prop->data['pending_action_id']));

        // Reusing an existing code is denied with the coupon message.
        $denied = $registry->dispatch($admin, $conv, [
            'name' => 'create_coupons',
            'args' => ['code' => strtolower($codes[0]), 'type' => 'fixed', 'value' => 5, 'usage_limit' => 10, 'ends_at' => now()->addMonth()->toDateTimeString()],
        ]);
        $this->assertSame('denied', $denied->type);
        $this->assertStringContainsString('مستخدم بالفعل', $denied->message);
    }

    public function test_update_coupon_guards_and_undo(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pending = app(PendingActionService::class);
        $coupon = Coupon::query()->create(['code' => 'UPD1', 'type' => 'fixed', 'value' => 10, 'used_count' => 5, 'is_active' => true]);

        // usage_limit below used_count is denied.
        $denied = $registry->dispatch($admin, $conv, [
            'name' => 'update_coupon',
            'args' => ['coupon_id' => $coupon->id, 'usage_limit' => 3],
        ]);
        $this->assertSame('denied', $denied->type);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'update_coupon',
            'args' => ['coupon_id' => $coupon->id, 'value' => 15],
        ]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $pending->confirm($admin, $action);
        $this->assertSame(15.0, (float) $coupon->fresh()->value);

        // Concurrent edit → 409.
        $prop2 = $registry->dispatch($admin, $conv, [
            'name' => 'update_coupon',
            'args' => ['coupon_id' => $coupon->id, 'value' => 20],
        ]);
        $action2 = AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        Coupon::query()->whereKey($coupon->id)->update(['value' => 99]);
        try {
            $pending->confirm($admin, $action2);
            $this->fail('Expected 409 on concurrent edit');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_set_coupon_status_toggle_and_undo(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pending = app(PendingActionService::class);
        $coupon = Coupon::query()->create(['code' => 'TOG1', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'set_coupon_status',
            'args' => ['coupon_id' => $coupon->id, 'is_active' => false],
        ]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $pending->confirm($admin, $action);
        $this->assertFalse($coupon->fresh()->is_active);
        $pending->undo($admin, $action->fresh());
        $this->assertTrue($coupon->fresh()->is_active);
    }

    public function test_create_promotion_resolves_category_and_warns_not_applied(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $category = \Database\Factories\CategoryFactory::new()->create(['name_ar' => 'فئة العروض الفريدة']);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'create_promotion',
            'args' => ['name' => 'عرض test', 'type' => 'percentage_discount', 'value' => 10,
                'targets' => [['kind' => 'category', 'query' => 'فئة العروض الفريدة']]],
        ]);
        $this->assertSame('proposal', $prop->type);
        $this->assertStringContainsString('مش هيتطبق تلقائيًا', json_encode($prop->uiBlock, JSON_UNESCAPED_UNICODE));

        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $res = app(PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $res->type);
        $promo = Promotion::findOrFail($res->data['promotion_id']);
        $this->assertSame('categories', $promo->applies_to);
        $this->assertSame([$category->id], $promo->target_ids);
        $this->assertFalse($res->data['auto_applied']);
        $this->assertStringContainsString('مش هيتطبق تلقائيًا', $res->message);
    }

    public function test_promotion_rejects_mixed_targets_and_bxgy(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        \Database\Factories\CategoryFactory::new()->create(['name_ar' => 'فئة ألف']);
        \Database\Factories\BrandFactory::new()->create(['name_ar' => 'ماركة باء']);

        $mixed = $registry->dispatch($admin, $conv, [
            'name' => 'create_promotion',
            'args' => ['name' => 'x', 'type' => 'fixed_discount', 'value' => 5,
                'targets' => [['kind' => 'category', 'query' => 'فئة ألف'], ['kind' => 'brand', 'query' => 'ماركة باء']]],
        ]);
        $this->assertSame('denied', $mixed->type);

        $bxgy = $registry->dispatch($admin, $conv, [
            'name' => 'create_promotion',
            'args' => ['name' => 'y', 'type' => 'buy_x_get_y'],
        ]);
        $this->assertSame('denied', $bxgy->type);
    }

    public function test_update_and_status_promotion_with_undo(): void
    {
        $admin = $this->admin();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);
        $pending = app(PendingActionService::class);
        $promotion = Promotion::query()->create(['name' => 'عرض قديم', 'type' => 'fixed_discount', 'value' => 20, 'is_active' => true]);

        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'update_promotion',
            'args' => ['promotion_id' => $promotion->id, 'value' => 25],
        ]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $pending->confirm($admin, $action);
        $this->assertSame(25.0, (float) $promotion->fresh()->value);
        $pending->undo($admin, $action->fresh());
        $this->assertSame(20.0, (float) $promotion->fresh()->value);

        $propStatus = $registry->dispatch($admin, $conv, [
            'name' => 'set_promotion_status',
            'args' => ['promotion_id' => $promotion->id, 'is_active' => false],
        ]);
        $statusAction = AiPendingAction::findOrFail($propStatus->data['pending_action_id']);
        $pending->confirm($admin, $statusAction);
        $this->assertFalse($promotion->fresh()->is_active);
        $pending->undo($admin, $statusAction->fresh());
        $this->assertTrue($promotion->fresh()->is_active);
    }
}
