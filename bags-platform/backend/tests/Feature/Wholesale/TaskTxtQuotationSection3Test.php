<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Wholesale\QuotationStatus;
use App\Events\Wholesale\QuotationPriced;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\Wholesale\Tools\ExpireQuotationTool;
use App\Services\Ai\Wholesale\Tools\ExtendQuotationValidityTool;
use App\Services\Wholesale\QuotationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * task.txt §3: عروض الأسعار (التاجر + الأدمن)
 */
class TaskTxtQuotationSection3Test extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private User $admin;

    private string $adminToken;

    private ProductVariant $variant;

    private ProductVariant $secondVariant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 50000,
        ]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => 80, 'is_active' => true]);

        $productTwo = Product::factory()->create(['status' => 'published']);
        $this->secondVariant = $productTwo->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $productTwo->id]);
        $this->secondVariant->update(['retail_price' => 120, 'wholesale_price' => 90, 'is_active' => true]);
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->adminToken);
    }

    public function test_trader_create_validation_limits_inactive_unpriced_and_duplicate_lines(): void
    {
        // Empty items
        $this->asTrader()->postJson('/api/v1/wholesale/quotations', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        // > 100 items
        $tooMany = array_fill(0, 101, ['product_variant_id' => $this->variant->id, 'qty' => 1]);
        $this->asTrader()->postJson('/api/v1/wholesale/quotations', ['items' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        // qty = 0, negative, or > 1,000,000
        foreach ([0, -5, 1000001] as $badQty) {
            $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
                'items' => [['product_variant_id' => $this->variant->id, 'qty' => $badQty]],
            ])->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
        }

        // Inactive or deleted variant -> 422
        $inactive = ProductVariant::factory()->create(['retail_price' => 100, 'is_active' => false]);
        $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $inactive->id, 'qty' => 2]],
        ])->assertStatus(422);

        $deleted = ProductVariant::factory()->create(['retail_price' => 100, 'is_active' => true]);
        $deletedId = $deleted->id;
        $deleted->delete();
        $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $deletedId, 'qty' => 2]],
        ])->assertStatus(422);

        // Unpriced variant -> 422
        $unpriced = ProductVariant::factory()->create(['retail_price' => null, 'wholesale_price' => null, 'is_active' => true]);
        $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $unpriced->id, 'qty' => 2]],
        ])->assertStatus(422);

        // Same variant in two lines: currently kept as two separate lines (owner decision documented)
        $res = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
                ['product_variant_id' => $this->variant->id, 'qty' => 3],
            ],
        ])->assertCreated();
        $this->assertCount(2, $res->json('data.items'));
    }

    public function test_trader_update_increments_revision_on_items_change_and_allows_clearing_customer_notes_to_null(): void
    {
        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'customer_notes' => 'ملاحظة أولى',
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json('data.id');

        $quotation = Quotation::query()->findOrFail($id);
        $initialRevision = (int) $quotation->revision;
        $this->assertSame('ملاحظة أولى', $quotation->customer_notes);

        // Updating items increments revision
        $this->asTrader()->putJson("/api/v1/wholesale/quotations/{$id}", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
        ])->assertOk();
        $this->assertSame($initialRevision + 1, (int) $quotation->fresh()->revision);
        $this->assertSame('ملاحظة أولى', $quotation->fresh()->customer_notes);

        // Explicitly passing customer_notes = null clears the note
        $this->asTrader()->putJson("/api/v1/wholesale/quotations/{$id}", [
            'customer_notes' => null,
        ])->assertOk();
        $this->assertNull($quotation->fresh()->customer_notes);
    }

    public function test_quotation_numbers_are_unique_across_creations(): void
    {
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $q = app(QuotationService::class)->create($this->trader, [
                'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            ]);
            $numbers[] = $q->quotation_number;
        }

        $this->assertCount(5, array_unique($numbers));
    }

    public function test_admin_pricing_rollbacks_on_foreign_item_or_excessive_discount_and_locks_quotation_row(): void
    {
        $service = app(QuotationService::class);
        $q1 = $service->create($this->trader, [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
        ]);
        $service->submit($q1);

        $q2 = $service->create($this->trader, [
            'items' => [['product_variant_id' => $this->secondVariant->id, 'qty' => 2]],
        ]);
        $service->submit($q2);

        $q1Item = $q1->items()->firstOrFail();
        $q2Item = $q2->items()->firstOrFail();

        // 1. Item ID not belonging to quotation rolls back earlier item updates
        try {
            $service->price($q1->fresh(), [
                'items' => [
                    ['id' => $q1Item->id, 'offered_unit_price' => 50],
                    ['id' => $q2Item->id, 'offered_unit_price' => 50],
                ],
            ]);
            $this->fail('Expected BusinessException for foreign item ID.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('لا ينتمي', $e->getMessage());
        }
        $this->assertSame(80.0, (float) $q1Item->fresh()->offered_unit_price);
        $this->assertSame(QuotationStatus::Requested, $q1->fresh()->status);

        // 2. Discount greater than subtotal rolls back item price changes
        try {
            $service->price($q1->fresh(), [
                'items' => [['id' => $q1Item->id, 'offered_unit_price' => 60]],
                'discount_amount' => 500,
            ]);
            $this->fail('Expected BusinessException when discount exceeds subtotal.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('سالبًا', $e->getMessage());
        }
        $this->assertSame(80.0, (float) $q1Item->fresh()->offered_unit_price);
        $this->assertSame(QuotationStatus::Requested, $q1->fresh()->status);

        // 3. Past valid_until rejected via Admin REST endpoint
        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$q1->id}/price", [
            'valid_until' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('valid_until');

        // 4. Price = 0 and null valid_until allowed; QuotationPriced dispatched once; row locked FOR UPDATE
        Event::fake([QuotationPriced::class]);
        DB::enableQueryLog();

        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$q1->id}/price", [
            'items' => [['id' => $q1Item->id, 'offered_unit_price' => 0]],
            'valid_until' => null,
        ])->assertOk()
            ->assertJsonPath('data.grand_total', '0.00')
            ->assertJsonPath('data.valid_until', null);

        $locked = collect(DB::getQueryLog())->contains(
            fn ($entry) => str_contains((string) $entry['query'], 'quotations')
                && str_contains(strtolower((string) $entry['query']), 'for update')
        );
        $this->assertTrue($locked, 'price() must lock the quotation row FOR UPDATE to prevent concurrent admin pricing races.');
        Event::assertDispatchedTimes(QuotationPriced::class, 1);
    }

    public function test_acceptance_warnings_ai_version_guards_and_undo_blocked_after_acceptance(): void
    {
        $branch = Branch::factory()->create();
        StockLevel::forceCreate([
            'branch_id' => $branch->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 3,
        ]);

        $this->trader->update(['credit_limit' => 100]);
        $service = app(QuotationService::class);
        $q = $service->create($this->trader, [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
        ]);
        $service->submit($q);
        $priced = $service->price($q->fresh(), [
            'valid_until' => now()->addDays(5)->toDateString(),
        ]);

        // 1. acceptanceWarnings warns on both credit limit breach and stock shortage
        $warnings = $service->acceptanceWarnings($priced);
        $joined = implode(' | ', $warnings);
        $this->assertStringContainsString('حد الائتمان', $joined);
        $this->assertStringContainsString($this->variant->sku, $joined);

        // 2. AI tools ExtendQuotationValidityTool & ExpireQuotationTool reject stale QuotationVersion::hash
        $staleExtendAction = new AiPendingAction([
            'args' => ['quotation_id' => $priced->id, 'valid_until' => now()->addDays(15)->toDateString()],
            'expected_versions' => ['q:'.$priced->id => 'stale-hash'],
        ]);
        $extendOut = app(ExtendQuotationValidityTool::class)->execute($this->admin, $staleExtendAction);
        $this->assertSame('denied', $extendOut->type);

        $staleExpireAction = new AiPendingAction([
            'args' => ['quotation_id' => $priced->id],
            'expected_versions' => ['q:'.$priced->id => 'stale-hash'],
        ]);
        $expireOut = app(ExpireQuotationTool::class)->execute($this->admin, $staleExpireAction);
        $this->assertSame('denied', $expireOut->type);

        // 3. Undo (restorePricedSnapshot) is blocked after trader accepts the quotation
        $this->trader->update(['credit_limit' => 100000]);
        StockLevel::query()->where('product_variant_id', $this->variant->id)->update(['on_hand' => 50]);

        $item = $priced->items()->firstOrFail();
        $accepted = $service->accept($priced->fresh());
        $this->assertSame(QuotationStatus::ConvertedToOrder, $accepted->status);

        try {
            $service->restorePricedSnapshot($accepted->fresh(), [
                'valid_until' => null,
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'items' => [['id' => $item->id, 'offered_unit_price' => '80.00']],
            ]);
            $this->fail('Undo must be rejected after trader accepts.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('التراجع متاح فقط', $e->getMessage());
        }
    }
}
