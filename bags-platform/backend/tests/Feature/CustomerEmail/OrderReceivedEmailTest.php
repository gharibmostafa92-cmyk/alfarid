<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEmail;

use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Models\Branch;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderReceivedEmailTest extends TestCase
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

        config([
            'services.resend.capture_enabled' => true,
            'services.resend.from' => 'Alfarid <orders@alfarid.example>',
            'services.resend.environment' => 'testing',
        ]);
        Http::preventStrayRequests();
    }

    public function test_guest_checkout_sends_to_the_saved_recipient_email(): void
    {
        $variant = $this->purchasableVariant();
        $this->giveStock($variant);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $cartToken = $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ])->assertOk()->json('data.cart_token');

        $response = $this->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف',
            'recipient_phone' => '01012345801',
            'recipient_email' => 'guest@example.test',
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان التوصيل',
            'payment_method' => 'cod',
        ], ['X-Cart-Session' => $cartToken])->assertCreated();

        $order = Order::query()->findOrFail($response->json('data.id'));
        $row = DB::table('customer_email_outbox')->first();
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('guest@example.test', $order->guest_email);
        $this->assertSame('guest@example.test', $payload['to'][0]);
        $this->assertStringContainsString($order->order_number, $payload['text']);
        $this->assertStringContainsString($order->grand_total.' جنيه مصري', $payload['text']);
        $this->assertDatabaseCount('customer_email_outbox', 1);
        Http::assertNothingSent();
    }

    public function test_guest_checkout_without_email_still_creates_the_order_without_an_intent(): void
    {
        $variant = $this->purchasableVariant();
        $this->giveStock($variant);
        $city = City::factory()->create(['shipping_cost' => 0]);
        $cartToken = (string) Str::uuid();

        $this->postJson('/api/v1/store/cart/items', [
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ], ['X-Cart-Session' => $cartToken])->assertOk();

        $this->postJson('/api/v1/store/checkout', [
            'recipient_name' => 'ضيف بلا بريد',
            'recipient_phone' => '01012345802',
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان التوصيل',
            'payment_method' => 'cod',
        ], ['X-Cart-Session' => $cartToken])->assertCreated();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('customer_email_outbox', 0);
        Http::assertNothingSent();
    }

    private function purchasableVariant(): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published']);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => 100,
        ]);
    }

    private function giveStock(ProductVariant $variant): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 5,
            'created_by' => $this->staff->id,
        ]);
    }
}
