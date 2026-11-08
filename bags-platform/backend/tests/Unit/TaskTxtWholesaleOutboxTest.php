<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SendOutboundMessage;
use App\Models\Customer;
use App\Models\OutboundMessage;
use App\Services\Wholesale\WholesaleOutbox;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskTxtWholesaleOutboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function customer(?array $hours = null): Customer
    {
        return Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'preferred_contact_hours' => $hours,
        ]);
    }

    public function test_contact_windows_resolve_correctly(): void
    {
        $outbox = app(WholesaleOutbox::class);
        $customer = $this->customer(['from' => '09:00', 'to' => '17:00']);

        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'Africa/Cairo'));
        try {
            $this->assertSame('12:00', $outbox->nextContactAt($customer)->format('H:i'));

            Carbon::setTestNow(Carbon::parse('2026-11-07 07:00:00', 'Africa/Cairo'));
            $this->assertSame('09:00', $outbox->nextContactAt($customer->fresh())->format('H:i'));

            Carbon::setTestNow(Carbon::parse('2026-11-07 20:00:00', 'Africa/Cairo'));
            $next = $outbox->nextContactAt($customer->fresh());
            $this->assertSame('09:00', $next->format('H:i'));
            $this->assertSame('2026-11-08', $next->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overnight_window_is_documented_as_unsupported(): void
    {
        // Documents current behavior: overnight windows (from > to) are not
        // treated as open; whether they should be is an owner decision.
        $outbox = app(WholesaleOutbox::class);
        $customer = $this->customer(['from' => '20:00', 'to' => '08:00']);

        Carbon::setTestNow(Carbon::parse('2026-11-07 23:00:00', 'Africa/Cairo'));
        try {
            $next = $outbox->nextContactAt($customer);
            $this->assertFalse(
                $next->format('Y-m-d H:i') === '2026-11-07 23:00',
                'Overnight window does not resolve to now.'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_hours_fall_back_to_now(): void
    {
        $outbox = app(WholesaleOutbox::class);
        $customer = $this->customer(['from' => 'xx', 'to' => 'yy']);

        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'Africa/Cairo'));
        try {
            $this->assertSame('12:00', $outbox->nextContactAt($customer)->format('H:i'));
        } finally {
            Carbon::setTestNow();
        }

        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'Africa/Cairo'));
        try {
            $this->assertSame(
                '2026-11-07 12:00',
                $outbox->nextContactAt($this->customer(null))->format('Y-m-d H:i')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_duplicate_key_enqueues_single_row_and_single_job(): void
    {
        Queue::fake();
        $outbox = app(WholesaleOutbox::class);
        $customer = $this->customer();

        $outbox->queue('dup-1', 'customer_whatsapp', $customer->id, 'payment_reminder', ['a' => 1], now(), true);
        $outbox->queue('dup-1', 'customer_whatsapp', $customer->id, 'payment_reminder', ['a' => 1], now(), true);

        $this->assertSame(1, OutboundMessage::query()->where('idempotency_key', 'dup-1')->count());
        Queue::assertPushed(SendOutboundMessage::class, 1);
    }

    public function test_missing_opt_in_records_skipped_without_dispatch(): void
    {
        Queue::fake();
        $outbox = app(WholesaleOutbox::class);
        $customer = $this->customer();
        $this->assertFalse($outbox->customerAllowsWhatsapp($customer));

        $outbox->queue('opt-1', 'customer_whatsapp', $customer->id, 'payment_reminder', [], now(), $outbox->customerAllowsWhatsapp($customer));

        $message = OutboundMessage::query()->where('idempotency_key', 'opt-1')->firstOrFail();
        $this->assertSame('skipped', $message->status);
        $this->assertSame('no_opt_in', $message->error);
        Queue::assertNotPushed(SendOutboundMessage::class);
    }
}
