<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Events\Wholesale\PaymentNoticeReviewed;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\WholesaleInsights;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class Task6Slice2RegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('inventory.all_branches');
    }

    private function wholesaleCustomer(float $creditLimit = 50000): Customer
    {
        return Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => $creditLimit,
            'payment_terms_days' => 30,
        ]);
    }

    private function creditOrder(Customer $customer, float $total, string $status = 'confirmed'): Order
    {
        return Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $customer->id,
            'status' => $status,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'due_at' => now()->addDays(30),
            'subtotal' => $total,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'cod_fee' => 0,
            'tax_amount' => 0,
            'grand_total' => $total,
        ]);
    }

    public function test_f04_unapproved_notice_allocations_are_ignored_in_coverage_and_insights(): void
    {
        $customer = $this->wholesaleCustomer(10000);
        $order = $this->creditOrder($customer, 9500);

        $pendingNotice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-UNAPP-1',
            'customer_id' => $customer->id,
            'order_id' => null,
            'amount' => '5000.00',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-UNAPP-1',
            'transfer_date' => '2026-11-10',
            'receipt_file_path' => 'receipts/unapp.jpg',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        // Temporarily disable trigger or insert allocation via SQL if trigger blocks it;
        // if DB trigger blocks non-approved insert, test the service query defense directly via a reversed/pending simulation.
        DB::statement('ALTER TABLE wholesale_payment_allocations DISABLE TRIGGER ALL');
        try {
            WholesalePaymentAllocation::query()->create([
                'notice_id' => $pendingNotice->id,
                'order_id' => $order->id,
                'amount' => '5000.00',
                'created_by' => $this->admin->id,
            ]);
        } finally {
            DB::statement('ALTER TABLE wholesale_payment_allocations ENABLE TRIGGER ALL');
        }

        $service = app(WholesalePaymentNoticeService::class);
        $breakdown = $service->balanceBreakdown($customer);
        $this->assertSame('0.00', $breakdown['allocated']);

        // Credit utilization is 9500/10000 (95%) since pending allocation must be ignored.
        $suggestions = app(WholesaleInsights::class)->creditSuggestions();
        $this->assertCount(1, $suggestions);
        $this->assertSame($customer->id, $suggestions[0]['customer_id']);
    }

    public function test_f05_release_excess_allocations_and_settle_credit_note_are_atomic_and_settle_other_orders(): void
    {
        $customer = $this->wholesaleCustomer(50000);
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = Treasury::query()->create([
            'name' => 'بنك مركزي',
            'code' => 'BANK-F05',
            'type' => 'bank_account',
            'branch_id' => null,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $order1 = $this->creditOrder($customer, 1000);
        $order2 = $this->creditOrder($customer, 400);

        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-F05-1',
            'customer_id' => $customer->id,
            'order_id' => $order1->id,
            'amount' => '1000.00',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-F05-1',
            'transfer_date' => '2026-11-10',
            'receipt_file_path' => 'receipts/f05.jpg',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $service = app(WholesalePaymentNoticeService::class);
        $service->approve($notice, $this->admin->id, $treasury->id);
        $this->assertSame('paid', $order1->fresh()->payment_status);

        // Simulate a 400 EGP credit note on order1: should unlink notice, retain 600 on order1, and release 400.
        $released = $service->releaseExcessAllocationsForCreditNote($order1->fresh(), '400.00', $this->admin->id);
        $this->assertSame('400.00', $released);
        $this->assertNull($notice->fresh()->order_id);

        // Now settleCreditNote should apply the freed 400 EGP toward order2 via FIFO.
        $service->settleCreditNote($order1->fresh(), $this->admin->id, 'RET-F05-1');
        $this->assertDatabaseHas('wholesale_payment_allocations', [
            'notice_id' => $notice->id,
            'order_id' => $order2->id,
            'amount' => '400.00',
        ]);
    }

    public function test_f06_statement_excludes_credit_notes_on_cancelled_or_non_credit_orders(): void
    {
        $customer = $this->wholesaleCustomer(50000);
        $creditOrder = $this->creditOrder($customer, 1000);
        $cancelledCreditOrder = $this->creditOrder($customer, 800, OrderStatus::Cancelled->value);
        $codOrder = Order::factory()->create([
            'channel' => 'wholesale',
            'customer_id' => $customer->id,
            'status' => OrderStatus::Delivered->value,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'subtotal' => 500,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'cod_fee' => 0,
            'tax_amount' => 0,
            'grand_total' => 500,
        ]);

        ReturnRequest::query()->create([
            'return_number' => 'RET-CANCELLED-1',
            'order_id' => $cancelledCreditOrder->id,
            'customer_id' => $customer->id,
            'status' => 'refunded',
            'refund_method' => RefundMethod::CreditNote,
            'reason' => 'test',
            'total_refund_amount' => 300,
            'refunded_at' => now(),
        ]);

        ReturnRequest::query()->create([
            'return_number' => 'RET-COD-1',
            'order_id' => $codOrder->id,
            'customer_id' => $customer->id,
            'status' => 'refunded',
            'refund_method' => RefundMethod::CreditNote,
            'reason' => 'test',
            'total_refund_amount' => 200,
            'refunded_at' => now(),
        ]);

        $service = app(WholesalePaymentNoticeService::class);
        $breakdown = $service->balanceBreakdown($customer);
        $statement = $service->statement(
            $customer,
            now()->subDays(5)->toDateString(),
            now()->addDays(1)->toDateString(),
        );

        $this->assertSame('1000.00', $breakdown['outstanding']);
        $this->assertSame($breakdown['outstanding'], $statement['closing_balance']);
    }

    public function test_f08_reverse_dispatches_reviewed_event_with_reversed_message_and_rejects_inactive_treasury(): void
    {
        $customer = $this->wholesaleCustomer(50000);
        $treasury = Treasury::query()->create([
            'name' => 'بنك مركزي',
            'code' => 'BANK-F05',
            'type' => 'bank_account',
            'branch_id' => null,
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $order = $this->creditOrder($customer, 1000);

        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-REV-1',
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'amount' => '1000.00',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-REV-1',
            'transfer_date' => '2026-11-10',
            'receipt_file_path' => 'receipts/rev.jpg',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $service = app(WholesalePaymentNoticeService::class);
        $approved = $service->approve($notice, $this->admin->id, $treasury->id);

        // Null treasury_id must be rejected on reverse.
        WholesalePaymentNotice::query()->whereKey($approved->id)->update(['treasury_id' => null]);
        try {
            $service->reverse($approved->fresh(), $this->admin->id, 'شيك مرتد');
            $this->fail('Expected BusinessException for null treasury_id.');
        } catch (\App\Exceptions\BusinessException) {
            $this->addToAssertionCount(1);
        }

        // Restore treasury_id and reverse: must notify customer with reversed message, not rejected.
        WholesalePaymentNotice::query()->whereKey($approved->id)->update(['treasury_id' => $treasury->id]);
        $reversed = $service->reverse($approved->fresh(), $this->admin->id, 'شيك مرتد');

        $this->assertSame(WholesalePaymentNoticeStatus::Reversed, $reversed->status);
        $notifs = DB::table('notifications')
            ->where('notifiable_type', Customer::class)
            ->where('notifiable_id', $customer->id)
            ->get()
            ->map(fn ($row) => json_decode((string) $row->data, true));
        $reversedNotif = $notifs->firstWhere('status', 'reversed');
        $this->assertNotNull($reversedNotif);
        $this->assertStringContainsString('عكس', (string) ($reversedNotif['message'] ?? ''));
        $this->assertStringNotContainsString('اترفض', (string) ($reversedNotif['message'] ?? ''));
    }

    public function test_f21_credit_suggestions_ranks_beyond_first_100_customers(): void
    {
        // Create 102 wholesale customers with 0 utilization first.
        for ($i = 0; $i < 102; $i++) {
            $this->wholesaleCustomer(10000);
        }

        // Customer #103 has 95% utilization.
        $highUtil = $this->wholesaleCustomer(10000);
        $this->creditOrder($highUtil, 9500);

        $suggestions = app(WholesaleInsights::class)->creditSuggestions();
        $ids = array_column($suggestions, 'customer_id');
        $this->assertContains($highUtil->id, $ids);
    }
}
