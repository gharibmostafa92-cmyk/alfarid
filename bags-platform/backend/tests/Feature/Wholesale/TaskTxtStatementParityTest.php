<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaskTxtStatementParityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $customer;

    private User $admin;

    private Treasury $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->bank = Treasury::create([
            'name' => 'بنك', 'code' => 'STMT-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }

    private function creditOrder(float $total, OrderStatus $status = OrderStatus::Delivered): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => $status,
            'grand_total' => $total,
            'subtotal' => $total,
        ]);
    }

    private function approveFree(float $amount): WholesalePaymentNotice
    {
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'status' => 'pending_review',
        ]);
        app(WholesalePaymentNoticeService::class)->approve($notice, $this->admin->id, $this->bank->id);

        return $notice->fresh();
    }

    public function test_statement_closing_matches_breakdown_outstanding(): void
    {
        $this->creditOrder(500);
        $this->creditOrder(300);
        $this->creditOrder(200);
        $this->approveFree(600);

        $service = app(WholesalePaymentNoticeService::class);
        $statement = $service->statement($this->customer);
        $breakdown = $service->balanceBreakdown($this->customer);

        $this->assertSame(400.0, $statement['closing_balance']);
        $this->assertSame('400.00', $breakdown['outstanding']);
        $this->assertSame($statement['closing_balance'], (float) $breakdown['outstanding']);
    }

    public function test_cancelled_orders_and_rejected_notices_stay_out_while_refund_is_a_single_credit(): void
    {
        $service = app(WholesalePaymentNoticeService::class);
        $cancelled = $this->creditOrder(700, OrderStatus::Cancelled);

        $rejected = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => 100,
            'status' => 'pending_review',
        ]);
        $service->reject($rejected, $this->admin->id, 'غير واضح');

        $statement = $service->statement($this->customer);
        $references = array_column($statement['entries'], 'reference');
        $this->assertNotContains($cancelled->order_number, $references);
        $this->assertNotContains($rejected->notice_number, $references);

        $this->assertSame(0.0, $statement['closing_balance']);
        $this->assertSame('0.00', $service->balanceBreakdown($this->customer)['outstanding']);
    }

    public function test_refunded_return_appears_as_a_single_credit(): void
    {
        $service = app(WholesalePaymentNoticeService::class);
        $return = ReturnRequest::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'refunded',
            'refunded_at' => now(),
            'total_refund_amount' => 150,
        ]);

        $statement = $service->statement($this->customer);
        $credits = array_values(array_filter(
            $statement['entries'],
            fn ($entry) => $entry['reference'] === $return->return_number
        ));

        $this->assertCount(1, $credits);
        $this->assertSame('0.00', $credits[0]['debit']);
        // Note: refunds credit the statement ledger but do not feed the
        // outstanding balance — owner decision pending on unifying them.
        $this->assertSame(-150.0, $statement['closing_balance']);
        $this->assertSame('0.00', $service->balanceBreakdown($this->customer)['outstanding']);
    }
}
