<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReturnService;
use App\Services\ShipmentService;
use App\Services\WalletService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WalletCheckoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $customer;

    private ProductVariant $variant;

    private City $city;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        // المحفظة طريقة دفع من الإعدادات فقط منذ L3 — تُفعَّل هنا صراحة
        config(['store.payment_methods' => ['cod', 'wallet']]);
        $this->customer = Customer::factory()->create();
        $this->token = $this->customer->createToken('wallet-review')->plainTextToken;
        $this->city = City::factory()->create(['shipping_cost' => 0]);
        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'is_active' => true]);
        StockLevel::forceCreate(['branch_id' => Branch::factory()->create()->id, 'product_variant_id' => $this->variant->id, 'on_hand' => 10]);
    }

    private function fund(float $amount): void
    {
        app(WalletService::class)->record($this->customer->id, $amount, 'opening', 'customer', $this->customer->id);
    }

    private function cart(): void
    {
        Auth::forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/store/cart/items', ['product_variant_id' => $this->variant->id, 'qty' => 1])->assertOk();
    }

    private function payload(): array
    {
        return ['city_id' => $this->city->id, 'shipping_address_line' => 'عنوان العميل', 'payment_method' => 'wallet'];
    }

    public function test_checkout_debits_wallet_atomically_and_retry_returns_same_paid_order(): void
    {
        $this->fund(150);
        $this->cart();
        $id = $this->postJson('/api/v1/store/checkout', $this->payload(), ['Idempotency-Key' => 'wallet-order-1'])->assertCreated()->assertJsonPath('data.payment_status', 'paid')->json('data.id');
        $this->postJson('/api/v1/store/checkout', $this->payload(), ['Idempotency-Key' => 'wallet-order-1'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame('50.00', $this->customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('customer_wallet_transactions', ['reference_type' => 'order', 'reference_id' => $id, 'type' => 'checkout_payment', 'amount' => -100, 'balance_after' => 50]);
        $this->assertSame(1, CustomerWalletTransaction::where('type', 'checkout_payment')->count());
        $this->getJson('/api/v1/store/account/wallet')->assertOk()->assertJsonPath('balance', '50.00')->assertJsonCount(2, 'transactions.data');
    }

    public function test_insufficient_wallet_balance_rolls_back_order_stock_reservation_and_cart(): void
    {
        $this->fund(50);
        $this->cart();
        $this->postJson('/api/v1/store/checkout', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0.0, (float) StockLevel::sum('reserved'));
        $this->assertSame('50.00', $this->customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseMissing('customer_wallet_transactions', ['type' => 'checkout_payment']);
    }

    public function test_cancellation_returns_wallet_payment_once_and_releases_stock(): void
    {
        $this->fund(150);
        $this->cart();
        $id = $this->postJson('/api/v1/store/checkout', $this->payload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/store/account/orders/{$id}/cancel")->assertOk()->assertJsonPath('data.payment_status', 'refunded');
        $this->postJson("/api/v1/store/account/orders/{$id}/cancel")->assertUnprocessable();
        $this->assertSame('150.00', $this->customer->fresh()->wallet_balance);
        $this->assertSame(1, CustomerWalletTransaction::where('type', 'order_reversal')->count());
        $this->assertSame(0.0, (float) StockLevel::sum('reserved'));
        $this->assertDatabaseHas('orders', ['id' => $id, 'requires_refund' => false]);
    }

    public function test_stock_failure_does_not_debit_wallet(): void
    {
        $this->fund(150);
        $this->cart();
        StockLevel::query()->update(['on_hand' => 0]);
        $this->postJson('/api/v1/store/checkout', $this->payload())->assertUnprocessable();
        $this->assertSame('150.00', $this->customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customer_wallet_transactions', 1);
    }

    public function test_guest_cannot_choose_wallet_even_if_it_is_misconfigured_as_public_payment_method(): void
    {
        config(['store.payment_methods' => ['cod', 'wallet']]);
        Auth::forgetGuards();
        $this->postJson('/api/v1/store/checkout', [...$this->payload(), 'recipient_name' => 'ضيف', 'recipient_phone' => '01001234567'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_wallet_ledger_is_scoped_to_the_authenticated_customer(): void
    {
        $this->fund(150);
        $other = Customer::factory()->create();
        Auth::forgetGuards();
        $this->withToken($other->createToken('review')->plainTextToken)->getJson('/api/v1/store/account/wallet')->assertOk()->assertJsonPath('balance', '0.00')->assertJsonCount(0, 'transactions.data');
    }

    public function test_wallet_covers_shipping_and_does_not_charge_cod_fees(): void
    {
        $this->city->update(['shipping_cost' => 20]);
        $this->fund(150);
        $this->cart();
        $this->postJson('/api/v1/store/checkout', $this->payload())->assertCreated()
            ->assertJsonPath('data.grand_total', '120.00')->assertJsonPath('data.cod_fee', '0.00');
        $this->assertSame('30.00', $this->customer->fresh()->wallet_balance);
    }

    public function test_wallet_paid_order_rma_refunds_once_without_reversing_the_checkout_again(): void
    {
        $this->fund(150);
        $this->cart();
        $id = $this->postJson('/api/v1/store/checkout', $this->payload())->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        $staff = User::factory()->create();
        $orders = app(OrderService::class);
        $orders->changeStatus($order, OrderStatus::Confirmed, $staff);
        $company = ShippingCompany::factory()->create();
        app(ShipmentService::class)->createForOrder($order->fresh(), $company->id, null, $staff);
        $orders->changeStatus($order->fresh(), OrderStatus::Delivered, $staff);
        $returns = app(ReturnService::class);
        $return = $returns->createReturnRequest($order->fresh(), ['reason' => 'عيب', 'items' => [['order_item_id' => $order->items->first()->id, 'requested_qty' => 1]]]);
        $returns->approve($return, $staff, ['branch_id' => $order->fresh()->allocated_branch_id]);
        $returns->receive($return, $staff, ['items' => [['id' => $return->items->first()->id, 'received_good_qty' => 1, 'received_damaged_qty' => 0]]]);
        $returns->refund($return, $staff, ['refund_method' => 'wallet']);
        $this->assertSame('150.00', $this->customer->fresh()->wallet_balance);
        $this->assertSame(0, CustomerWalletTransaction::where('type', 'order_reversal')->count());
        $this->assertSame(1, CustomerWalletTransaction::where('type', 'return_refund')->count());
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Returned, $order->fresh()->status);
    }
}
