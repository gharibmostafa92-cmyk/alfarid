<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\QuotationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TaskTxtAcceptAllocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private ProductVariant $variant;

    private ProductVariant $variantTwo;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);

        $productTwo = Product::factory()->create(['status' => 'published']);
        $this->variantTwo = $productTwo->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $productTwo->id]);
        $this->variantTwo->update(['retail_price' => 50, 'wholesale_price' => null, 'is_active' => true]);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function stock(int $branchA, int $branchB): void
    {
        StockLevel::forceCreate(['branch_id' => $this->branchA->id, 'product_variant_id' => $this->variant->id, 'on_hand' => $branchA]);
        StockLevel::forceCreate(['branch_id' => $this->branchB->id, 'product_variant_id' => $this->variant->id, 'on_hand' => $branchB]);
    }

    private function pricedQuotation(float $qty): Quotation
    {
        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => $qty]],
        ])->assertCreated()->json('data.id');

        $quotation = Quotation::query()->findOrFail($id);
        $quotation->update(['status' => QuotationStatus::PricedByAdmin]);

        return $quotation->fresh();
    }

    private function onHand(Branch $branch): int
    {
        return (int) StockLevel::query()
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $this->variant->id)
            ->value('on_hand');
    }

    public function test_accept_distributes_variants_across_branches_and_confirms(): void
    {
        // Each line reserves wholly from one branch: variant one lives on A,
        // variant two on B. A single line is never split across branches.
        StockLevel::forceCreate(['branch_id' => $this->branchA->id, 'product_variant_id' => $this->variant->id, 'on_hand' => 5]);
        StockLevel::forceCreate(['branch_id' => $this->branchB->id, 'product_variant_id' => $this->variantTwo->id, 'on_hand' => 5]);

        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 5],
                ['product_variant_id' => $this->variantTwo->id, 'qty' => 5],
            ],
        ])->assertCreated()->json('data.id');
        $quotation = Quotation::query()->findOrFail($id);
        $quotation->update(['status' => QuotationStatus::PricedByAdmin]);

        $response = $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")->assertOk();
        $order = Order::query()->findOrFail($response->json('data.converted_order_id'));

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(5.0, (float) \App\Models\StockMovement::query()
            ->where('branch_id', $this->branchA->id)
            ->where('product_variant_id', $this->variant->id)
            ->where('type', \App\Enums\StockMovementType::Reserve->value)
            ->sum('quantity'));
        $this->assertSame(5.0, (float) \App\Models\StockMovement::query()
            ->where('branch_id', $this->branchB->id)
            ->where('product_variant_id', $this->variantTwo->id)
            ->where('type', \App\Enums\StockMovementType::Reserve->value)
            ->sum('quantity'));
    }

    public function test_single_line_exceeding_any_one_branch_is_rejected(): void
    {
        $this->stock(5, 5);
        $quotation = $this->pricedQuotation(8);

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")->assertUnprocessable();

        $this->assertSame(0, Order::query()->where('customer_id', $this->trader->id)->count());
        $this->assertSame(QuotationStatus::PricedByAdmin, $quotation->fresh()->status);
    }

    public function test_insufficient_stock_rolls_back_order_and_keeps_priced_status(): void
    {
        $this->stock(2, 1);
        $quotation = $this->pricedQuotation(8);

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")->assertUnprocessable();

        $this->assertSame(0, Order::query()->where('customer_id', $this->trader->id)->count());
        $this->assertSame(QuotationStatus::PricedByAdmin, $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->converted_order_id);
        $this->assertSame(2, $this->onHand($this->branchA));
        $this->assertSame(1, $this->onHand($this->branchB));
    }
}
