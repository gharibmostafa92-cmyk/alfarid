<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\OrderStatus;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\City;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderService;
use App\Services\Finance\WalletService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class StoreCriticalConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_parallel_wallet_checkout_replay_and_cancellation_apply_effects_once(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(5);
        $customer = Customer::factory()->create();
        $token = $customer->createToken('checkout-race')->plainTextToken;
        app(WalletService::class)->record($customer->id, 200, 'opening', 'customer', $customer->id);
        $coupon = Coupon::factory()->create(['type' => 'fixed', 'value' => 10, 'usage_limit' => 1]);
        $cart = Cart::query()->create(['customer_id' => $customer->id, 'coupon_id' => $coupon->id]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);

        $checkout = 'config(["store.payment_methods" => ["cod", "wallet"]]);'.$this->httpJob(
            $token, 'POST', '/api/v1/store/checkout', [
                'city_id' => $city->id,
                'shipping_address_line' => 'شارع 1',
                'payment_method' => 'wallet',
                'expected_total' => 90,
            ], ['HTTP_IDEMPOTENCY_KEY' => 'wallet-race'],
        );
        $results = $this->concurrentJobs([$checkout, $checkout]);

        $this->assertSame([201, 201], array_column($results, 'status'));
        $order = Order::query()->sole();
        foreach ($results as $result) {
            $this->assertSame($order->order_number, $result['body']['data']['order_number']);
        }
        $this->assertSame(1.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertDatabaseCount('stock_movements', 2); // Initial stock and one reservation.
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertDatabaseCount('coupon_usages', 1);
        $this->assertSame('110.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('customer_wallet_transactions', 2); // Opening balance and one debit.

        $cancel = $this->httpJob($token, 'POST', "/api/v1/store/account/orders/{$order->id}/cancel");
        $results = $this->concurrentJobs([$cancel, $cancel]);

        $this->assertSame([200, 200], array_column($results, 'status'));
        foreach ($results as $result) {
            $this->assertSame('refunded', $result['body']['data']['payment_status']);
        }
        $this->assertFalse($order->fresh()->requires_refund);
        $this->assertSame('200.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('customer_wallet_transactions', 3);
        $this->assertDatabaseHas('customer_wallet_transactions', [
            'reference_id' => $order->id, 'type' => 'order_reversal', 'amount' => 90,
        ]);
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertDatabaseCount('coupon_usages', 0);
    }

    public function test_parallel_cart_adds_accumulate_in_one_line(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant] = $this->stockedVariant(8);
        $cart = Cart::query()->create([]);
        $jobs = [];
        foreach ([2, 3] as $quantity) {
            $jobs[] = $this->httpJob('', 'POST', '/api/v1/store/cart/items', [
                'product_variant_id' => $variant->id,
                'qty' => $quantity,
            ], ['HTTP_X_CART_SESSION' => $cart->cart_token]);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(5.0, (float) $cart->items()->sole()->qty);
    }

    public function test_parallel_checkout_of_the_last_unit_creates_one_order(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(1);
        $jobs = [];
        foreach (['01010000001', '01010000002'] as $phone) {
            $cart = Cart::query()->create([]);
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            $jobs[] = $this->checkoutJob($cart, $city, $phone, 'last-unit-'.$phone, 100);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::Reserve)->count());
        $this->assertTrue(StockLevel::query()->whereColumn('reserved', '>', 'on_hand')->doesntExist());
        $this->assertTrue(StockLevel::query()->where('on_hand', '<', 0)->orWhere('reserved', '<', 0)->doesntExist());
        $this->assertSame(1, Cart::query()->has('items')->count());
        $failed = collect($results)->first(fn (array $result): bool => $result['status'] === 422);
        $message = (string) ($failed['body']['message'] ?? '');
        $this->assertTrue(
            str_contains($message, 'غير كافٍ') || str_contains($message, 'تغير المخزون'),
            $message,
        );
        $this->assertSame(0, CouponUsage::query()->count());
    }

    public function test_twenty_parallel_checkouts_with_reversed_line_order_get_unique_numbers(): void
    {
        $this->seed(SettingSeeder::class);
        [$first, $city] = $this->stockedVariant(20);
        [$second] = $this->stockedVariant(20);
        $jobs = [];
        for ($index = 0; $index < 20; $index++) {
            $cart = Cart::query()->create([]);
            $variants = $index % 2 === 0 ? [$first, $second] : [$second, $first];
            foreach ($variants as $variant) {
                $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            }
            $jobs[] = $this->checkoutJob($cart, $city, sprintf('01040000%03d', $index), "load-{$index}", 200);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertSame(array_fill(0, 20, 201), array_column($results, 'status'), json_encode($results));
        $numbers = array_map(fn (array $result) => $result['body']['data']['order_number'], $results);
        $this->assertCount(20, array_unique($numbers));
        $this->assertDatabaseCount('orders', 20);
        $this->assertDatabaseCount('order_items', 40);
        foreach ([$first, $second] as $variant) {
            $this->assertSame(20.0, (float) StockLevel::query()->where('product_variant_id', $variant->id)->sum('reserved'));
        }
    }

    public function test_parallel_coupon_redemptions_keep_a_single_usage(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(8);
        $coupon = Coupon::factory()->create([
            'code' => 'RACE',
            'type' => 'fixed',
            'value' => 10,
            'usage_limit' => 1,
            'usage_limit_per_customer' => 1,
        ]);
        $jobs = [];
        for ($index = 0; $index < 10; $index++) {
            $phone = sprintf('01020000%03d', $index);
            $cart = Cart::query()->create(['coupon_id' => $coupon->id]);
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            $jobs[] = $this->checkoutJob($cart, $city, $phone, 'coupon-'.$phone, 90);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['status'] === 201)));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Coupon::query()->sole()->used_count);
        $this->assertSame(1, CouponUsage::query()->count());
        foreach ($results as $result) {
            if ($result['status'] !== 201) {
                $this->assertSame(422, $result['status']);
                $this->assertSame('coupon_invalid', $result['body']['code'] ?? null);
            }
        }
    }

    public function test_parallel_checkouts_cannot_redeem_a_coupon_twice_for_the_same_phone(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(5);
        $coupon = Coupon::factory()->create([
            'type' => 'fixed', 'value' => 10, 'usage_limit' => null, 'usage_limit_per_customer' => 1,
        ]);
        $jobs = [];
        foreach (['01020000001', '+20 10-2000-0001'] as $index => $phone) {
            $cart = Cart::query()->create(['coupon_id' => $coupon->id]);
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            $jobs[] = $this->checkoutJob($cart, $city, $phone, "identity-{$index}", 90);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'), json_encode($results));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('coupon_usages', 1);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_customer_cancellation_and_staff_processing_cannot_both_succeed(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(5);
        $customer = Customer::factory()->create();
        $customerToken = $customer->createToken('cancel-race')->plainTextToken;
        $staff = User::factory()->create();
        foreach (['orders.view', 'orders.update', 'inventory.all_branches'] as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        $staffToken = $staff->createToken('processing-race')->plainTextToken;
        $cart = Cart::query()->create(['customer_id' => $customer->id]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $id = $this->withToken($customerToken)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id, 'shipping_address_line' => 'شارع 1', 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff);

        [$cancel, $process] = $this->concurrentJobs([
            $this->httpJob($customerToken, 'POST', "/api/v1/store/account/orders/{$id}/cancel"),
            $this->httpJob($staffToken, 'PATCH', "/api/v1/admin/orders/{$id}/status", ['status' => 'processing']),
        ]);

        $this->assertEqualsCanonicalizing([200, 422], [$cancel['status'], $process['status']], json_encode([$cancel, $process]));
        $cancelled = $cancel['status'] === 200;
        $this->assertSame($cancelled ? OrderStatus::Cancelled : OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame($cancelled ? 0.0 : 1.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_parallel_guest_checkouts_cannot_pass_the_pending_phone_cap(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(8);
        $jobs = [];
        foreach (['01030000001', '01030000002', '01030000003', '01030000004'] as $index => $ignored) {
            $cart = Cart::query()->create([]);
            $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
            $jobs[] = $this->checkoutJob($cart, $city, '01030000099', 'pending-'.$index, 100);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertSame(3, count(array_filter($results, fn (array $result): bool => $result['status'] === 201)));
        $this->assertSame(3, Order::query()->where('guest_phone_normalized', '01030000099')->count());
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['status'] === 422)));
    }

    public function test_parallel_payment_webhooks_only_confirm_once(): void
    {
        [$order] = $this->checkoutForDispatch();
        $order->update(['payment_method' => 'card']);
        $webhook = $this->paymentJob($order);

        $results = $this->concurrentJobs([$webhook, $webhook]);

        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertCount(1, array_filter($results, fn (array $result) => $result['body']['duplicate'] ?? false));
        $this->assertEqualsCanonicalizing(['processed', 'duplicate'], WebhookLog::query()->pluck('status')->all());
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1, $order->statusHistories()->where('to_status', 'confirmed')->count());
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::Reserve)->count());
        $this->assertSame(1.0, (float) StockLevel::query()->sum('reserved'));
    }

    public function test_parallel_manual_payment_updates_create_one_audit_entry(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'payment_status' => 'unpaid']);
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('orders.payment', 'staff'));
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $token = $staff->createToken('payment-race')->plainTextToken;
        $job = $this->httpJob($token, 'POST', "/api/v1/admin/orders/{$order->id}/payment", [
            'payment_status' => 'paid', 'notes' => 'Received late',
        ]);

        $results = $this->concurrentJobs([$job, $job]);

        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertTrue($order->requires_refund);
        $history = $order->statusHistories()->sole();
        $this->assertSame($staff->id, $history->changed_by_id);
        $this->assertStringContainsString('unpaid ← paid', $history->notes);
    }

    public function test_expiration_racing_with_payment_leaves_a_consistent_order_and_reservation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 10:00:00 UTC'));
        [$order] = $this->checkoutForDispatch();
        $order->forceFill(['payment_method' => 'card', 'created_at' => now()->subMinutes(91)])->save();
        $expire = '$ready(); config(["orders.pending_online_expire_minutes" => 90]);'
            .'Illuminate\\Support\\Facades\\Date::setTestNow("2026-11-07 10:00:00 UTC");'
            .'return Illuminate\\Support\\Facades\\Artisan::call("orders:expire-pending");';

        [$payment, $exit] = $this->concurrentJobs([$this->paymentJob($order), $expire]);

        $this->assertSame(0, $exit);
        $this->assertSame(200, $payment['status'], json_encode($payment));
        $this->assertSame('paid', $order->refresh()->payment_status);
        $this->assertContains($order->status, [OrderStatus::Confirmed, OrderStatus::Cancelled]);
        $cancelled = $order->status === OrderStatus::Cancelled;
        $this->assertSame($cancelled, $order->requires_refund);
        $this->assertSame($cancelled ? 0.0 : 1.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame($cancelled ? 1 : 0, StockMovement::query()->where('type', StockMovementType::ReleaseReserve)->count());
    }

    public function test_parallel_shipment_requests_sell_the_reserved_unit_once(): void
    {
        [$order, $staff] = $this->checkoutForDispatch();
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff);
        $company = ShippingCompany::factory()->create(['is_active' => true]);
        $token = $staff->createToken('shipment-race')->plainTextToken;
        $job = $this->httpJob($token, 'POST', "/api/v1/admin/orders/{$order->id}/shipment", [
            'shipping_company_id' => $company->id,
        ]);

        $results = $this->concurrentJobs([$job, $job]);

        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'), json_encode($results));
        $rejection = collect($results)->firstWhere('status', 422);
        $this->assertStringContainsString('توجد بوليصة نشطة', $rejection['body']['message']);
        $this->assertSame($order->id, Shipment::query()->sole()->order_id);
        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame(4.0, (float) StockLevel::query()->sum('on_hand'));
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::Sale)->count());
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::ReleaseReserve)->count());
    }

    public function test_parallel_shipments_cannot_use_the_same_awb_on_different_orders(): void
    {
        [$first, $staff] = $this->checkoutForDispatch();
        [$second] = $this->checkoutForDispatch('01040000002');
        $company = ShippingCompany::factory()->create(['is_active' => true]);
        $token = $staff->createToken('awb-race')->plainTextToken;
        $jobs = [];
        foreach ([$first, $second] as $order) {
            app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff);
            // Both requests have passed the form's uniqueness check before either inserts.
            $jobs[] = 'App\\Models\\Shipment::creating(function () use ($ready) { $ready(); });'
                .$this->httpJob($token, 'POST', "/api/v1/admin/orders/{$order->id}/shipment", [
                    'shipping_company_id' => $company->id,
                    'awb_number' => 'SHARED-AWB',
                ], waitAtRoute: false);
        }

        $results = $this->concurrentJobs($jobs);

        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'), json_encode($results));
        $this->assertArrayHasKey('awb_number', collect($results)->firstWhere('status', 422)['body']['errors']);
        $shipment = Shipment::query()->sole();
        $this->assertSame('SHARED-AWB', $shipment->awb_number);
        foreach ([$first, $second] as $order) {
            $expectedStatus = $shipment->order_id === $order->id ? OrderStatus::Shipped : OrderStatus::Confirmed;
            $this->assertSame($expectedStatus, $order->refresh()->status);
        }
        $this->assertSame(1.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::Sale)->count());
    }

    public function test_customer_cancellation_and_shipment_cannot_both_apply(): void
    {
        [$order, $staff] = $this->checkoutForDispatch();
        $customer = Customer::factory()->create();
        $order->update(['customer_id' => $customer->id]);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff);
        $company = ShippingCompany::factory()->create(['is_active' => true]);
        $staffToken = $staff->createToken('ship-race')->plainTextToken;
        $customerToken = $customer->createToken('cancel-race')->plainTextToken;

        [$cancel, $ship] = $this->concurrentJobs([
            $this->httpJob($customerToken, 'POST', "/api/v1/store/account/orders/{$order->id}/cancel"),
            $this->httpJob($staffToken, 'POST', "/api/v1/admin/orders/{$order->id}/shipment", [
                'shipping_company_id' => $company->id,
            ]),
        ]);

        $this->assertEqualsCanonicalizing([200, 422], [$cancel['status'], $ship['status']], json_encode([$cancel, $ship]));
        $shipped = $ship['status'] === 200;
        $this->assertSame($shipped ? OrderStatus::Shipped : OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertDatabaseCount('shipments', $shipped ? 1 : 0);
        $this->assertSame($shipped ? 4.0 : 5.0, (float) StockLevel::query()->sum('on_hand'));
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::ReleaseReserve)->count());
    }

    public function test_checkout_stock_lock_timeout_returns_conflict_and_restores_the_connection_setting(): void
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(5);
        $cart = Cart::query()->create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $job = $this->httpJob('', 'POST', '/api/v1/store/checkout', [
            'recipient_name' => 'Customer',
            'recipient_phone' => '01040000001',
            'city_id' => $city->id,
            'shipping_address_line' => 'Cairo',
            'payment_method' => 'cod',
        ], ['HTTP_X_CART_SESSION' => $cart->cart_token]);
        $job = 'return (function () use ($ready) {'
            .'$response = (function () {'.$job.'})();'
            .'$response["lock_timeout"] = Illuminate\\Support\\Facades\\DB::selectOne("SHOW lock_timeout")->lock_timeout;'
            .'return $response; })();';

        [$result] = DB::transaction(function () use ($variant, $job): array {
            StockLevel::query()->where('product_variant_id', $variant->id)->lockForUpdate()->sole();

            return $this->concurrentJobs([$job]);
        });

        $this->assertSame(409, $result['status'], json_encode($result));
        $this->assertSame('1min', $result['lock_timeout']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(0.0, (float) StockLevel::query()->sum('reserved'));
        $this->assertSame(1, $cart->items()->count());
    }

    /** @return array{Order, User} */
    private function checkoutForDispatch(string $phone = '01040000001'): array
    {
        $this->seed(SettingSeeder::class);
        [$variant, $city] = $this->stockedVariant(5);
        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $cart = Cart::query()->create([]);
        $cart->items()->create(['product_variant_id' => $variant->id, 'qty' => 1]);
        $id = $this->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id,
            'recipient_name' => 'Customer',
            'recipient_phone' => $phone,
            'shipping_address_line' => 'Cairo',
            'payment_method' => 'cod',
        ], ['X-Cart-Session' => $cart->cart_token])->assertCreated()->json('data.id');

        return [Order::query()->findOrFail($id), $staff];
    }

    private function paymentJob(Order $order): string
    {
        $payload = ['event_id' => 'evt_concurrent', 'order_number' => $order->order_number, 'status' => 'paid'];
        $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'race-secret');

        return 'config(["webhooks.payment.testpay" => ["secret" => "race-secret", "signature_header" => "X-Webhook-Signature"]]);'
            .$this->httpJob('', 'POST', '/api/v1/webhooks/payment/testpay', $payload, [
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ]);
    }

    /** @return array{0: ProductVariant, 1: City} */
    private function stockedVariant(int $qty): array
    {
        $branch = Branch::factory()->create();
        $staff = User::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['status' => 'published'])->id,
            'retail_price' => 100,
        ]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $staff->id,
        ]);

        return [$variant, City::factory()->create(['shipping_cost' => 0])];
    }

    private function checkoutJob(Cart $cart, City $city, string $phone, string $key, int $expectedTotal): string
    {
        $body = json_encode([
            'recipient_name' => 'عميل',
            'recipient_phone' => $phone,
            'city_id' => $city->id,
            'shipping_address_line' => 'شارع 1',
            'payment_method' => 'cod',
            'expected_total' => $expectedTotal,
        ], JSON_THROW_ON_ERROR);

        return '$ready(); $kernel = app(Illuminate\\Contracts\\Http\\Kernel::class);'
            .'$request = Illuminate\\Http\\Request::create("/api/v1/store/checkout", "POST", [], [], [], '
            .var_export([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CART_SESSION' => $cart->cart_token,
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ], true).', '.var_export($body, true).');'
            .'$response = $kernel->handle($request); $decoded = json_decode($response->getContent(), true);'
            .'$kernel->terminate($request, $response);'
            .'return ["status" => $response->getStatusCode(), "body" => $decoded];';
    }
}
