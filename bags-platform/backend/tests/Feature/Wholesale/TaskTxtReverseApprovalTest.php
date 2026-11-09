<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use App\Services\Pos\TreasuryService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaskTxtReverseApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    private function bank(string $code): Treasury
    {
        return Treasury::create([
            'name' => 'بنك', 'code' => $code, 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }

    private function safe(string $code): Treasury
    {
        return Treasury::create([
            'name' => 'خزنة', 'code' => $code, 'type' => TreasuryType::MainSafe,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }

    private function creditOrder(float $total): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => $total,
            'subtotal' => $total,
        ]);
    }

    private function freeNotice(float $amount): WholesalePaymentNotice
    {
        return WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'status' => 'pending_review',
        ]);
    }

    public function test_reverse_of_free_notice_reopens_two_fifo_orders_and_records_negative_reversal(): void
    {
        $bank = $this->bank('REV2-'.uniqid());
        $first = $this->creditOrder(500);
        $second = $this->creditOrder(300);
        $notice = $this->freeNotice(800);

        $service = app(WholesalePaymentNoticeService::class);
        $service->approve($notice, $this->admin->id, $bank->id);
        $this->assertSame('paid', $first->fresh()->payment_status);
        $this->assertSame('paid', $second->fresh()->payment_status);

        $historyBefore = OrderStatusHistory::query()->count();

        $reversed = $service->reverse($notice->fresh(), $this->admin->id, 'الإيصال اتسجل بالغلط');

        $this->assertSame('reversed', $reversed->status->value);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertNotNull($reversed->reversal_treasury_transaction_id);
        $this->assertDatabaseHas('treasury_transactions', [
            'id' => $reversed->reversal_treasury_transaction_id,
            'treasury_id' => $bank->id,
            'type' => 'customer_receipt_reversal',
            'amount' => '-800.00',
            'reference_id' => $notice->id,
        ]);
        $this->assertSame('0.00', $bank->fresh()->current_balance);
        $this->assertSame(0, WholesalePaymentAllocation::query()->where('notice_id', $notice->id)->count());
        $this->assertSame('unpaid', $first->fresh()->payment_status);
        $this->assertSame('unpaid', $second->fresh()->payment_status);
        $this->assertGreaterThan($historyBefore, OrderStatusHistory::query()->count());
    }

    public function test_reverse_rejects_blank_reason_without_any_change(): void
    {
        $bank = $this->bank('REVB-'.uniqid());
        $this->creditOrder(1000);
        $notice = $this->freeNotice(600);
        app(WholesalePaymentNoticeService::class)->approve($notice, $this->admin->id, $bank->id);

        $txBefore = $bank->fresh()->current_balance;

        foreach (['', '   '] as $reason) {
            try {
                app(WholesalePaymentNoticeService::class)->reverse($notice->fresh(), $this->admin->id, $reason);
                $this->fail('Blank reversal reason must be rejected.');
            } catch (BusinessException $e) {
                $this->assertSame('سبب عكس الاعتماد مطلوب.', $e->getMessage());
            }
        }

        $this->assertSame('approved', $notice->fresh()->status->value);
        $this->assertSame($txBefore, $bank->fresh()->current_balance);
        $this->assertDatabaseCount('treasury_transactions', 1);
    }

    public function test_reverse_rejects_pending_and_rejected_notices_without_any_change(): void
    {
        $service = app(WholesalePaymentNoticeService::class);

        $pending = $this->freeNotice(100);
        try {
            $service->reverse($pending, $this->admin->id, 'سبب');
            $this->fail('Pending notice reversal must be rejected.');
        } catch (BusinessException) {
        }
        $this->assertSame('pending_review', $pending->fresh()->status->value);

        $rejected = $this->freeNotice(100);
        $service->reject($rejected, $this->admin->id, 'إيصال غير واضح');
        try {
            $service->reverse($rejected->fresh(), $this->admin->id, 'سبب');
            $this->fail('Rejected notice reversal must be rejected.');
        } catch (BusinessException) {
        }
        $this->assertSame('rejected', $rejected->fresh()->status->value);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_reverse_rejects_second_reverse_and_treasuryless_notice(): void
    {
        $bank = $this->bank('REVD-'.uniqid());
        $service = app(WholesalePaymentNoticeService::class);

        $this->creditOrder(1000);
        $notice = $this->freeNotice(200);
        $service->approve($notice, $this->admin->id, $bank->id);
        $service->reverse($notice->fresh(), $this->admin->id, 'سبب أول');
        try {
            $service->reverse($notice->fresh(), $this->admin->id, 'مرة تانية');
            $this->fail('Second reversal must be rejected.');
        } catch (BusinessException) {
        }
        $this->assertDatabaseCount('treasury_transactions', 2);

        $orphan = $this->freeNotice(200);
        $service->approve($orphan, $this->admin->id, $bank->id);
        $orphan->forceFill(['treasury_id' => null])->save();
        try {
            $service->reverse($orphan->fresh(), $this->admin->id, 'سبب');
            $this->fail('Treasury-less reversal must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame('الإشعار من غير حركة خزينة، لا يمكن عكسه.', $e->getMessage());
        }
    }

    public function test_reverse_rejects_when_main_safe_cannot_cover_it(): void
    {
        $safe = $this->safe('REVSAFE-'.uniqid());
        $service = app(WholesalePaymentNoticeService::class);

        $order = $this->creditOrder(600);
        $notice = $this->freeNotice(600);
        $service->approve($notice, $this->admin->id, $safe->id);
        $this->assertSame('600.00', $safe->fresh()->current_balance);

        // Later payouts drain the physical safe below the reversal amount.
        app(TreasuryService::class)->record([
            'treasury_id' => $safe->id,
            'type' => \App\Enums\TreasuryTransactionType::Expense,
            'amount' => '-550.00',
            'created_by' => $this->admin->id,
            'notes' => 'مصروف',
        ]);
        $this->assertSame('50.00', $safe->fresh()->current_balance);

        try {
            $service->reverse($notice->fresh(), $this->admin->id, 'عكس');
            $this->fail('Reversal that would drive a physical safe negative must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame('رصيد الخزينة غير كافٍ لإتمام العملية.', $e->getMessage());
        }

        $this->assertSame('approved', $notice->fresh()->status->value);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('50.00', $safe->fresh()->current_balance);
    }
}
