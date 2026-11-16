<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Finance\TreasuryType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use App\Services\Orders\ReturnService;
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

        $this->assertSame('400.00', $statement['closing_balance']);
        $this->assertSame('400.00', $breakdown['outstanding']);
        $this->assertSame($statement['closing_balance'], $breakdown['outstanding']);
    }

    public function test_statement_range_keeps_opening_balance_and_orders_same_day_debits_first(): void
    {
        $this->creditOrder(500)->forceFill(['created_at' => '2026-11-09 21:59:59'])->save();
        $start = $this->creditOrder(300);
        $start->forceFill(['created_at' => '2026-11-09 22:00:00'])->save();
        $end = $this->creditOrder(200);
        $end->forceFill(['created_at' => '2026-11-10 21:59:59'])->save();
        $this->creditOrder(900)->forceFill(['created_at' => '2026-11-10 22:00:00'])->save();
        $notice = $this->approveFree(100);
        $notice->forceFill(['approved_at' => '2026-11-09 22:00:00'])->save();

        $token = $this->customer->createToken('wholesale-statement')->plainTextToken;
        $entries = $this->withToken($token)->getJson('/api/v1/wholesale/statement?from=2026-11-10&to=2026-11-10')
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '500.00')
            ->assertJsonPath('data.closing_balance', '900.00')
            ->assertJsonCount(3, 'data.entries')
            ->assertJsonPath('data.entries.2.reference', $notice->notice_number)
            ->json('data.entries');
        $this->assertEqualsCanonicalizing(
            [$start->order_number, $end->order_number],
            array_column(array_slice($entries, 0, 2), 'reference'),
        );
        $this->getJson('/api/v1/wholesale/statement?from=2026-11-11&to=2026-11-10')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/wholesale/invoices?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
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

        $this->assertSame('0.00', $statement['closing_balance']);
        $this->assertSame('0.00', $service->balanceBreakdown($this->customer)['outstanding']);
    }

    public function test_refunded_return_appears_as_a_single_credit(): void
    {
        $service = app(WholesalePaymentNoticeService::class);
        $order = $this->creditOrder(150);
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'status' => 'refunded',
            'refund_method' => RefundMethod::CreditNote,
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
        $this->assertSame('0.00', $statement['closing_balance']);
        $this->assertSame('0.00', $service->balanceBreakdown($this->customer)['outstanding']);
    }

    public function test_credit_note_reduces_unpaid_order_debt_and_payment_settles_only_the_remainder(): void
    {
        $order = $this->creditOrder(1000);
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'status' => 'received',
            'total_refund_amount' => 300,
        ]);

        $refunded = app(ReturnService::class)->refund($return, $this->admin, [
            'refund_method' => RefundMethod::CreditNote->value,
        ]);

        $this->assertSame(RefundMethod::CreditNote, $refunded->refund_method);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('treasury_transactions', 0);
        $this->assertDatabaseCount('customer_wallet_transactions', 0);

        $service = app(WholesalePaymentNoticeService::class);
        $this->assertSame('700.00', $service->balanceBreakdown($this->customer)['outstanding']);
        $this->assertSame('700.00', $service->statement($this->customer)['closing_balance']);

        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'order_id' => $order->id,
            'amount' => 700,
            'status' => 'pending_review',
        ]);
        $service->approve($notice, $this->admin->id, $this->bank->id);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('0.00', $service->balanceBreakdown($this->customer)['outstanding']);
        $this->assertSame('0.00', $service->statement($this->customer)['closing_balance']);
    }

    public function test_full_credit_note_settles_order_without_cash_movement(): void
    {
        $order = $this->creditOrder(1000);
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id, 'customer_id' => $this->customer->id,
            'status' => 'received', 'total_refund_amount' => 1000,
        ]);
        app(ReturnService::class)->refund($return, $this->admin, ['refund_method' => RefundMethod::CreditNote->value]);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('0.00', app(WholesalePaymentNoticeService::class)->balanceBreakdown($this->customer)['outstanding']);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }
}
