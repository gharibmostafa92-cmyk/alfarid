<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Notifications\Channels\CustomerSmsChannel;
use App\Notifications\CustomerPasswordReset;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task6 Lane A4: SMS channel guards + reset-link fragment (F8 + F17).
 *
 * F17: blank recipients must be rejected before any network call (422, not a
 * misleading 503), notifications without toSms must not fatal, and 4xx
 * provider rejections must not masquerade as 503 outages.
 * F8: the SMS reset link must carry the raw token in the URL fragment (never
 * in the query string) and must not include the phone number.
 */
class Task6SmsChannelTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        config(['services.customer_sms.url' => 'https://sms.example.test/send']);
        config(['services.customer_sms.token' => 'test-token']);
    }

    public function test_blank_recipient_rejected_before_network_call(): void
    {
        Http::fake();
        $customer = Customer::factory()->make(['phone' => '01012345678']);
        // Simulate a recipient whose number was wiped: bypass the mutator so
        // the channel itself must reject the blank value.
        $customer->setRawAttributes([...$customer->getAttributes(), 'phone' => null], true);

        try {
            (new CustomerSmsChannel)->send($customer, new CustomerPasswordReset(str_repeat('a', 64)));
            $this->fail('Blank recipient must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }
        Http::assertNothingSent();
    }

    public function test_notification_without_to_sms_rejected_cleanly(): void
    {
        Http::fake();
        $customer = Customer::factory()->make(['phone' => '01012345678']);
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [CustomerSmsChannel::class];
            }
        };

        try {
            (new CustomerSmsChannel)->send($customer, $notification);
            $this->fail('Notifications without toSms must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(500, $e->status);
        }
        Http::assertNothingSent();
    }

    public function test_provider_client_error_maps_to_422_not_503(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'bad number'], 422)]);
        $customer = Customer::factory()->make(['phone' => '01012345678']);

        try {
            (new CustomerSmsChannel)->send($customer, new CustomerPasswordReset(str_repeat('a', 64)));
            $this->fail('Provider 4xx must surface as 4xx.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_connection_failure_stays_503_retryable(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('down'));
        $customer = Customer::factory()->make(['phone' => '01012345678']);

        try {
            (new CustomerSmsChannel)->send($customer, new CustomerPasswordReset(str_repeat('a', 64)));
            $this->fail('Connection failure must raise.');
        } catch (BusinessException $e) {
            $this->assertSame(503, $e->status);
        }
    }

    public function test_sms_reset_link_uses_fragment_and_omits_phone(): void
    {
        config(['app.frontend_url' => 'https://shop.example.test']);
        $token = str_repeat('b', 64);
        $customer = Customer::factory()->make(['phone' => '01012345678']);

        $text = (new CustomerPasswordReset($token))->toSms($customer);

        $this->assertStringContainsString('#token='.$token, $text);
        $this->assertStringNotContainsString('?token=', $text);
        $this->assertStringNotContainsString('01012345678', $text);
        $this->assertLessThanOrEqual(160, strlen($text), 'SMS must stay inside one GSM-7 segment.');
    }
}
