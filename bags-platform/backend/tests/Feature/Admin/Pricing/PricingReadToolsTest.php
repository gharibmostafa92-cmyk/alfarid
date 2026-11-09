<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\PriceListType;
use App\Enums\Catalog\ProductStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Ai\Pricing\Tools\CompareCustomerPricesTool;
use App\Services\Ai\Pricing\Tools\CouponPerformanceTool;
use App\Services\Ai\Pricing\Tools\ExplainPriceTool;
use App\Services\Ai\Pricing\Tools\ExportPriceListTool;
use App\Services\Ai\Pricing\Tools\GetCouponTool;
use App\Services\Ai\Pricing\Tools\ListCouponsTool;
use App\Services\Ai\Pricing\Tools\ListPriceListsTool;
use App\Services\Ai\Pricing\Tools\ListPromotionsTool;
use App\Services\Ai\Pricing\Tools\MarginRiskReportTool;
use App\Services\Ai\Pricing\Tools\PricingHealthReportTool;
use App\Services\Ai\Pricing\Tools\QuotePriceTool;
use App\Services\Ai\Pricing\Tools\SimulateCartTool;
use App\Services\Ai\Pricing\Tools\WhatIfPriceChangeTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PricingReadToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL = ['price_lists.view', 'customers.view', 'coupons.view', 'promotions.view', 'inventory.all_branches'];

    public function test_export_tool_returns_download_route_without_file_contents(): void
    {
        $actor = $this->staff(['price_lists.export', 'inventory.all_branches']);
        $list = $this->priceList(PriceListType::Retail);

        $out = app(ExportPriceListTool::class)->handle($actor, ['price_list_id' => $list->id]);

        $this->assertSame(['deep_link' => "/api/v1/admin/price-lists/{$list->id}/export"], $out->data);
    }

    public function test_what_if_prices_are_resolved_without_persisting_changes(): void
    {
        $actor = $this->staff(self::ALL);
        $list = $this->priceList(PriceListType::Retail);
        $item = $list->items()->create(['product_variant_id' => $this->variant()->id, 'min_qty' => 1, 'price' => 50]);

        $out = app(WhatIfPriceChangeTool::class)->handle($actor, ['price_list_id' => $list->id, 'percent' => 20]);

        $this->assertSame(50.0, $out->data['sample'][0][3]);
        $this->assertSame(60.0, $out->data['sample'][0][4]);
        $this->assertSame(50.0, (float) $item->fresh()->price);
    }

    public function test_coupon_performance_counts_released_cancelled_orders(): void
    {
        $actor = $this->staff([...self::ALL, 'orders.view']);
        $coupon = Coupon::factory()->create();
        $order = Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'confirmed', 'grand_total' => 75]);
        Order::factory()->create(['coupon_id' => $coupon->id, 'status' => 'cancelled']);
        $coupon->usages()->create(['order_id' => $order->id, 'discount_amount' => 25]);

        $out = app(CouponPerformanceTool::class)->handle($actor, ['code' => strtolower($coupon->code)]);

        $this->assertSame(1, $out->data['uses']);
        $this->assertSame(25.0, $out->data['discount_total']);
        $this->assertSame(75.0, $out->data['order_average']);
        $this->assertSame(50.0, $out->data['cancellation_percent']);
    }

    public function test_margin_report_resolves_list_price_and_coupon_discount(): void
    {
        $actor = $this->staff([...self::ALL, 'products.view_cost']);
        $variant = $this->variant();
        $variant->update(['cost_price' => 70]);
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 80]);
        Coupon::factory()->create(['type' => 'percentage', 'value' => 25, 'min_order_amount' => 0, 'max_discount_amount' => null]);

        $out = app(MarginRiskReportTool::class)->handle($actor, []);

        $this->assertSame(80.0, $out->data['rows'][0]['unit_price']);
        $this->assertSame(20.0, $out->data['rows'][0]['coupon_discount']);
        $this->assertSame(-10.0, $out->data['rows'][0]['margin']);
    }

    public function test_explain_price_returns_waterfall_with_winning_reason(): void
    {
        $actor = $this->staff(self::ALL);
        $variant = $this->variant();
        $customer = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved);
        $list = $this->priceList(PriceListType::CustomerSpecific, customerId: $customer->id);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 340]);

        $outcome = app(ExplainPriceTool::class)->handle($actor, ['sku' => $variant->sku, 'customer_id' => $customer->id]);

        $this->assertSame('data', $outcome->type);
        $this->assertSame(340.0, $outcome->data['final']['unit_price']);
        $this->assertSame('customer_specific', $outcome->data['final']['source']);
        $this->assertSame('won', $outcome->data['layers'][0]['status']);
        $this->assertSame('customer_specific', $outcome->data['layers'][0]['layer']);
        $this->assertSame('price_waterfall', $outcome->uiBlock['type']);
        $this->assertSame('#/pricing?list='.$list->id, $outcome->uiBlock['deep_link']);
    }

    public function test_explain_price_denies_customer_without_customers_view(): void
    {
        $actor = $this->staff(['price_lists.view']);
        $variant = $this->variant();
        $customer = $this->customer(CustomerType::Retail, CustomerStatus::Approved);

        $outcome = app(ExplainPriceTool::class)->handle($actor, ['variant_id' => $variant->id, 'customer_id' => $customer->id]);

        $this->assertSame('denied', $outcome->type);
    }

    public function test_restricted_branch_user_cannot_read_other_branch_list(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $actor = $this->staff(['price_lists.view', 'customers.view', 'coupons.view', 'promotions.view'], $branchA->id);
        $list = $this->priceList(PriceListType::Retail, branchId: $branchB->id);

        $outcome = app(\App\Services\Ai\Pricing\Tools\GetPriceListTool::class)->handle($actor, ['price_list_id' => $list->id]);

        $this->assertSame('denied', $outcome->type);
    }

    public function test_quote_price_returns_tier_ladder_with_savings(): void
    {
        $actor = $this->staff(['price_lists.view', 'inventory.all_branches']);
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 100]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 10, 'price' => 90]);

        $outcome = app(QuotePriceTool::class)->handle($actor, ['variant_id' => $variant->id, 'quantities' => [1, 10, 20]]);

        $this->assertSame('data', $outcome->type);
        $tiers = $outcome->data['tiers'];
        $this->assertSame(100.0, $tiers[0]['unit_price']);
        $this->assertSame(90.0, $tiers[1]['unit_price']);
        $this->assertSame(90.0, $tiers[2]['unit_price']);
        $this->assertSame(10.0, $tiers[1]['saving_pct']);
        $this->assertSame('tier_ladder', $outcome->uiBlock['type']);
    }

    public function test_compare_customer_prices_resolves_each_identity(): void
    {
        $actor = $this->staff(['price_lists.view', 'customers.view', 'inventory.all_branches']);
        $variant = $this->variant();
        $retail = $this->customer(CustomerType::Retail, CustomerStatus::Approved);
        $wholesale = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved);
        $list = $this->priceList(PriceListType::Wholesale);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 60]);

        $outcome = app(CompareCustomerPricesTool::class)->handle($actor, ['variant_id' => $variant->id, 'customer_ids' => [$retail->id, $wholesale->id]]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(2, $outcome->data['prices']);
        $this->assertSame(100.0, $outcome->data['prices'][0]['unit_price']);
        $this->assertSame(60.0, $outcome->data['prices'][1]['unit_price']);
        $this->assertSame('table', $outcome->uiBlock['type']);
    }

    public function test_list_price_lists_scopes_branch_for_restricted_user(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $this->priceList(PriceListType::Retail, branchId: $branchA->id);
        $this->priceList(PriceListType::Retail, branchId: $branchB->id);
        $actor = $this->staff(['price_lists.view'], $branchA->id);

        $outcome = app(ListPriceListsTool::class)->handle($actor, []);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['price_lists']);
        $this->assertSame($branchA->id, $outcome->data['price_lists'][0]['branch_id']);
    }

    public function test_list_coupons_flags_near_limit_and_get_coupon_status(): void
    {
        $actor = $this->staff(['coupons.view']);
        Coupon::query()->create(['code' => 'NEAR90', 'type' => 'percentage', 'value' => 10, 'usage_limit' => 100, 'used_count' => 95, 'is_active' => true]);
        Coupon::query()->create(['code' => 'FAR10', 'type' => 'fixed', 'value' => 5, 'usage_limit' => 100, 'used_count' => 3, 'is_active' => true]);

        $outcome = app(ListCouponsTool::class)->handle($actor, ['near_limit' => true]);

        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['coupons']);
        $this->assertSame('NEAR90', $outcome->data['coupons'][0]['code']);

        $expired = Coupon::query()->create(['code' => 'OLD99', 'type' => 'fixed', 'value' => 5, 'is_active' => true, 'ends_at' => now()->subDay()]);
        $got = app(GetCouponTool::class)->handle($actor, ['code' => 'old99']);
        $this->assertSame('data', $got->type);
        $this->assertSame('منتهي', $got->data['coupon']['status']);
    }

    public function test_list_promotions_badges_not_auto_applied(): void
    {
        $actor = $this->staff(['promotions.view']);
        Promotion::query()->create(['name' => 'عرض test', 'type' => 'percentage_discount', 'value' => 10, 'is_active' => true]);

        $outcome = app(ListPromotionsTool::class)->handle($actor, []);

        $this->assertSame('data', $outcome->type);
        $this->assertFalse($outcome->data['promotions'][0]['auto_applied']);
        $this->assertContains('غير مُفعَّل تلقائيًا', $outcome->uiBlock['rows'][0]);
        $this->assertStringContainsString('مش بتتطبق تلقائيًا', $outcome->data['notice']);
    }

    public function test_simulate_cart_totals_with_coupon_and_theoretical_promotion_note(): void
    {
        $actor = $this->staff(['price_lists.view', 'coupons.view', 'promotions.view', 'inventory.all_branches']);
        $variant = $this->variant(100, 80);
        Promotion::query()->create(['name' => 'عرض الكل', 'type' => 'percentage_discount', 'value' => 5, 'applies_to' => 'all', 'is_active' => true]);
        $expired = Coupon::query()->create(['code' => 'DEAD1', 'type' => 'fixed', 'value' => 20, 'is_active' => true, 'ends_at' => now()->subDay()]);
        $live = Coupon::query()->create(['code' => 'LIVE20', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);

        $rejected = app(SimulateCartTool::class)->handle($actor, [
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'coupon_code' => 'dead1',
        ]);
        $this->assertSame('data', $rejected->type);
        $this->assertSame(200.0, $rejected->data['subtotal']);
        $this->assertSame(0.0, $rejected->data['discount']);
        $this->assertStringContainsString('منتهي', implode(' ', $rejected->data['notes']));

        $applied = app(SimulateCartTool::class)->handle($actor, [
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'coupon_code' => 'live20',
        ]);
        $this->assertSame(20.0, $applied->data['discount']);
        $this->assertSame(180.0, $applied->data['total']);
        $this->assertStringContainsString('لن يُطبَّق في الدفع حاليًا', implode(' ', $applied->data['notes']));
        $this->assertSame(0, Coupon::query()->where('code', 'LIVE20')->value('used_count'));
    }

    public function test_health_report_flags_dead_and_expired_lists(): void
    {
        $actor = $this->staff(self::ALL);
        $pending = $this->customer(CustomerType::Wholesale, CustomerStatus::Pending);
        $dead = $this->priceList(PriceListType::CustomerSpecific, customerId: $pending->id);
        $expired = $this->priceList(PriceListType::Retail);
        $expired->update(['ends_at' => now()->subDay()]);

        $outcome = app(PricingHealthReportTool::class)->handle($actor, []);

        $this->assertSame('data', $outcome->type);
        $this->assertSame('checklist', $outcome->uiBlock['type']);
        $byKey = collect($outcome->data['checks'])->keyBy('key');
        $this->assertSame(1, $byKey['dead_lists']['count']);
        $this->assertContains($dead->id, $byKey['dead_lists']['list_ids']);
        $this->assertSame(1, $byKey['expired_active']['count']);
        $this->assertArrayHasKey('score', $outcome->data);
    }

    public function test_cost_is_hidden_from_unauthorized_actor(): void
    {
        $actor = $this->staff(self::ALL);
        $variant = $this->variant(100, 80);
        $variant->update(['cost_price' => 90]);
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 50]);

        $outcome = app(PricingHealthReportTool::class)->handle($actor, []);

        $this->assertSame('data', $outcome->type);
        $byKey = collect($outcome->data['checks'])->keyBy('key');
        $this->assertSame('critical', $byKey['below_cost']['severity']);
        $this->assertStringContainsString('محمية', $byKey['below_cost']['detail'] ?? '');
        $this->assertStringNotContainsString('cost_price', json_encode($outcome->data, JSON_UNESCAPED_UNICODE));
    }

    // ---------- Helpers ----------

    private function variant(float $retail = 100, float $wholesale = 80): ProductVariant
    {
        $category = Category::query()->firstOrCreate(['slug' => 'test-cat'], ['name_ar' => 'قسم', 'name_en' => 'Cat', 'sort_order' => 1, 'is_active' => true]);
        $product = Product::query()->create([
            'code' => 'P'.uniqid(), 'category_id' => $category->id, 'name_ar' => 'منتج', 'name_en' => 'P',
            'slug' => 'p-'.uniqid(), 'status' => ProductStatus::Published, 'sales_channel' => 'both',
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id, 'sku' => 'SKU-'.uniqid(), 'retail_price' => $retail,
            'wholesale_price' => $wholesale, 'is_active' => true,
        ]);
    }

    private function priceList(PriceListType $type, ?int $customerId = null, ?int $customerGroupId = null, ?int $branchId = null, int $priority = 0): PriceList
    {
        return PriceList::query()->create([
            'name' => 'List '.uniqid(),
            'type' => $type,
            'customer_id' => $customerId,
            'customer_group_id' => $customerGroupId,
            'branch_id' => $branchId,
            'priority' => $priority,
        ]);
    }

    private function customer(CustomerType $type, CustomerStatus $status): Customer
    {
        return Customer::query()->create([
            'name' => 'عميل '.uniqid(),
            'email' => uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'password' => Hash::make('password'),
            'type' => $type,
            'status' => $status,
        ]);
    }

    private function staff(array $permissions, ?int $branchId = null): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create(['branch_id' => $branchId]);
        $staff->givePermissionTo($permissions);

        return $staff;
    }
}
