<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskTxtPartialFifoOutstandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_partial_allocation_reduces_outstanding_balance(): void
    {
        DB::table('settings')->where('key', 'wholesale_fifo_mode')->update(['value' => 'partial']);
        app(SettingService::class)->clearCache();

        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $bank = Treasury::create([
            'name' => 'بنك', 'code' => 'PARTIAL-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
        ]);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 600,
            'status' => 'pending_review',
        ]);

        app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $bank->id);

        // Partial mode applies 600 to the 1000 order without closing it.
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('wholesale_payment_allocations', [
            'notice_id' => $notice->id,
            'order_id' => $order->id,
            'amount' => '600.00',
        ]);

        // The paid 600 must reduce what the customer still owes.
        $this->assertSame(400.0, app(WholesalePaymentNoticeService::class)->outstandingBalance($customer));
        $this->assertSame('400.00', app(WholesalePaymentNoticeService::class)->balanceBreakdown($customer)['outstanding']);
    }
}
