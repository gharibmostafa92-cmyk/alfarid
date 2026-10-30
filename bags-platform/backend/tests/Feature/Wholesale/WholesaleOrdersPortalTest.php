<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WholesaleOrdersPortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $token;

    private Customer $otherTrader;

    private string $otherToken;

    private Customer $pendingTrader;

    private string $pendingToken;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 250000,
            'company_name' => 'شركة الاختبار',
            'tax_number' => '123456789',
            'commercial_register' => '654321',
        ]);
        $this->token = $this->trader->createToken('portal')->plainTextToken;

        $this->otherTrader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
        ]);
        $this->otherToken = $this->otherTrader->createToken('portal')->plainTextToken;

        $this->pendingTrader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'pending',
        ]);
        $this->pendingToken = $this->pendingTrader->createToken('portal')->plainTextToken;

        $product = Product::factory()->published()->create(['sales_channel' => 'both']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => 90, 'is_active' => true, 'color_name_ar' => 'أسود', 'size' => 'L']);

        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 1000,
            'reserved' => 0,
        ]);

        $list = PriceList::create([
            'name' => 'قائمة اختبار', 'type' => 'wholesale', 'currency' => 'EGP',
            'priority' => 1, 'is_active' => true,
        ]);
        foreach ([[1, 90], [24, 85]] as [$minQty, $price]) {
            $list->items()->create([
                'product_variant_id' => $this->variant->id,
                'min_qty' => $minQty,
                'price' => $price,
            ]);
        }
    }

    private function asTrader(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_demo_seeder_produces_portal_fixture_set(): void
    {
        $this->seed(\Database\Seeders\WholesaleDemoSeeder::class);

        $this->assertSame(1, Customer::query()->where('email', 'wholesale.demo@alfarid.test')->where('status', 'approved')->count());
        $this->assertSame(1, Customer::query()->where('email', 'wholesale.pending@alfarid.test')->where('status', 'pending')->count());
        $this->assertSame(1, Customer::query()->where('email', 'wholesale.rejected@alfarid.test')->where('status', 'rejected')->count());

        $approved = Customer::query()->where('email', 'wholesale.demo@alfarid.test')->firstOrFail();
        $this->assertSame('250000.00', (string) $approved->credit_limit);
        $this->assertSame(3, $approved->documents()->count());

        $response = $this->asTrader($approved->createToken('demo')->plainTextToken)
            ->getJson('/api/v1/wholesale/products?per_page=10')->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertNotEmpty($response->json('data.0.variants.0.tier_prices'));

        $statuses = Order::query()->where('customer_id', $approved->id)->pluck('status')->map(fn ($s) => $s->value)->all();
        foreach (['pending', 'processing', 'delivered', 'cancelled'] as $expected) {
            $this->assertContains($expected, $statuses);
        }
    }

    private function makeOrder(Customer $customer, OrderStatus $status, float $total = 1000): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => 'wholesale',
            'status' => $status,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'subtotal' => $total,
            'grand_total' => $total,
        ]);
        $order->items()->create([
            'product_variant_id' => $this->variant->id,
            'product_name_snapshot' => 'منتج',
            'sku_snapshot' => $this->variant->sku,
            'qty' => 10,
            'unit_price' => 100,
            'discount_amount' => 0,
            'line_total' => $total,
        ]);
        $order->statusHistories()->create([
            'from_status' => null,
            'to_status' => $status,
            'changed_by_type' => Customer::class,
            'changed_by_id' => $customer->id,
        ]);

        return $order;
    }

    public function test_orders_index_returns_own_orders_with_group_filter(): void
    {
        $this->makeOrder($this->trader, OrderStatus::Pending);
        $this->makeOrder($this->trader, OrderStatus::Delivered);
        $this->makeOrder($this->otherTrader, OrderStatus::Pending);

        $this->asTrader($this->token)->getJson('/api/v1/wholesale/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'meta']);

        $this->asTrader($this->token)->getJson('/api/v1/wholesale/orders?group=active')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asTrader($this->token)->getJson('/api/v1/wholesale/orders?group=delivered')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_orders_index_pending_trader_forbidden(): void
    {
        $this->asTrader($this->pendingToken)->getJson('/api/v1/wholesale/orders')->assertForbidden();
    }

    public function test_orders_show_returns_details_for_owner(): void
    {
        $order = $this->makeOrder($this->trader, OrderStatus::Pending);

        $this->asTrader($this->token)->getJson("/api/v1/wholesale/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonStructure([
                'data' => [
                    'id', 'city_name', 'status_history', 'cancellable', 'returnable',
                    'items' => [['id']],
                ],
            ])
            ->assertJsonPath('data.cancellable', true)
            ->assertJsonPath('data.returnable', false);
    }

    public function test_orders_show_other_trader_returns_404(): void
    {
        $order = $this->makeOrder($this->trader, OrderStatus::Pending);

        $this->asTrader($this->otherToken)->getJson("/api/v1/wholesale/orders/{$order->id}")->assertNotFound();
    }

    public function test_orders_show_pending_trader_forbidden(): void
    {
        $order = $this->makeOrder($this->trader, OrderStatus::Pending);

        $this->asTrader($this->pendingToken)->getJson("/api/v1/wholesale/orders/{$order->id}")->assertForbidden();
    }

    public function test_orders_show_delivered_is_returnable_not_cancellable(): void
    {
        $order = $this->makeOrder($this->trader, OrderStatus::Delivered);

        $this->asTrader($this->token)->getJson("/api/v1/wholesale/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.cancellable', false)
            ->assertJsonPath('data.returnable', true);
    }

    public function test_quick_add_to_cart_imports_valid_lines(): void
    {
        $response = $this->asTrader($this->token)->postJson('/api/v1/wholesale/quick-order/add-to-cart', [
            'items' => [
                ['sku' => $this->variant->sku, 'qty' => 5],
                ['sku' => 'NOPE-404', 'qty' => 2],
            ],
        ])->assertOk();

        $response->assertJsonPath('data.added.0.sku', $this->variant->sku)
            ->assertJsonPath('data.skipped.0.reason', 'not_found_or_inactive')
            ->assertJsonStructure(['data' => ['added', 'skipped', 'cart']]);
    }

    public function test_quick_add_to_cart_rejects_too_many_lines(): void
    {
        $items = array_fill(0, 501, ['sku' => 'X', 'qty' => 1]);

        $this->asTrader($this->token)->postJson('/api/v1/wholesale/quick-order/add-to-cart', ['items' => $items])
            ->assertUnprocessable();
    }

    public function test_quick_add_to_cart_pending_trader_forbidden(): void
    {
        $this->asTrader($this->pendingToken)->postJson('/api/v1/wholesale/quick-order/add-to-cart', [
            'items' => [['sku' => $this->variant->sku, 'qty' => 1]],
        ])->assertForbidden();
    }

    public function test_products_index_includes_label_and_image(): void
    {
        $this->asTrader($this->token)->getJson('/api/v1/wholesale/products?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'variants' => [['id', 'label', 'image', 'tier_prices']]]]])
            ->assertJsonPath('data.0.variants.0.label', 'أسود / L');
    }

    public function test_customer_resource_includes_wholesale_fields(): void
    {
        $this->asTrader($this->token)->getJson('/api/v1/wholesale/auth/application-status')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'company_name', 'tax_number', 'commercial_register',
                'credit_limit', 'wallet_balance', 'approved_at',
            ]]);
    }

    public function test_statement_entries_use_unified_shape(): void
    {
        $this->makeOrder($this->trader, OrderStatus::Delivered, 2000);

        $this->asTrader($this->token)->getJson('/api/v1/wholesale/statement')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['opening_balance', 'closing_balance', 'entries' => [['date', 'reference', 'description', 'debit', 'credit', 'balance']]],
            ])
            ->assertJsonPath('data.entries.0.debit', '2000.00')
            ->assertJsonPath('data.entries.0.credit', '0.00');
    }

    public function test_notifications_read_all_clears_unread(): void
    {
        $this->trader->notify(new \App\Notifications\OrderConfirmationNotification(
            $this->makeOrder($this->trader, OrderStatus::Pending)
        ));

        $this->assertGreaterThan(0, $this->trader->unreadNotifications()->count());

        $this->asTrader($this->token)->postJson('/api/v1/wholesale/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->assertSame(0, $this->trader->fresh()->unreadNotifications()->count());
    }

    public function test_notifications_read_all_pending_trader_forbidden(): void
    {
        $this->asTrader($this->pendingToken)->postJson('/api/v1/wholesale/notifications/read-all')->assertForbidden();
    }

    public function test_notification_data_uses_unified_shape(): void
    {
        $order = $this->makeOrder($this->trader, OrderStatus::Pending);
        $this->trader->notify(new \App\Notifications\OrderConfirmationNotification($order));

        $this->asTrader($this->token)->getJson('/api/v1/wholesale/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.title', "تم استلام طلبك رقم {$order->order_number}")
            ->assertJsonPath('data.0.data.link.screen', 'order')
            ->assertJsonPath('data.0.data.link.id', $order->id);
    }

    public function test_credit_checkout_over_limit_is_rejected(): void
    {
        $this->trader->update(['credit_limit' => 50]);
        $city = City::factory()->create(['is_active' => true, 'shipping_cost' => 0]);

        $this->asTrader($this->token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $this->variant->id, 'qty' => 5,
        ])->assertOk();

        $this->asTrader($this->token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الاختبار',
            'payment_method' => 'credit',
        ], ['Idempotency-Key' => 'test-over-limit-1'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'credit_limit_exceeded');
    }

    public function test_double_checkout_with_same_idempotency_key_creates_one_order(): void
    {
        $city = City::factory()->create(['is_active' => true, 'shipping_cost' => 0]);

        $this->asTrader($this->token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $this->variant->id, 'qty' => 2,
        ])->assertOk();

        $payload = [
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الاختبار',
            'payment_method' => 'credit',
        ];

        $first = $this->asTrader($this->token)->postJson('/api/v1/store/checkout', $payload, ['Idempotency-Key' => 'test-dup-1'])->assertCreated();
        $second = $this->asTrader($this->token)->postJson('/api/v1/store/checkout', $payload, ['Idempotency-Key' => 'test-dup-1'])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::query()->where('idempotency_key', 'test-dup-1')->count());
    }

    public function test_stale_expected_total_is_rejected_as_price_changed(): void
    {
        $city = City::factory()->create(['is_active' => true, 'shipping_cost' => 0]);

        $this->asTrader($this->token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $this->variant->id, 'qty' => 2,
        ])->assertOk();

        $this->asTrader($this->token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الاختبار',
            'payment_method' => 'credit',
            'expected_total' => 1.00,
        ], ['Idempotency-Key' => 'test-stale-1'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'price_changed');
    }

    public function test_credit_checkout_within_limit_succeeds(): void
    {
        $city = City::factory()->create(['is_active' => true, 'shipping_cost' => 0]);

        $this->asTrader($this->token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $this->variant->id, 'qty' => 2,
        ])->assertOk();

        $this->asTrader($this->token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الاختبار',
            'payment_method' => 'credit',
        ], ['Idempotency-Key' => 'test-within-limit-1'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id']]);
    }
}
