<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use App\Services\SettingService;
use App\Services\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTxtFifoModesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $customer;

    private Treasury $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->bank = Treasury::create([
            'name' => 'بنك', 'code' => 'FIFO-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function openOrder(float $total, ?int $branchId = null): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => $total,
            'subtotal' => $total,
            'allocated_branch_id' => $branchId,
        ]);
    }

    private function approveFree(float $amount, int $adminId): WholesalePaymentNotice
    {
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'status' => 'pending_review',
        ]);
        app(WholesalePaymentNoticeService::class)->approve($notice, $adminId, $this->bank->id);

        return $notice->fresh();
    }

    public function test_invalid_fifo_mode_falls_back_to_strict(): void
    {
        DB::table('settings')->where('key', 'wholesale_fifo_mode')->update(['value' => 'bogus']);
        app(SettingService::class)->clearCache();

        $oldest = $this->openOrder(500);
        $newer = $this->openOrder(300);
        $this->approveFree(400, $this->superAdmin()->id);

        // Strict: the oldest order needs 500, so nothing closes.
        $this->assertSame('unpaid', $oldest->fresh()->payment_status);
        $this->assertSame('unpaid', $newer->fresh()->payment_status);
        $this->assertSame(0, WholesalePaymentAllocation::query()->count());
        $this->assertSame(400.0, app(WholesalePaymentNoticeService::class)->outstandingBalance($this->customer));
    }

    public function test_branch_restricted_admin_settles_only_own_branch_orders(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $ownOrder = $this->openOrder(300, $branchA->id);
        $foreignOrder = $this->openOrder(300, $branchB->id);

        $role = Role::create(['name' => 'fifo-'.uniqid(), 'guard_name' => 'staff']);
        $role->givePermissionTo('payment_notices.approve');
        $restricted = User::factory()->create(['branch_id' => $branchA->id]);
        $restricted->assignRole($role);

        $branchBank = Treasury::create([
            'name' => 'بنك فرع', 'code' => 'FIFOBR-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'branch_id' => $branchA->id, 'current_balance' => 0, 'is_active' => true,
        ]);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => 300,
            'status' => 'pending_review',
        ]);
        app(WholesalePaymentNoticeService::class)->approve($notice, $restricted->id, $branchBank->id);

        $this->assertSame('paid', $ownOrder->fresh()->payment_status);
        $this->assertSame('unpaid', $foreignOrder->fresh()->payment_status);
        $this->assertDatabaseHas('wholesale_payment_allocations', ['order_id' => $ownOrder->id]);
        $this->assertDatabaseMissing('wholesale_payment_allocations', ['order_id' => $foreignOrder->id]);
    }
}
