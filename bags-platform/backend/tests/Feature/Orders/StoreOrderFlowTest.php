<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\CartService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * المرحلة 7 (7.3 + 7.4 — جانب المتجر): سلة الزائر/العميل، الدمج عند
 * الدخول، الكوبون، إتمام الشراء بالضرائب والشحن، التتبع، وطلبات الحساب.
 */
class StoreOrderFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->staff = User::factory()->create();
        $this->branch = Branch::factory()->create();
    }

    public function test_guest_cart_crud_with_live_prices_and_availability(): void
    {
        $variant = $this->purchasableVariant(200);
        $this->giveStock($variant, 5);

        // القراءة لا تنشئ سلة (M3): التوكن يصدر من أول إضافة فعلية
        $added = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 2,
        ])->assertOk()->assertJsonPath('data.totals.items_total', 400);
        $token = $added->json('data.cart_token');
        $this->assertNotEmpty($token);
        $headers = ['X-Cart-Session' => $token];
        $itemId = $added->json('data.items.0.id');
        $this->assertNotEmpty($itemId);

        // إضافة نفس الصنف تتراكم على نفس البند
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk()->assertJsonPath('data.items.0.qty', 3);

        // كمية تتجاوز المتاح تُرفض
        $this->withHeaders($headers)->putJson("/api/v1/store/cart/items/{$itemId}", [
            'qty' => 6,
        ])->assertUnprocessable();

        $this->withHeaders($headers)->putJson("/api/v1/store/cart/items/{$itemId}", [
            'qty' => 4,
        ])->assertOk()->assertJsonPath('data.items.0.qty', 4);

        $this->withHeaders($headers)->deleteJson("/api/v1/store/cart/items/{$itemId}")
            ->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_cart_rejects_unpublished_product(): void
    {
        $draft = ProductVariant::factory()->create(['retail_price' => 100]);

        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $draft->id, 'qty' => 1,
        ])->assertUnprocessable();
    }

    public function test_guest_checkout_with_coupon_computes_totals_and_empties_cart(): void
    {
        $variant = $this->purchasableVariant(200);
        $this->giveStock($variant, 10);
        $city = City::factory()->create(['shipping_cost' => 30]);
        Coupon::factory()->create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);

        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 2,
        ])->assertOk()->json('data.cart_token');
        $headers = ['X-Cart-Session' => $token];
        $this->withHeaders($headers)->postJson('/api/v1/store/cart/coupon', ['code' => 'save10'])
            ->assertOk()->assertJsonPath('data.coupon_discount', 40);

        $response = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'شارع التحرير',
            'payment_method' => 'cod',
        ])->assertCreated();

        $response->assertJsonPath('data.subtotal', '400.00')
            ->assertJsonPath('data.discount_amount', '40.00')
            ->assertJsonPath('data.shipping_cost', '30.00')
            ->assertJsonPath('data.tax_amount', '0.00')
            ->assertJsonPath('data.grand_total', '390.00')
            ->assertJsonPath('data.status', 'pending');
        $this->assertStringStartsWith('ORD-', (string) $response->json('data.order_number'));

        $order = Order::query()->sole();
        $this->assertNull($order->customer_id);
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame(1, $order->statusHistories()->count());
        $this->assertSame(1, Coupon::query()->sole()->used_count);

        // السلة فُرّغت بعد تحويلها لطلب
        $this->withHeaders($headers)->getJson('/api/v1/store/cart/items')
            ->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_checkout_applies_tax_when_enabled_in_settings(): void
    {
        // الدفع بالكارت يُفتح من الإعدادات فقط بعد ربط بوابته (H9)
        config(['store.payment_methods' => ['cod', 'card']]);
        Setting::query()->where('key', 'tax_enabled')->update(['value' => '1']);
        $variant = $this->purchasableVariant(200);
        $this->giveStock($variant, 10);
        $city = City::factory()->create(['shipping_cost' => 30]);
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 2,
        ])->assertOk()->json('data.cart_token');

        $this->withHeaders(['X-Cart-Session' => $token])->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان',
            'payment_method' => 'card',
        ])->assertCreated()
            ->assertJsonPath('data.tax_amount', '56.00')   // 400 × 14%
            ->assertJsonPath('data.grand_total', '486.00'); // 400 + 30 شحن + 56 ضريبة
    }

    public function test_guest_cart_merges_into_account_on_login(): void
    {
        $variant = $this->purchasableVariant(150);
        $this->giveStock($variant, 10);
        $customer = Customer::factory()->create([
            'phone' => '01099998888',
            'password' => Hash::make('secret123'),
        ]);

        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 2,
        ])->assertOk()->json('data.cart_token');

        $login = $this->withHeaders(['X-Cart-Session' => $token])->postJson('/api/v1/store/auth/login', [
            'login' => '01099998888', 'password' => 'secret123',
        ])->assertOk();
        $customerToken = $login->json('token');

        Auth::forgetGuards();
        $this->withToken($customerToken)->getJson('/api/v1/store/cart/items')
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.items.0.qty', 2)
            ->assertJsonPath('data.totals.items_total', 300);
    }

    public function test_customer_checkout_uses_personalized_pricing(): void
    {
        $variant = $this->purchasableVariant(200);
        $this->giveStock($variant, 10);
        $group = CustomerGroup::create([
            'name' => 'تجار', 'code' => 'traders',
            'discount_percentage' => 10, 'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $customer->createToken('store-token')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk()->assertJsonPath('data.totals.items_total', 180);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان العميل',
            'payment_method' => 'cod',
        ])->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '180.00')
            ->assertJsonPath('data.discount_amount', '20.00');

        $this->assertSame($customer->id, Order::query()->sole()->customer_id);
    }

    public function test_public_tracking_requires_matching_phone(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 1,
        ])->assertOk()->json('data.cart_token');
        $headers = ['X-Cart-Session' => $token];
        $number = $this->withHeaders($headers)->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف', 'recipient_phone' => '01012345678',
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        $this->getJson("/api/v1/store/orders/{$number}/track?phone=01012345678")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(1, 'data.status_history');

        $this->getJson("/api/v1/store/orders/{$number}/track?phone=01000000000")->assertNotFound();
    }

    public function test_account_orders_listing_and_customer_cancel_releases_reservation(): void
    {
        // منفّذ نظام لحركات فك الحجز عند إلغاء العميل (الدفتر يتطلب موظفًا)
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'staff'));

        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 5);
        $customer = Customer::factory()->create();
        $city = City::factory()->create(['shipping_cost' => 0]);
        $token = $customer->createToken('store-token')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id, 'qty' => 2,
        ])->assertOk();
        Auth::forgetGuards();
        $orderId = $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/store/account/orders')
            ->assertOk()->assertJsonCount(1, 'data');

        // تأكيد إداري (خدمة) يحجز الكمية، ثم إلغاء العميل يفك الحجز
        app(OrderService::class)->changeStatus(Order::findOrFail($orderId), 'confirmed', $this->staff);
        $this->assertSame('2.0000', (string) $this->level($variant->id)->reserved);

        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/store/account/orders/{$orderId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('0.0000', (string) $this->level($variant->id)->reserved);

        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/store/account/orders/{$orderId}/cancel")
            ->assertUnprocessable();
    }

    // ---------- helpers ----------

    public function test_failed_writes_without_a_session_do_not_persist_empty_carts(): void
    {
        $draft = ProductVariant::factory()->create(['retail_price' => 100]);
        $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $draft->id, 'qty' => 1])->assertUnprocessable();
        $this->putJson('/api/v1/store/cart/items/123', ['qty' => 1])->assertNotFound();
        $this->deleteJson('/api/v1/store/cart/items/123')->assertNotFound();
        $this->deleteJson('/api/v1/store/cart/coupon')->assertNotFound();
        $this->postJson('/api/v1/store/cart/coupon', ['code' => 'INVALID'])->assertNotFound();
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_clear_removes_all_lines_without_touching_another_guest_cart(): void
    {
        $variant = $this->purchasableVariant(100);
        $otherVariant = $this->purchasableVariant(200);
        $this->giveStock($variant, 10);
        $this->giveStock($otherVariant, 10);
        $first = $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 1])->assertOk()->json('data');
        $second = $this->postJson('/api/v1/store/cart/items', ['product_variant_id' => $otherVariant->id, 'qty' => 1])->assertOk()->json('data');
        $this->withHeaders(['X-Cart-Session' => $first['cart_token']])->postJson('/api/v1/store/cart/items', ['product_variant_id' => $otherVariant->id, 'qty' => 2])->assertOk();
        $this->deleteJson('/api/v1/store/cart/items')->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.totals.total', 0);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $first['id']]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $second['id'], 'qty' => 1]);
        $this->deleteJson('/api/v1/store/cart/items')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_soft_deleted_variant_does_not_break_the_remaining_cart(): void
    {
        $deleted = $this->purchasableVariant(100);
        $live = $this->purchasableVariant(200);
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $deleted->id]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $live->id]);
        // Bypass model events to exercise historical orphaned rows as well.
        DB::table('product_variants')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        $this->withHeaders(['X-Cart-Session' => $cart->cart_token])->getJson('/api/v1/store/cart/items')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $item->id);
        $live->delete();
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
        $this->deleteJson('/api/v1/store/cart/items')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_pricing_queries_stay_bounded_as_cart_lines_grow(): void
    {
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create(['customer_id' => $customer->id]);
        foreach (range(1, 20) as $index) {
            $variant = $this->purchasableVariant(12.35);
            CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 3]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(CartService::class)->present($cart, $customer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(20, $result['items']);
        $this->assertSame(741.0, $result['totals']['total']);
        $priceQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'from "price_list_items"'));
        $this->assertCount(1, $priceQueries);
        $this->assertLessThanOrEqual(8, count($queries));
    }

    public function test_updating_a_removed_item_does_not_reinsert_it(): void
    {
        $variant = $this->purchasableVariant(100);
        $this->giveStock($variant, 10);
        $cart = Cart::factory()->create();
        $item = CartItem::factory()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id]);
        $item->delete();
        try {
            app(CartService::class)->updateItemQty($cart, null, $variant->id, 2, $item->id);
            $this->fail('Updating a deleted line must return not found.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);
        }
    }

    private function purchasableVariant(float $price): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => $price,
        ]);
    }

    private function giveStock(ProductVariant $variant, float|int $qty): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $this->staff->id,
        ]);
    }

    private function level(int $variantId): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $this->branch->id)
            ->whereNull('location_id')
            ->firstOrFail();
    }
}
