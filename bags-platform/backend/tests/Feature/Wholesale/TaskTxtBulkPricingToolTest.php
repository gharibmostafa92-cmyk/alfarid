<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\QuotationStatus;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Wholesale\Tools\BulkPriceQuotationsTool;
use App\Services\Shared\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskTxtBulkPricingToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    private function requestedQuotation(): Quotation
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);

        $quotation = Quotation::factory()->create(['customer_id' => $customer->id, 'status' => QuotationStatus::Requested]);
        $quotation->items()->create([
            'product_variant_id' => $variant->id,
            'qty' => 2,
            'offered_unit_price' => 100,
            'line_total' => 200,
        ]);

        return $quotation->fresh();
    }

    private function tool(): BulkPriceQuotationsTool
    {
        $conversation = AiConversation::create(['user_id' => $this->admin->id, 'channel' => 'web', 'model' => 'test']);
        $builder = new ProposalBuilder(app(SettingService::class));
        $builder->setConversation($conversation);
        app()->instance(ProposalBuilder::class, $builder);

        return app(BulkPriceQuotationsTool::class);
    }

    private function latestAction(): AiPendingAction
    {
        return AiPendingAction::query()->where('tool', 'bulk_price_quotations')->latest('id')->firstOrFail();
    }

    public function test_discount_bounds_are_enforced(): void
    {
        $this->requestedQuotation();

        $this->assertSame('denied', $this->tool()->prepare($this->admin, ['discount_percent' => 81])->type);
        $this->assertSame('denied', $this->tool()->prepare($this->admin, ['discount_percent' => -1])->type);
        $this->assertNotSame('denied', $this->tool()->prepare($this->admin, ['discount_percent' => 10])->type);
    }

    public function test_high_discount_requires_typed_confirmation(): void
    {
        DB::table('settings')->where('key', 'ai_quote_high_risk_discount_percent')->update(['value' => '15']);
        app(SettingService::class)->clearCache();
        $this->requestedQuotation();

        $this->assertNotSame('denied', $this->tool()->prepare($this->admin, ['discount_percent' => 20])->type);
        $action = $this->latestAction();
        $preview = $action->preview ?? [];
        $this->assertSame('تنفيذ', $preview['typed_confirmation'] ?? null);
    }

    public function test_plans_carry_independent_line_prices_and_execute_prices_selection(): void
    {
        $quotation = $this->requestedQuotation();

        $tool = $this->tool();
        $this->assertNotSame('denied', $tool->prepare($this->admin, ['discount_percent' => 10])->type);
        $action = $this->latestAction();

        $plans = $action->args['plans'] ?? [];
        $this->assertNotEmpty($plans);
        foreach ($plans as $plan) {
            foreach ($plan['items'] as $line) {
                $this->assertArrayHasKey('offered_unit_price', $line);
                $this->assertSame(90.0, (float) $line['offered_unit_price']);
            }
        }

        $tool->executeSelected($this->admin, $action->fresh(), ['q'.$quotation->id]);
        $this->assertSame(QuotationStatus::PricedByAdmin, $quotation->fresh()->status);
    }

    public function test_unknown_keys_denied_and_candidates_capped_at_50(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->requestedQuotation();
        }

        $tool = $this->tool();
        $this->assertNotSame('denied', $tool->prepare($this->admin, ['discount_percent' => 5])->type);
        $action = $this->latestAction();

        $this->assertCount(50, $action->args['plans'] ?? []);
        $this->assertSame('denied', $tool->executeSelected($this->admin, $action->fresh(), ['q999999'])->type);
    }
}
