<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Notifications\WholesaleDeskNotification;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TraderBotEngagementTest extends TestCase
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

    public function test_invoice_reply_links_the_portal_pdf(): void
    {
        $customer = $this->verifiedTrader();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'subtotal' => 750,
            'grand_total' => 750,
        ]);

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'فاتورة '.$order->order_number);

        $this->assertStringContainsString("/api/v1/wholesale/invoices/{$order->id}/pdf", $reply);
    }

    public function test_statement_reply_links_the_portal_pdf(): void
    {
        $customer = $this->verifiedTrader();

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'كشف حساب الشهر ده');

        $this->assertStringContainsString('/api/v1/wholesale/statement', $reply);
    }

    public function test_bargaining_hands_off_to_sales_with_staff_notification(): void
    {
        Notification::fake();
        $customer = $this->verifiedTrader();
        $staff = \App\Models\User::factory()->create();
        $staff->givePermissionTo('quotations.price');

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'السعر غالي، عايز أفاصل فيه');

        $this->assertStringContainsString('مبيعات', $reply);
        Notification::assertSentTo([$staff], WholesaleDeskNotification::class);
    }

    public function test_customer_webhook_is_rate_limited(): void
    {
        config(['services.whatsapp_business.webhook_secret' => 'cloud-secret']);
        RateLimiter::for('webhooks', fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute(5));

        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $payload = json_encode([
                'provider_message_id' => "wamid-rate-{$i}",
                'from' => '201012345678',
                'text' => 'رصيدي',
            ], JSON_UNESCAPED_UNICODE);
            $last = $this->postSigned($payload);
        }

        $this->assertSame(429, $last->getStatusCode());
    }

    private function postSigned(string $body): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'cloud-secret');

        return $this->call('POST', '/api/v1/webhooks/whatsapp/customers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WA_SIGNATURE' => $signature,
            'HTTP_X_WA_TIMESTAMP' => $timestamp,
        ], $body);
    }
}
