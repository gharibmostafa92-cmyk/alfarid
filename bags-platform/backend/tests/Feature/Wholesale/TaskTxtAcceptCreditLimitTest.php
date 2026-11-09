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
use App\Models\Treasury;
use App\Enums\Finance\TreasuryType;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TaskTxtAcceptCreditLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private ProductVariant $variant;

    private StockLevel $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 1000,
        ]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);
        $this->stock = StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 100,
        ]);
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function openDebt(float $total): void
    {
        Order::factory()->create([
            'customer_id' => $this->trader->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => $total,
            'subtotal' => $total,
        ]);
    }

    /** Quotation totals 200.00 (2 x 100). */
    private function pricedQuotation(): Quotation
    {
        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json('data.id');

        $quotation = Quotation::query()->findOrFail($id);
        $quotation->update(['status' => QuotationStatus::PricedByAdmin]);

        return $quotation;
    }

    public function test_accept_at_exactly_the_credit_limit_succeeds(): void
    {
        $this->openDebt(800);
        $quotation = $this->pricedQuotation();

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")
            ->assertOk()->assertJsonPath('data.status', 'converted_to_order');
    }

    public function test_accept_a_piastre_over_the_limit_rolls_back_everything(): void
    {
        $this->openDebt(800.01);
        $quotation = $this->pricedQuotation();
        $ordersBefore = Order::query()->where('customer_id', $this->trader->id)->count();

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")
            ->assertStatus(422)->assertJsonPath('code', 'credit_limit_exceeded');

        $this->assertSame($ordersBefore, Order::query()->where('customer_id', $this->trader->id)->count());
        $this->assertSame(QuotationStatus::PricedByAdmin, $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->converted_order_id);
        $this->assertSame(100, (int) $this->stock->fresh()->on_hand);
    }

    public function test_zero_limit_rejects_credit_acceptance(): void
    {
        // The column is decimal(12,2) default 0 and NOT NULL, so a null limit
        // cannot occur: the "null means unlimited" question is settled by schema.
        $columns = \Illuminate\Support\Facades\Schema::getColumns('customers');
        $credit = collect($columns)->firstWhere('name', 'credit_limit');
        $this->assertFalse((bool) $credit['nullable']);

        $quotation = $this->pricedQuotation();

        foreach ([0, '0.00'] as $limit) {
            $this->trader->update(['credit_limit' => $limit]);

            $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")
                ->assertStatus(422)->assertJsonPath('code', 'credit_limit_exceeded');
        }

        $this->assertSame(QuotationStatus::PricedByAdmin, $quotation->fresh()->status);
    }

    public function test_credit_balance_covers_acceptance_despite_zero_limit(): void
    {
        $this->trader->update(['credit_limit' => 0]);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $bank = Treasury::create([
            'name' => 'بنك', 'code' => 'CR-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);

        // A linked notice on an explicitly cancelled order survives as free
        // credit, driving the outstanding negative (verified pattern).
        $order = Order::factory()->create([
            'customer_id' => $this->trader->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Pending,
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->trader->id,
            'order_id' => $order->id,
            'amount' => 400,
            'status' => 'pending_review',
        ]);
        app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $bank->id);
        app(\App\Services\Orders\OrderService::class)->changeStatus(
            $order->fresh(), OrderStatus::Cancelled, null, null, null, true, true
        );
        $this->assertSame(-400.0, app(WholesalePaymentNoticeService::class)->outstandingBalance($this->trader));

        $quotation = $this->pricedQuotation();

        // -400 + 200 > 0 is false, so the credit balance covers the order.
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")
            ->assertOk()->assertJsonPath('data.status', 'converted_to_order');
    }
}
