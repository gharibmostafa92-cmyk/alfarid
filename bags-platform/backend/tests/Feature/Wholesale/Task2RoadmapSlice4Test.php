<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Notifications\Wholesale\WholesaleDeskNotification;
use App\Services\AI\GeminiClient;
use App\Services\Shared\SpokenQuantity;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task2RoadmapSlice4Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->fakeUploads();
    }

    public function test_read_order_image_rejects_unapproved_customer_before_gemini(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'pending',
        ]);

        $this->mock(GeminiClient::class)
            ->shouldNotReceive('generateStructured');

        $file = UploadedFile::fake()->image('order.jpg', 100, 100);
        $reply = app(CustomerWholesaleAssistant::class)->readOrderImage($customer, $file);

        $this->assertNotSame('', $reply);
    }

    public function test_transcribe_and_reply_rejects_unverified_channel_before_gemini(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
        ]);

        $this->mock(GeminiClient::class)
            ->shouldNotReceive('generateStructured');

        $audio = UploadedFile::fake()->create('voice.ogg', 10, 'audio/ogg');
        $reply = app(CustomerWholesaleAssistant::class)->transcribeAndReply($customer, $audio, '+201099999999');

        $this->assertSame('رقم واتساب غير متاح للحساب.', $reply);
    }

    public function test_handoff_to_sales_truncates_long_message_to_500_chars(): void
    {
        Notification::fake();

        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('Super Admin');
        $staff->givePermissionTo('quotations.price');

        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
        ]);
        CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => CustomerChannel::canonicalPhone($customer->phone),
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $longText = 'عايز اتكلم مع موظف مبيعات ' . str_repeat('أ', 900);
        app(CustomerWholesaleAssistant::class)->reply($customer, $longText);

        Notification::assertSentTo($staff, WholesaleDeskNotification::class, function (WholesaleDeskNotification $n): bool {
            $body = $n->toArray(new User)['message'] ?? '';

            return mb_strlen($body) < 600;
        });
    }

    public function test_registration_rejects_duplicate_email_before_uploading_to_r2(): void
    {
        $existing = Customer::factory()->create([
            'email' => 'dup-ws@example.com',
            'phone' => '01011112222',
        ]);

        $response = $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر مكرر',
            'email' => $existing->email,
            'phone' => '01033334444',
            'password' => 'secure-password123',
            'company_name' => 'شركة التجربة',
            'documents' => [
                UploadedFile::fake()->create('register.pdf', 100, 'application/pdf'),
            ],
            'document_types' => ['commercial_register'],
        ]);

        $response->assertStatus(422);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_spoken_quantity_preserves_digits_embedded_in_sku_tokens(): void
    {
        $cleaned = SpokenQuantity::stripQuantityTokens('عايز 50 BAG100');

        $this->assertSame('عايز BAG100', $cleaned);
    }

    public function test_order_parser_does_not_treat_sku_digits_as_a_quantity(): void
    {
        $method = new \ReflectionMethod(CustomerWholesaleAssistant::class, 'parseOrderLines');
        $lines = $method->invoke(app(CustomerWholesaleAssistant::class), 'عايز BAG100');
        $this->assertSame([['qty' => 1.0, 'query' => 'BAG100']], $lines);
    }

    public function test_unsupported_fractional_spoken_quantity_requests_clarification(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        CustomerChannel::query()->create(['customer_id' => $customer->id, 'phone' => $customer->phone, 'provider' => 'whatsapp_cloud', 'verification_status' => 'verified', 'verified_at' => now()]);
        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'عايز دستة ونص BAG100');
        $this->assertStringContainsString('وضح الكمية', $reply);
    }

    public function test_payment_reminders_ignores_skipped_in_weekly_cap_and_skips_already_queued_order(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
        ]);

        $due = now()->addDay();
        $alreadyQueuedOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed->value,
            'due_at' => $due,
        ]);

        // Already queued reminder for this order+due date
        OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'template' => 'payment_reminder',
            'payload' => [],
            'status' => 'sent',
            'idempotency_key' => 'payment-reminder:' . $alreadyQueuedOrder->id . ':' . $due->toDateString(),
        ]);

        // 2 skipped/error rows should NOT count against weekly cap of 2
        OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'template' => 'payment_reminder',
            'payload' => [],
            'status' => 'skipped',
            'idempotency_key' => 'skipped-1',
        ]);
        OutboundMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'template' => 'payment_reminder',
            'payload' => [],
            'status' => 'error',
            'idempotency_key' => 'error-1',
        ]);

        $freshDue = now()->addDays(2);
        $freshOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed->value,
            'due_at' => $freshDue,
        ]);

        $this->artisan('wholesale:payment-reminders')->assertSuccessful();

        $this->assertDatabaseHas('outbound_messages', [
            'idempotency_key' => 'payment-reminder:' . $freshOrder->id . ':' . $freshDue->toDateString(),
        ]);
        $this->assertSame(
            1,
            OutboundMessage::query()
                ->where('idempotency_key', 'payment-reminder:' . $alreadyQueuedOrder->id . ':' . $due->toDateString())
                ->count()
        );
    }

    public function test_escalate_stale_wholesale_notifies_payment_notice_approvers(): void
    {
        Notification::fake();

        $perm = Permission::query()->firstOrCreate(['name' => 'payment_notices.approve', 'guard_name' => 'staff']);
        $role = Role::query()->firstOrCreate(['name' => 'Notice Approver', 'guard_name' => 'staff']);
        $role->givePermissionTo($perm);

        $approver = User::factory()->create(['is_active' => true]);
        $approver->assignRole($role);

        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-STALE-1',
            'customer_id' => $customer->id,
            'amount' => '500.00',
            'bank_name' => 'CIB',
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'receipts/stale.jpg',
            'reference_number' => 'STALE-1',
            'payment_method' => 'bank_transfer',
            'status' => 'pending_review',
        ]);
        WholesalePaymentNotice::query()->whereKey($notice->id)->update(['created_at' => now()->subHours(72)]);

        $this->artisan('wholesale:escalate-stale')->assertSuccessful();

        Notification::assertSentTo($approver, WholesaleDeskNotification::class);
    }
}
