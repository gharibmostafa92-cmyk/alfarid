<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Jobs\ScoreWholesaleReceipt;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\BankStatementMatcher;
use App\Services\Wholesale\WholesaleInsights;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2RoadmapSlice3Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    private function wholesaleCustomer(): Customer
    {
        return Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => 500000,
        ]);
    }

    private function admin(): User
    {
        Permission::findOrCreate('inventory.all_branches', 'staff');
        $u = User::factory()->create(['branch_id' => null]);
        $u->givePermissionTo('inventory.all_branches');

        return $u;
    }

    private function treasury(): Treasury
    {
        return Treasury::query()->create([
            'name' => 'Bank Main',
            'code' => 'BANK-'.uniqid(),
            'type' => TreasuryType::BankAccount,
            'is_active' => true,
            'opening_balance' => 0,
            'current_balance' => 0,
        ]);
    }

    private function creditOrder(Customer $customer, float $total, ?int $branchId = null, ?\DateTimeInterface $dueAt = null): Order
    {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => 'wholesale',
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'grand_total' => $total,
            'subtotal' => $total,
            'allocated_branch_id' => $branchId,
            'due_at' => $dueAt,
        ]);
    }

    // CRITICAL-02: Releasing excess FIFO allocation when issuing CreditNote & DB guard includes credit notes
    public function test_credit_note_on_paid_credit_order_returns_excess_to_customer_balance(): void
    {
        $customer = $this->wholesaleCustomer();
        $admin = $this->admin();
        $order = $this->creditOrder($customer, 1000);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id, 'order_id' => $order->id,
            'amount' => 1000, 'status' => 'pending_review',
        ]);
        $service = app(WholesalePaymentNoticeService::class);
        $service->approve($notice, $admin->id, $this->treasury()->id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id, 'customer_id' => $customer->id,
            'status' => 'received', 'total_refund_amount' => 300,
        ]);

        app(\App\Services\Orders\ReturnService::class)->refund($return, $admin, ['refund_method' => 'credit_note']);

        $this->assertSame('-300.00', $service->balanceBreakdown($customer)['outstanding']);
        $this->assertSame('-300.00', $service->statement($customer)['closing_balance']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $service->reverse($notice->fresh(), $admin->id, 'عكس الإشعار');
        $this->assertSame('700.00', $service->balanceBreakdown($customer)['outstanding']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_database_rejects_overallocation_when_only_a_credit_note_changes(): void
    {
        $customer = $this->wholesaleCustomer();
        $order = $this->creditOrder($customer, 1000);
        WholesalePaymentNotice::factory()->create(['customer_id' => $customer->id, 'order_id' => $order->id, 'amount' => 800, 'status' => 'approved']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(function () use ($customer, $order): void {
            ReturnRequest::factory()->create(['customer_id' => $customer->id, 'order_id' => $order->id, 'status' => 'refunded', 'refund_method' => 'credit_note', 'total_refund_amount' => 300, 'refunded_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_credit_note_releases_excess_fifo_allocation_to_free_pool(): void
    {
        $customer = $this->wholesaleCustomer();
        $admin = $this->admin();
        $treasury = $this->treasury();

        $order = $this->creditOrder($customer, 1000);

        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-CR-1',
            'customer_id' => $customer->id,
            'order_id' => null,
            'amount' => 600,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-CR-1',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => 'receipts/test.png',
        ]);
        // Settle 600 via FIFO partial allocation onto $order
        DB::table('settings')->updateOrInsert(['key' => 'wholesale_fifo_mode'], ['value' => 'partial', 'type' => 'string']);
        $service = app(WholesalePaymentNoticeService::class);
        $service->approve($notice, $admin->id, $treasury->id);

        $this->assertSame('600.00', (string) WholesalePaymentAllocation::query()->where('order_id', $order->id)->sum('amount'));

        // Now release excess allocations for a 600 credit note
        DB::transaction(function () use ($service, $order, $admin): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $service->releaseExcessAllocationsForCreditNote($locked, '600.00', $admin->id);
            $service->assertCreditNoteCapacity($locked, '600.00');
        });

        // 600 + 600 > 1000 => 200 of the FIFO allocation was released back to free_pool, leaving 400 allocated
        $this->assertSame(400.0, (float) WholesalePaymentAllocation::query()->where('order_id', $order->id)->sum('amount'));
    }

    // CRITICAL-03: Free FIFO settlement is customer-wide and ignores branch restriction of approver
    public function test_free_fifo_settlement_is_not_restricted_to_approver_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $branchStaffA = User::factory()->create(['branch_id' => $branchA->id]);
        $branchATreasury = Treasury::query()->create([
            'name' => 'Branch A Safe',
            'code' => 'SAFE-A-'.uniqid(),
            'type' => TreasuryType::MainSafe,
            'branch_id' => $branchA->id,
            'is_active' => true,
            'opening_balance' => 0,
            'current_balance' => 0,
        ]);
        $customer = $this->wholesaleCustomer();

        // Older order belongs to Branch B; newer order belongs to Branch A
        $olderBranchB = $this->creditOrder($customer, 500, $branchB->id);
        $newerBranchA = $this->creditOrder($customer, 500, $branchA->id);

        // Existing approved free notice (e.g. from an earlier cancelled order)
        WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-FIFO-FREE',
            'customer_id' => $customer->id,
            'order_id' => null,
            'amount' => 500,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-FIFO-FREE',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::Approved,
            'approved_at' => now()->subHour(),
            'receipt_file_path' => 'receipts/test.png',
        ]);

        // Linked notice on Branch A's order approved by Branch A staff
        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-FIFO-1',
            'customer_id' => $customer->id,
            'order_id' => $newerBranchA->id,
            'amount' => 100,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-FIFO-1',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => 'receipts/test.png',
        ]);

        app(WholesalePaymentNoticeService::class)->approve($notice, $branchStaffA->id, $branchATreasury->id);

        // Under Task 3 CRITICAL-03 branch isolation, branch-scoped approver cannot settle orders of other branches
        $this->assertSame('unpaid', $olderBranchB->fresh()->payment_status);
        $this->assertSame('paid', $newerBranchA->fresh()->payment_status);
    }

    // HIGH-05 & MEDIUM-02: Rejected/Reversed notices allow resubmission; Arabic/dashed reference numbers caught cleanly on approve
    public function test_rejected_and_reversed_notices_allow_reference_resubmission_and_approve_handles_duplicate_identity(): void
    {
        Storage::fake('r2');
        $customer = $this->wholesaleCustomer();
        $admin = $this->admin();
        $treasury = $this->treasury();
        $this->creditOrder($customer, 5000);

        WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-REJ-1',
            'customer_id' => $customer->id,
            'amount' => 500,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'TRX-998877',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::Rejected,
            'receipt_file_path' => 'receipts/test.png',
        ]);

        $service = app(WholesalePaymentNoticeService::class);
        $receipt = UploadedFile::fake()->image('slip.png');
        $resubmitted = $service->create($customer, [
            'amount' => 500,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'TRX-998877',
            'transfer_date' => now()->toDateString(),
        ], $receipt);

        $this->assertSame(WholesalePaymentNoticeStatus::PendingReview, $resubmitted->status);

        // Approve first notice with normalized identity
        $service->approve($resubmitted, $admin->id, $treasury->id);

        // Second customer (or same customer if pending before first was approved) with Arabic digits/dashes
        $customer2 = $this->wholesaleCustomer();
        $this->creditOrder($customer2, 5000);
        $dup = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-DUP-1',
            'customer_id' => $customer2->id,
            'amount' => 600,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'TRX٩٩٨٨٧٧',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => 'receipts/test.png',
        ]);

        $this->expectException(BusinessException::class);
        $service->approve($dup, $admin->id, $treasury->id);
    }

    // MEDIUM-05: WholesaleInsights excludes cancelled orders, subtracts partial allocations, and anomalies targets PendingReview latest
    public function test_credit_suggestions_subtract_unallocated_customer_credit(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 1000]);
        $this->creditOrder($customer, 950);
        WholesalePaymentNotice::factory()->create(['customer_id' => $customer->id, 'order_id' => null, 'amount' => 500, 'status' => 'approved']);
        $ids = array_column(app(WholesaleInsights::class)->creditSuggestions(), 'customer_id');
        $this->assertNotContains($customer->id, $ids);
    }

    public function test_statement_preserves_cents_when_the_total_exceeds_float_precision(): void
    {
        $customer = $this->wholesaleCustomer();
        foreach (range(1, 100) as $_) $this->creditOrder($customer, 999999999999.00);
        $this->creditOrder($customer, 0.01);
        $result = app(WholesalePaymentNoticeService::class)->statement($customer);
        $this->assertSame('99999999999900.01', $result['closing_balance']);
    }

    public function test_wholesale_insights_credit_utilization_and_anomalies(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => 1000,
        ]);
        $admin = $this->admin();
        $treasury = $this->treasury();

        // Cancelled overdue order should NOT disqualify or count toward utilization
        Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => 'wholesale',
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Cancelled,
            'grand_total' => 950,
            'due_at' => now()->subDays(5),
        ]);

        // Active order of 950 with 500 partial allocation -> net used is 450 (45% < 90%)
        $order = $this->creditOrder($customer, 950, null, now()->subDays(2));
        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-INS-1',
            'customer_id' => $customer->id,
            'amount' => 500,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-INS-1',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => 'receipts/test.png',
        ]);
        DB::table('settings')->updateOrInsert(['key' => 'wholesale_fifo_mode'], ['value' => 'partial', 'type' => 'string']);
        app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $treasury->id);

        $insights = app(WholesaleInsights::class);
        $priorities = collect($insights->collectionPriorities())->firstWhere('order_number', $order->order_number);
        $this->assertNotNull($priorities);
        $this->assertEquals(450.0, (float) $priorities['amount']);

        // Approved notice with OCR gap should NOT appear in anomalies(); PendingReview SHOULD
        $notice->update(['ocr_payload' => ['amount' => '100.00']]);
        $pendingAnomaly = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-ANOM-1',
            'customer_id' => $customer->id,
            'amount' => 300,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-ANOM-1',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'ocr_payload' => ['amount' => '250.00'],
            'receipt_file_path' => 'receipts/test.png',
        ]);

        $anomalyNumbers = collect($insights->anomalies())->pluck('notice_number')->all();
        $this->assertContains($pendingAnomaly->notice_number, $anomalyNumbers);
        $this->assertNotContains($notice->notice_number, $anomalyNumbers);
    }

    // MEDIUM-09: ScoreWholesaleReceipt has $tries/$timeout and skips non-pending notices
    public function test_score_wholesale_receipt_job_configuration(): void
    {
        $job = new ScoreWholesaleReceipt(1);
        $this->assertSame(2, $job->tries);
        $this->assertSame(45, $job->timeout);
    }

    // LOW-01: BankStatementMatcher two-pass matching prevents probable match from stealing exact match
    public function test_bank_statement_matcher_prioritizes_exact_over_probable(): void
    {
        $customer = $this->wholesaleCustomer();
        $today = now()->toDateString();

        $notice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-MATCH-1',
            'customer_id' => $customer->id,
            'amount' => 1000,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'EXACT-REF-99',
            'transfer_date' => $today,
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => 'receipts/test.png',
        ]);
        $probableNotice = WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-MATCH-2',
            'customer_id' => $customer->id,
            'amount' => 1000,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'OTHER-REF-99',
            'transfer_date' => $today,
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => 'receipts/test-2.png',
        ]);

        $result = app(BankStatementMatcher::class)->match([
            // Row 0: same amount & date, NO reference (would greedily steal as probable in 1-pass!)
            ['amount' => 1000, 'reference' => '', 'date' => $today],
            // Row 1: same amount & date & EXACT reference
            ['amount' => 1000, 'reference' => 'EXACT-REF-99', 'date' => $today],
        ]);

        $this->assertCount(1, $result['exact']);
        $this->assertSame($notice->id, $result['exact'][0]['notice_id']);
        $this->assertSame(1, $result['exact'][0]['row']);
        $this->assertCount(1, $result['probable']);
        $this->assertSame(0, $result['probable'][0]['row']);
        $this->assertSame($probableNotice->id, $result['probable'][0]['notice_id']);
    }
}
