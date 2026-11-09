<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaskTxtReminderCancelledTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_reminders_skip_cancelled_orders(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'whatsapp_opt_in_at' => now(),
        ]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Cancelled,
            'due_at' => now()->addDay(),
            'grand_total' => 800,
        ]);

        $this->artisan('wholesale:payment-reminders')->assertSuccessful();

        $this->assertSame(0, OutboundMessage::query()->count());
    }
}
