<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\WholesalePaymentMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** F-03: only approved notices grant allocation capacity; unapproved notices grant zero. */
class WholesaleAllocationGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_pending_notice_grants_no_allocation_capacity(): void
    {
        $order = $this->creditOrder(1000.0);
        $notice = $this->makeNotice($order, 1000.0, WholesalePaymentNoticeStatus::PendingReview);

        try {
            WholesalePaymentAllocation::create([
                'notice_id' => $notice->id,
                'order_id' => $order->id,
                'amount' => 100.0,
            ]);
            // Allocation guards are deferred to commit; flush them like a commit would.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('An allocation on a pending notice must be rejected by the database guard.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? $e->getCode());
        }
    }

    public function test_rejected_notice_grants_no_allocation_capacity(): void
    {
        $order = $this->creditOrder(1000.0);
        $notice = $this->makeNotice($order, 1000.0, WholesalePaymentNoticeStatus::Rejected);

        try {
            WholesalePaymentAllocation::create([
                'notice_id' => $notice->id,
                'order_id' => $order->id,
                'amount' => 50.0,
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('An allocation on a rejected notice must be rejected by the database guard.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? $e->getCode());
        }
    }

    public function test_approved_notice_allows_allocation_within_its_amount(): void
    {
        $order = $this->creditOrder(1000.0);
        // Standalone credit: a linked notice already covers the order by
        // itself, so allocation capacity is exercised without an order link.
        $notice = $this->makeNotice($order, 1000.0, WholesalePaymentNoticeStatus::Approved, false);

        $allocation = WholesalePaymentAllocation::create([
            'notice_id' => $notice->id,
            'order_id' => $order->id,
            'amount' => 400.0,
        ]);

        $this->assertNotNull($allocation->getKey());
    }

    public function test_reversing_a_notice_with_allocations_is_rejected_until_allocations_move(): void
    {
        $order = $this->creditOrder(1000.0);
        $notice = $this->makeNotice($order, 1000.0, WholesalePaymentNoticeStatus::Approved, false);
        WholesalePaymentAllocation::create([
            'notice_id' => $notice->id,
            'order_id' => $order->id,
            'amount' => 400.0,
        ]);

        try {
            $notice->update(['status' => WholesalePaymentNoticeStatus::Reversed]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Reversing a notice must force its allocations to move in the same transaction.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? $e->getCode());
        }
    }

    private function creditOrder(float $total): Order
    {
        return Order::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => $total,
            'subtotal' => $total,
        ]);
    }

    private function makeNotice(Order $order, float $amount, WholesalePaymentNoticeStatus $status, bool $linked = true): WholesalePaymentNotice
    {
        return WholesalePaymentNotice::create([
            'notice_number' => 'F03-'.Str::uuid(),
            'customer_id' => $order->customer_id,
            'order_id' => $linked ? $order->id : null,
            'amount' => $amount,
            'payment_method' => WholesalePaymentMethod::BankTransfer,
            'bank_name' => 'بنك مصر',
            'reference_number' => 'F03-'.Str::uuid(),
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'receipts/f03.jpg',
            'status' => $status,
        ]);
    }
}
