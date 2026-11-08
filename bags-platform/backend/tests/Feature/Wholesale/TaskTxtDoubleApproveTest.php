<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskTxtDoubleApproveTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private Treasury $bank;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->adminToken = $admin->createToken('admin')->plainTextToken;

        $this->bank = Treasury::create([
            'name' => 'الحساب البنكي الرئيسي',
            'code' => 'DBL-'.uniqid(),
            'type' => TreasuryType::BankAccount,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->adminToken);
    }

    private function freeNotice(int $amount = 600): WholesalePaymentNotice
    {
        Order::factory()->create([
            'customer_id' => $this->trader->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
        ]);

        return WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->trader->id,
            'amount' => $amount,
            'status' => 'pending_review',
        ]);
    }

    public function test_approve_locks_notice_row_for_update(): void
    {
        $notice = $this->freeNotice();

        DB::enableQueryLog();

        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
            'treasury_id' => $this->bank->id,
        ])->assertOk();

        $locked = collect(DB::getQueryLog())->contains(
            fn ($entry) => str_contains((string) $entry['query'], 'wholesale_payment_notices')
                && str_contains(strtolower((string) $entry['query']), 'for update')
        );

        $this->assertTrue($locked, 'approve() must SELECT the notice row FOR UPDATE so concurrent approves serialize.');
    }

    public function test_second_approve_is_rejected_with_single_receipt_and_no_duplicate_allocations(): void
    {
        $notice = $this->freeNotice();

        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
            'treasury_id' => $this->bank->id,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $allocationsAfterFirst = WholesalePaymentAllocation::query()->count();

        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
            'treasury_id' => $this->bank->id,
        ])->assertUnprocessable()->assertJsonPath('message', 'إشعار السداد هذا تمت مراجعته من قبل.');

        $this->assertSame('approved', $notice->fresh()->status->value);
        $this->assertDatabaseCount('treasury_transactions', 1);
        $this->assertSame('600.00', $this->bank->fresh()->current_balance);
        $this->assertSame(
            $allocationsAfterFirst,
            WholesalePaymentAllocation::query()->count(),
            'The rejected second approve must not duplicate allocations.'
        );
    }
}
