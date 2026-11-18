<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Wholesale\QuotationStatus;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2ExecutionSlice3PricingAndLogicTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_wholesale_tools_enabled',
            'ai_pricing_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
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

    private function makeVariant(array $attrs = []): ProductVariant
    {
        $cat = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['category_id' => $cat->id, 'brand_id' => $brand->id]);

        return ProductVariant::factory()->create(array_merge([
            'product_id' => $product->id,
            'is_active' => true,
            'retail_price' => 200,
            'wholesale_price' => 150,
            'cost_price' => 100,
        ], $attrs));
    }

    public function test_what_if_price_change_does_not_execute_db_updates_on_price_list_items(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.pricing', 'inventory.all_branches', 'price_lists.view']);
        $variant = $this->makeVariant();
        $list = PriceList::create(['name' => 'جملة', 'type' => 'wholesale', 'is_active' => true, 'priority' => 10]);
        $item = PriceListItem::create([
            'price_list_id' => $list->id,
            'product_variant_id' => $variant->id,
            'min_qty' => 1,
            'price' => 140,
        ]);

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'what_if_price_change',
            'args' => ['price_list_id' => $list->id, 'percent' => 10],
        ]);

        $this->assertSame('data', $outcome->type, (string) $outcome->message);
        $this->assertSame('140.00', number_format((float) $item->fresh()->price, 2, '.', ''));
        $updateQueries = array_filter($queries, fn (string $sql) => str_contains(strtolower($sql), 'update "price_list_items"') || str_contains(strtolower($sql), 'update price_list_items'));
        $this->assertSame([], array_values($updateQueries), 'Read tool must never UPDATE price_list_items');
    }

    public function test_bulk_price_quotations_skips_quotations_with_missing_list_price(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.wholesale', 'inventory.all_branches', 'quotations.price']);
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);

        // Variant with NO retail_price and NO wholesale_price -> list_price is null
        $unpricedVariant = $this->makeVariant(['retail_price' => null, 'wholesale_price' => null, 'cost_price' => 50]);
        $pricedVariant = $this->makeVariant(['retail_price' => 200, 'wholesale_price' => 150, 'cost_price' => 80]);

        $unpricedQuote = Quotation::create(['quotation_number' => 'QT-NULL-1', 'customer_id' => $customer->id, 'status' => QuotationStatus::Requested]);
        QuotationItem::create(['quotation_id' => $unpricedQuote->id, 'product_variant_id' => $unpricedVariant->id, 'qty' => 5]);

        $pricedQuote = Quotation::create(['quotation_number' => 'QT-OK-1', 'customer_id' => $customer->id, 'status' => QuotationStatus::Requested]);
        QuotationItem::create(['quotation_id' => $pricedQuote->id, 'product_variant_id' => $pricedVariant->id, 'qty' => 5]);

        $belowCostVariant = $this->makeVariant(['retail_price' => 200, 'wholesale_price' => 150, 'cost_price' => 145]);
        $belowCostQuote = Quotation::create(['quotation_number' => 'QT-COST-1', 'customer_id' => $customer->id, 'status' => QuotationStatus::Requested]);
        QuotationItem::create(['quotation_id' => $belowCostQuote->id, 'product_variant_id' => $belowCostVariant->id, 'qty' => 5]);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'bulk_price_quotations',
            'args' => ['discount_percent' => 10],
        ]);

        $this->assertSame('proposal', $outcome->type, (string) $outcome->message);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $plans = $action->args['plans'] ?? [];
        $this->assertArrayNotHasKey('q'.$unpricedQuote->id, $plans);
        $this->assertArrayHasKey('q'.$pricedQuote->id, $plans);
        $this->assertArrayNotHasKey('q'.$belowCostQuote->id, $plans);
        $this->assertNotEmpty($outcome->uiBlock['warnings'] ?? []);
    }

    public function test_quote_price_handles_missing_qty_1_tier_without_error(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.pricing', 'inventory.all_branches', 'price_lists.view']);
        $variant = $this->makeVariant(['sku' => 'SKU-TIER-12', 'retail_price' => 0, 'wholesale_price' => null]);
        $list = PriceList::create(['name' => 'دستة', 'type' => 'retail', 'is_active' => true, 'priority' => 10]);
        PriceListItem::create([
            'price_list_id' => $list->id,
            'product_variant_id' => $variant->id,
            'min_qty' => 12,
            'price' => 100,
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'quote_price',
            'args' => ['sku' => 'SKU-TIER-12'],
        ]);

        $this->assertSame('data', $outcome->type, (string) $outcome->message);
    }

    public function test_create_quotation_preserves_numeric_sku_and_parses_leading_quantity(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.wholesale', 'inventory.all_branches', 'quotations.price', 'customers.view']);
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $variant = $this->makeVariant(['sku' => 'BAG-205']);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'create_quotation_for_customer',
            'args' => ['customer_id' => $customer->id, 'text' => '15 BAG-205'],
        ]);

        $this->assertSame('proposal', $outcome->type, (string) $outcome->message);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame($variant->id, $action->args['items'][0]['product_variant_id']);
        $this->assertSame(15.0, (float) $action->args['items'][0]['qty']);
    }

    public function test_create_promotion_execute_blocks_buy_x_get_y_and_whitelists_args(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.pricing', 'inventory.all_branches', 'promotions.create']);
        $action = AiPendingAction::create([
            'conversation_id' => $this->conv($actor)->id,
            'user_id' => $actor->id,
            'tool' => 'create_promotion',
            'args' => [
                'name' => 'عرض محظور',
                'type' => 'buy_x_get_y',
                'discount_value' => 10,
                'is_active' => true,
                'unexpected_ui_field' => 'ignored',
            ],
            'expected_versions' => [],
            'preview' => [],
            'args_hash' => 'h-promo',
            'risk' => 'medium',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        $outcome = app(ToolRegistry::class)->executeWrite($actor, $action, ['channel' => 'ai']);
        $this->assertSame('denied', $outcome->type);
        $this->assertDatabaseMissing('promotions', ['name' => 'عرض محظور']);
    }

    public function test_reconcile_bank_statement_table_structure_pricing_health_retail_and_get_quotation_hidden_cost(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'ai_agent.pricing', 'inventory.all_branches', 'payment_notices.view', 'price_lists.view', 'quotations.view', 'inventory.view_cost']);

        // 1. ReconcileBankStatementTool returns proper columns + list rows
        $bank = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'reconcile_bank_statement',
            'args' => ['rows' => [['amount' => 500, 'reference' => 'REF-X', 'date' => '2026-11-18', 'description' => 'تحويل']]],
        ]);
        $this->assertSame('data', $bank->type);
        $this->assertSame('table', $bank->uiBlock['type']);
        $this->assertArrayHasKey('columns', $bank->uiBlock);
        $this->assertTrue(array_is_list($bank->uiBlock['rows']));

        // 2. PricingHealthReportTool does NOT flag a Retail list item with price == retail_price as inversion
        $variant = $this->makeVariant(['retail_price' => 200, 'wholesale_price' => 150, 'cost_price' => 50]);
        $retailList = PriceList::create(['name' => 'قطاعي', 'type' => 'retail', 'is_active' => true, 'priority' => 10]);
        PriceListItem::create(['price_list_id' => $retailList->id, 'product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 200]);

        $health = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'pricing_health_report',
            'args' => [],
        ]);
        $this->assertSame('data', $health->type);
        $inversionCheck = collect($health->data['checks'] ?? [])->firstWhere('key', 'wholesale_gte_retail');
        $this->assertSame(0, $inversionCheck['count'] ?? -1);

        // 3. GetQuotationTool hides protected costs without inventing a margin.
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $quote = Quotation::create(['quotation_number' => 'QT-COST-1', 'customer_id' => $customer->id, 'status' => QuotationStatus::Requested]);
        $hiddenCostVariant = $this->makeVariant(['sku' => 'SKU-HIDDEN-COST', 'cost_price' => 100]);
        QuotationItem::create(['quotation_id' => $quote->id, 'product_variant_id' => $hiddenCostVariant->id, 'qty' => 2, 'offered_unit_price' => 150]);
        $quotationActor = $this->staff(['ai_agent.use', 'ai_agent.wholesale', 'ai_agent.pricing', 'inventory.all_branches', 'payment_notices.view', 'price_lists.view', 'quotations.view']);

        $qOutcome = app(ToolRegistry::class)->dispatch($quotationActor, $this->conv($quotationActor), [
            'name' => 'get_quotation',
            'args' => ['quotation_id' => $quote->id],
        ]);
        $this->assertSame('data', $qOutcome->type);
        $this->assertNull($qOutcome->data['lines'][0]['cost']);
        $this->assertNull($qOutcome->data['lines'][0]['margin_percent']);
    }
}
