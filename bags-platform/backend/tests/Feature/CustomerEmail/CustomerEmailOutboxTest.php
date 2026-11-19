<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEmail;

use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerEmail\CustomerEmailOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerEmailOutboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.resend.capture_enabled' => true,
            'services.resend.from' => 'Alfarid <orders@alfarid.example>',
            'services.resend.environment' => 'testing',
        ]);
    }

    public function test_welcome_email_is_snapshotted_once_for_the_account(): void
    {
        $customer = Customer::factory()->create(['email' => ' customer@example.test ']);
        $outbox = app(CustomerEmailOutbox::class);

        $outbox->welcome($customer);
        $outbox->welcome($customer);

        $this->assertDatabaseCount('customer_email_outbox', 1);
        $row = DB::table('customer_email_outbox')->first();
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('customer-email:testing:welcome/'.$customer->id, $row->event_key);
        $this->assertSame('customer@example.test', $payload['to'][0]);
        $this->assertSame('Alfarid <orders@alfarid.example>', $payload['from']);
        $this->assertStringContainsString('تم إنشاء حسابك بنجاح.', $payload['text']);

        $customer->update(['email' => 'changed@example.test']);

        $this->assertSame('customer@example.test', $payload['to'][0]);
    }

    public function test_order_email_uses_the_saved_recipient_and_exact_decimal_total(): void
    {
        $order = Order::factory()->create([
            'guest_email' => 'recipient@example.test',
            'grand_total' => '1234567890.12',
            'subtotal' => '1234567890.12',
        ]);

        app(CustomerEmailOutbox::class)->orderReceived($order);

        $row = DB::table('customer_email_outbox')->first();
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('recipient@example.test', $payload['to'][0]);
        $this->assertStringContainsString('1234567890.12 جنيه مصري', $payload['text']);

        $order->update([
            'guest_email' => 'changed@example.test',
            'grand_total' => '2.00',
            'subtotal' => '2.00',
        ]);

        $this->assertSame('recipient@example.test', $payload['to'][0]);
        $this->assertStringContainsString('1234567890.12 جنيه مصري', $payload['text']);
    }

    public function test_zero_total_is_formatted_with_two_decimal_places(): void
    {
        $order = Order::factory()->create([
            'guest_email' => 'recipient@example.test',
            'grand_total' => '0.00',
            'subtotal' => '0.00',
        ]);

        app(CustomerEmailOutbox::class)->orderReceived($order);

        $row = DB::table('customer_email_outbox')->first();
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('0.00 جنيه مصري', $payload['text']);
    }

    public function test_welcome_and_order_events_with_the_same_numeric_id_remain_distinct(): void
    {
        $id = 900001;
        $customer = Customer::factory()->create(['id' => $id, 'email' => 'customer@example.test']);
        $order = Order::factory()->create([
            'id' => $id,
            'guest_email' => 'recipient@example.test',
            'grand_total' => '100.00',
            'subtotal' => '100.00',
        ]);

        $this->assertSame($customer->id, $order->id);

        $outbox = app(CustomerEmailOutbox::class);
        $outbox->welcome($customer);
        $outbox->orderReceived($order);

        $this->assertDatabaseCount('customer_email_outbox', 2);
    }

    public function test_invalid_or_missing_recipient_and_disabled_capture_create_no_row(): void
    {
        $outbox = app(CustomerEmailOutbox::class);

        $outbox->welcome(Customer::factory()->create(['email' => null]));
        $outbox->welcome(Customer::factory()->create(['email' => 'one@example.test,two@example.test']));
        $outbox->orderReceived(Order::factory()->create(['guest_email' => null]));

        config(['services.resend.capture_enabled' => false]);
        $outbox->welcome(Customer::factory()->create(['email' => 'disabled@example.test']));

        $this->assertDatabaseCount('customer_email_outbox', 0);
    }

    public function test_outbox_insert_rolls_back_with_the_business_transaction(): void
    {
        $customer = Customer::factory()->create(['email' => 'rollback@example.test']);
        $outbox = app(CustomerEmailOutbox::class);

        try {
            DB::transaction(function () use ($outbox, $customer): void {
                $outbox->welcome($customer);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('customer_email_outbox', 0);
    }
}
