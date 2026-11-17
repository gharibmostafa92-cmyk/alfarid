<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Jobs\SendOutboundMessage;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\OutboundMessage;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Task6 Lane A2: SendOutboundMessage OTP routing + concurrency (F3 + F4).
 *
 * F3: an OTP for a NEW channel number (customer->whatsapp_phone unset or
 * stale) must be delivered to the channel under verification, not skipped.
 * F4: double handling must not double-send (atomic pending->sending claim);
 * scheduled deferrals must not consume $tries; failed() must close every
 * non-terminal row instead of leaving non-OTP rows pending forever.
 */
class Task6OutboundJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        // Take the job out of shadow mode so it reaches the network layer.
        \DB::table('settings')->where('key', 'ai_wholesale_shadow_mode')->update(['value' => '0']);
        config(['services.whatsapp_business.token' => 'test-token']);
        config(['services.whatsapp_business.phone_number_id' => 'test-phone-id']);
    }

    private function customer(string $phone = '01012345678'): Customer
    {
        return Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'phone' => $phone,
            'whatsapp_phone' => null,
        ]);
    }

    private function channel(Customer $customer, string $phone = '01512345678'): CustomerChannel
    {
        $expires = now()->addMinutes(10);
        $channel = CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => $phone,
            'provider' => 'whatsapp_cloud',
            'verification_expires_at' => $expires,
        ]);
        $channel->forceFill(['verification_code_hash' => password_hash('123456', PASSWORD_BCRYPT)])->save();

        return $channel->fresh();
    }

    private function otpMessage(Customer $customer, CustomerChannel $channel): OutboundMessage
    {
        return OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'customer_whatsapp',
            'idempotency_key' => 'otp-'.uniqid(),
            'template' => 'customer_otp',
            'payload' => [
                'channel_id' => $channel->id,
                'code' => '123456',
                'expires_at' => $channel->verification_expires_at->timestamp,
            ],
        ]);
    }

    public function test_otp_to_new_channel_number_is_sent_not_skipped(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $customer = $this->customer();
        $channel = $this->channel($customer);
        $message = $this->otpMessage($customer, $channel);

        (new SendOutboundMessage($message->id))->handle(app(\App\Services\Shared\SettingService::class));

        $this->assertSame('sent', $message->fresh()->status);
        Http::assertSent(fn ($request) => $request['to'] === ltrim((string) CustomerChannel::canonicalPhone('01512345678'), '+'));
    }

    public function test_scheduled_message_is_redispatched_without_consuming_attempts(): void
    {
        Queue::fake();
        $customer = $this->customer();
        $message = OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'customer_whatsapp',
            'idempotency_key' => 'sched-'.uniqid(),
            'template' => 'payment_reminder',
            'payload' => ['a' => 1],
            'scheduled_for' => now()->addHour(),
        ]);

        (new SendOutboundMessage($message->id))->handle(app(\App\Services\Shared\SettingService::class));

        $this->assertSame('pending', $message->fresh()->status);
        Queue::assertPushed(SendOutboundMessage::class, 1);
    }

    public function test_failed_closes_non_otp_rows_instead_of_leaving_them_pending(): void
    {
        $customer = $this->customer();
        $message = OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'customer_whatsapp',
            'idempotency_key' => 'fail-'.uniqid(),
            'template' => 'payment_reminder',
            'payload' => ['a' => 1],
        ]);

        (new SendOutboundMessage($message->id))->failed(new RuntimeException('boom'));

        $this->assertSame('error', $message->fresh()->status);
    }

    public function test_failed_clears_otp_payload(): void
    {
        $customer = $this->customer();
        $channel = $this->channel($customer);
        $message = $this->otpMessage($customer, $channel);

        (new SendOutboundMessage($message->id))->failed(new RuntimeException('boom'));

        $fresh = $message->fresh();
        $this->assertSame('error', $fresh->status);
        $this->assertSame([], $fresh->payload);
    }
}
