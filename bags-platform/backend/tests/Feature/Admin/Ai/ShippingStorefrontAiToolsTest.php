<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Ai;

use App\Enums\Catalog\BannerPosition;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Inventory\InventoryService;
use App\Services\Shared\SettingService;
use App\Services\Orders\ShippingQuoteService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShippingStorefrontAiToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        foreach ([
            'ai_enabled' => '1',
            'ai_shipping_tools_enabled' => '1',
            'ai_storefront_tools_enabled' => '1',
            'ai_storefront_place_order_enabled' => '0',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['group' => 'ai', 'value' => $value]);
        }
        app(SettingService::class)->clearCache();
    }

    public function test_company_creation_rechecks_permission_at_confirmation(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'shipping.create', 'shipping.update']);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'upsert_shipping_company',
            'args' => ['name' => 'Test Carrier', 'code' => 'test-carrier'],
        ]);
        $action = AiPendingAction::query()->findOrFail($proposal->data['pending_action_id']);
        $actor->revokePermissionTo('shipping.create');

        try {
            app(PendingActionService::class)->confirm($actor, $action);
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseMissing('shipping_companies', ['code' => 'test-carrier']);
    }

    public function test_zone_sync_rejects_overlap_added_after_preview(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'shipping.update']);
        $city = City::factory()->create();
        $zone = ShippingZone::query()->create(['name' => 'Target', 'is_active' => true]);
        $other = ShippingZone::query()->create(['name' => 'Other', 'is_active' => true]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'sync_zone_cities',
            'args' => ['zone_id' => $zone->id, 'city_ids' => [$city->id]],
        ]);
        $action = AiPendingAction::query()->findOrFail($proposal->data['pending_action_id']);
        $other->cities()->attach($city);

        app(PendingActionService::class)->confirm($actor, $action, 'تنفيذ');

        $this->assertFalse($zone->cities()->whereKey($city->id)->exists());
    }

    public function test_rate_delete_requires_a_new_preview_if_it_becomes_the_last_path(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'shipping.delete']);
        $zone = ShippingZone::factory()->create(['is_active' => true]);
        $zone->cities()->attach(City::factory()->create());
        $rate = ShippingRate::factory()->create(['shipping_zone_id' => $zone->id]);
        $other = ShippingRate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'shipping_company_id' => ShippingCompany::factory()->create(['is_active' => true])->id,
        ]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'delete_shipping_rate',
            'args' => ['rate_id' => $rate->id],
        ]);
        $action = AiPendingAction::query()->findOrFail($proposal->data['pending_action_id']);
        $other->delete();

        $result = app(PendingActionService::class)->confirm($actor, $action, 'تنفيذ');

        $this->assertSame('failed', $result->type);
        $this->assertDatabaseHas('shipping_rates', ['id' => $rate->id]);
    }

    public function test_flags_off_hide_the_new_tools(): void
    {
        DB::table('settings')->whereIn('key', ['ai_shipping_tools_enabled', 'ai_storefront_tools_enabled'])->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $actor = $this->staff(['shipping.view', 'orders.view', 'store.manage', 'settings.view', 'ai_agent.execute']);
        $names = array_column(app(ToolRegistry::class)->declarationsFor($actor), 'name');

        $this->assertNotContains('explain_shipping_quote', $names);
        $this->assertNotContains('preview_checkout', $names);
        $this->assertNotContains('place_assisted_order', $names);
    }

    public function test_quote_explanation_matches_the_engine_and_hides_secrets(): void
    {
        $actor = $this->staff(['shipping.view']);
        $conv = $this->conv($actor);
        $city = City::factory()->create(['shipping_cost' => 30, 'is_active' => true]);
        $first = ShippingCompany::factory()->create(['is_active' => true, 'api_credentials' => ['token' => 'super-secret-value']]);
        $second = ShippingCompany::factory()->create(['is_active' => true]);
        $zoneA = ShippingZone::factory()->create();
        $zoneB = ShippingZone::factory()->create();
        $zoneA->cities()->sync([$city->id]);
        $zoneB->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $first->id,
            'shipping_zone_id' => $zoneA->id,
            'base_cost' => 80,
            'cod_fee' => 12,
            'free_shipping_threshold' => 500,
        ]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $second->id,
            'shipping_zone_id' => $zoneB->id,
            'base_cost' => 40,
            'cod_fee' => 5,
        ]);
        $engine = app(ShippingQuoteService::class)->quoteForCity($city->id, 500, true);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $conv, [
            'name' => 'explain_shipping_quote',
            'args' => ['city_id' => $city->id, 'merchandise_total' => 500, 'payment_method' => 'cod'],
        ]);

        $this->assertSame('data', $outcome->type);
        $this->assertSame($engine['cost'], $outcome->data['cost']);
        $this->assertSame($engine['cod_fee'], $outcome->data['cod_fee']);
        $this->assertSame(0.0, $outcome->data['cost']);
        $this->assertSame(12.0, $outcome->data['cod_fee']);
        $this->assertFalse($outcome->data['cod_fee_waived_by_threshold']);
        $this->assertSame('first_active_company_by_id', $outcome->data['winner_rule']);
        $this->assertSame($second->id, $outcome->data['also_matched'][0]['company_id']);
        $encoded = json_encode($outcome->data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('api_credentials', $encoded);
        $this->assertStringNotContainsString('super-secret-value', $encoded);

        $fallbackCity = City::factory()->create(['shipping_cost' => 30, 'is_active' => true]);
        $fallback = app(ToolRegistry::class)->dispatch($actor, $conv, [
            'name' => 'explain_shipping_quote',
            'args' => ['city_id' => $fallbackCity->id, 'merchandise_total' => 10, 'payment_method' => 'cod'],
        ]);
        $this->assertSame(30.0, $fallback->data['cost']);
        $this->assertSame(0.0, $fallback->data['cod_fee']);
        $this->assertSame('city_fallback', $fallback->data['source']);
    }

    public function test_what_if_does_not_write_and_rate_confirm_changes_the_quote(): void
    {
        $actor = $this->staff(['shipping.view', 'shipping.create', 'shipping.update', 'ai_agent.use', 'ai_agent.execute']);
        $conv = $this->conv($actor);
        $city = City::factory()->create(['shipping_cost' => 15]);
        $company = ShippingCompany::factory()->create(['code' => 'bosta']);
        $zone = ShippingZone::factory()->create();
        $zone->cities()->sync([$city->id]);
        $rate = ShippingRate::factory()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'cod_fee' => 0,
            'free_shipping_threshold' => null,
        ]);

        $simulated = app(ToolRegistry::class)->dispatch($actor, $conv, [
            'name' => 'what_if_shipping_change',
            'args' => ['city_id' => $city->id, 'base_cost' => 70],
        ]);
        $this->assertSame('data', $simulated->type);
        $this->assertFalse($simulated->data['saved']);
        $this->assertSame(70.0, $simulated->data['samples'][0]['simulated_cost']);
        $this->assertSame('50.00', (string) $rate->fresh()->base_cost);

        $proposed = app(ToolRegistry::class)->dispatch($actor, $conv, [
            'name' => 'upsert_shipping_rate',
            'args' => ['rate_id' => $rate->id, 'base_cost' => 90, 'cod_fee' => 0, 'estimated_days_min' => 2, 'estimated_days_max' => 4],
        ]);
        $this->assertSame('proposal', $proposed->type);
        $this->assertSame('50.00', (string) $rate->fresh()->base_cost);

        $action = AiPendingAction::query()->findOrFail($proposed->data['pending_action_id']);
        $confirmed = app(PendingActionService::class)->confirm($actor, $action, 'bosta');
        $this->assertSame('result', $confirmed->type, $confirmed->message ?? '');
        $this->assertSame(90.0, app(ShippingQuoteService::class)->quoteForCity($city->id, 10, false)['cost']);
    }

    public function test_shipping_update_requires_permission_and_rejects_secrets(): void
    {
        $actor = $this->staff(['shipping.view', 'shipping.create', 'ai_agent.use', 'ai_agent.execute']);
        $company = ShippingCompany::factory()->create();
        $denied = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'set_shipping_company_status',
            'args' => ['company_id' => $company->id, 'is_active' => false],
        ]);
        $this->assertSame('denied', $denied->type);

        $writer = $this->staff(['shipping.view', 'shipping.update', 'ai_agent.use', 'ai_agent.execute']);
        $secret = app(ToolRegistry::class)->dispatch($writer, $this->conv($writer), [
            'name' => 'upsert_shipping_company',
            'args' => ['company_id' => $company->id, 'name' => 'سرية', 'api_credentials' => ['token' => 'nope']],
        ]);
        $this->assertSame('denied', $secret->type);
        $this->assertStringNotContainsString('nope', (string) $secret->message);
    }

    public function test_banner_status_waits_for_confirmation_and_create_needs_an_image(): void
    {
        $actor = $this->staff(['store.manage', 'ai_agent.use', 'ai_agent.execute']);
        $banner = Banner::factory()->create(['is_active' => true, 'position' => BannerPosition::MainSlider]);
        $proposed = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'set_banner_status',
            'args' => ['banner_id' => $banner->id, 'is_active' => false],
        ]);
        $this->assertSame('proposal', $proposed->type);
        $this->assertTrue($banner->fresh()->is_active);

        $created = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'create_banner',
            'args' => ['title' => 'عرض', 'position' => 'main_slider', 'link_type' => 'none'],
        ]);
        $this->assertSame('denied', $created->type);
        $this->assertDatabaseMissing('banners', ['title' => 'عرض']);
    }

    public function test_banner_link_target_must_still_be_visible_at_confirmation(): void
    {
        $actor = $this->staff(['store.manage', 'ai_agent.use', 'ai_agent.execute']);
        $banner = Banner::factory()->create();
        $category = Category::factory()->create(['is_active' => true]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'update_banner',
            'args' => ['banner_id' => $banner->id, 'link_type' => 'category', 'link_value' => $category->id],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $category->update(['is_active' => false]);
        $action = AiPendingAction::query()->findOrFail($proposal->data['pending_action_id']);

        $result = app(PendingActionService::class)->confirm($actor, $action);

        $this->assertSame('failed', $result->type);
        $this->assertSame($banner->link_value, $banner->fresh()->link_value);
    }

    public function test_checkout_preview_does_not_create_an_order_and_reports_shipping(): void
    {
        $actor = $this->staff(['orders.view']);
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->published()->create()->id,
            'retail_price' => 100,
            'is_active' => true,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5,
            'created_by' => $actor->id,
        ]);
        $cart = Cart::query()->create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $city = City::factory()->create(['shipping_cost' => 10, 'is_active' => true]);
        $company = ShippingCompany::factory()->create();
        $zone = ShippingZone::factory()->create();
        $zone->cities()->sync([$city->id]);
        ShippingRate::factory()->create([
            'shipping_company_id' => $company->id,
            'shipping_zone_id' => $zone->id,
            'base_cost' => 50,
            'cod_fee' => 7,
        ]);

        $before = Order::query()->count();
        $preview = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'preview_checkout',
            'args' => ['cart_id' => $cart->id, 'city_id' => $city->id, 'payment_method' => 'cod'],
        ]);
        $this->assertSame('data', $preview->type);
        $this->assertTrue($preview->data['ok']);
        $this->assertNull($preview->data['payment_url']);
        $this->assertSame('not_integrated', $preview->data['payment_collection']);
        $this->assertFalse($preview->data['would_reserve_stock']);
        $this->assertSame(50.0, $preview->data['preview']['shipping']);
        $this->assertSame(7.0, $preview->data['preview']['cod_fee']);
        $this->assertSame($before, Order::query()->count());

        $draft = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'retail_price' => 80,
        ]);
        $badCart = Cart::query()->create([]);
        $badCart->items()->create(['product_variant_id' => $draft->id, 'qty' => 1]);
        $blocked = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'preview_checkout',
            'args' => ['cart_id' => $badCart->id, 'city_id' => $city->id, 'payment_method' => 'cod'],
        ]);
        $this->assertFalse($blocked->data['ok']);
        $this->assertStringContainsString('غير مُسعَّر', (string) $blocked->data['message']);
        $this->assertSame($before, Order::query()->count());
    }

    public function test_assisted_order_stays_hidden_until_its_flag_and_then_creates_one_order(): void
    {
        $actor = $this->staff(['orders.view', 'orders.update', 'ai_agent.use', 'ai_agent.execute']);
        $names = array_column(app(ToolRegistry::class)->declarationsFor($actor), 'name');
        $this->assertNotContains('place_assisted_order', $names);

        $customer = Customer::factory()->create(['phone' => '01012345678']);
        $empty = Cart::query()->create(['customer_id' => $customer->id]);
        DB::table('settings')->where('key', 'ai_storefront_place_order_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();

        $denied = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'place_assisted_order',
            'args' => [
                'customer_id' => $customer->id,
                'cart_id' => $empty->id,
                'city_id' => City::factory()->create()->id,
                'payment_method' => 'cod',
                'shipping_address_line' => 'شارع 1',
            ],
        ]);
        $this->assertSame('denied', $denied->type);
        $this->assertSame(0, Order::query()->count());

        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->published()->create()->id,
            'retail_price' => 100,
            'is_active' => true,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 4,
            'created_by' => $actor->id,
        ]);
        $empty->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $city = City::factory()->create(['is_active' => true, 'shipping_cost' => 20]);

        $proposed = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'place_assisted_order',
            'args' => [
                'customer_id' => $customer->id,
                'cart_id' => $empty->id,
                'city_id' => $city->id,
                'payment_method' => 'cod',
                'shipping_address_line' => 'شارع الاختبار',
            ],
        ]);
        $this->assertSame('proposal', $proposed->type, $proposed->message ?? '');
        $this->assertSame(0, Order::query()->count());
        $action = AiPendingAction::query()->findOrFail($proposed->data['pending_action_id']);
        $done = app(PendingActionService::class)->confirm($actor, $action, '5678');
        $this->assertSame('result', $done->type, $done->message ?? '');
        $this->assertNull($done->data['payment_url']);
        $this->assertSame(1, Order::query()->count());
        $again = app(PendingActionService::class)->confirm($actor, $action->fresh(), '5678');
        $this->assertSame('اتنفذ قبل كده.', $again->message);
        $this->assertSame(1, Order::query()->count());
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $user;
    }

    private function conv(User $user): AiConversation
    {
        return AiConversation::query()->create(['user_id' => $user->id, 'model' => 'gemini-test']);
    }
}
