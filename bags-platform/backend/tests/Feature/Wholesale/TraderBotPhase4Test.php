<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TraderBotPhase4Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function verifiedTrader(): Customer
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 50000,
        ]);
        CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => $customer->phone,
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        return $customer;
    }

    public function test_order_status_comes_from_status_history(): void
    {
        $customer = $this->verifiedTrader();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Processing,
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::Processing,
            'to_status' => OrderStatus::Shipped,
        ]);

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'طلبي فين');

        $this->assertStringContainsString($order->order_number, $reply);
        $this->assertStringContainsString('shipped', $reply);
    }

    public function test_reorder_executes_into_cart_with_added_and_skipped_summary(): void
    {
        $customer = $this->verifiedTrader();
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['retail_price' => 100, 'is_active' => true]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 100,
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'subtotal' => 200,
            'grand_total' => 200,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'حقيبة',
            'sku_snapshot' => $variant->sku,
            'qty' => 2,
            'unit_price' => 100,
            'discount_amount' => 0,
            'line_total' => 200,
        ]);

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'عيد آخر طلب');

        $cart = Cart::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($cart);
        $this->assertSame(2.0, (float) $cart->items()->where('product_variant_id', $variant->id)->value('qty'));
        $this->assertStringContainsString('اتضافت', $reply);
    }

    public function test_receipt_text_returns_confirmation_prompt_without_creating_notice(): void
    {
        $customer = $this->verifiedTrader();

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'سجلت تحويل 15000 من البنك الأهلي مرجع 88231');

        $this->assertStringContainsString('15000', $reply);
        $this->assertStringContainsString('88231', $reply);
        $this->assertStringContainsString('تأكيد', $reply);
        $this->assertSame(0, WholesalePaymentNotice::query()->count());
    }
}
