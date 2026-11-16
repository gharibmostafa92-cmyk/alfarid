<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Orders\ReturnStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\ReturnRequest;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\WholesaleInsights;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WholesaleAccountingRoadmapTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Africa/Cairo', 'app.timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_statement_uses_cairo_date_boundaries_and_date_transfer_fallback(): void
    {
        $customer = $this->wholesaleCustomer();
        $openingOrder = $this->creditOrder($customer, 50, '2026-12-19 21:59:59');
        $firstBoundaryOrder = $this->creditOrder($customer, 100, '2026-12-19 23:00:00');
        $lastBoundaryOrder = $this->creditOrder($customer, 200, '2026-12-20 21:59:59');
        $nextDayOrder = $this->creditOrder($customer, 300, '2026-12-20 22:00:00');

        $approvedAtBoundary = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 25,
            'status' => WholesalePaymentNoticeStatus::Approved,
            'transfer_date' => '2026-12-19',
            'approved_at' => '2026-12-19 23:30:00',
        ]);
        $dateFallback = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 25,
            'status' => WholesalePaymentNoticeStatus::Approved,
            'transfer_date' => '2026-12-20',
            'approved_at' => null,
        ]);
        DB::table('wholesale_payment_notices')->where('id', $dateFallback->id)->update([
            'updated_at' => '2026-12-22 00:00:00',
        ]);
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Honolulu'");

        $statement = app(WholesalePaymentNoticeService::class)->statement($customer, '2026-12-20', '2026-12-20');
        $references = array_column($statement['entries'], 'reference');

        $this->assertSame('50.00', $statement['opening_balance']);
        $this->assertEqualsCanonicalizing(
            [$firstBoundaryOrder->order_number, $lastBoundaryOrder->order_number, $approvedAtBoundary->notice_number, $dateFallback->notice_number],
            $references,
        );
        $this->assertNotContains($openingOrder->order_number, $references);
        $this->assertNotContains($nextDayOrder->order_number, $references);
        $this->assertSame(['2026-12-20'], array_values(array_unique(array_column($statement['entries'], 'date'))));
    }

    public function test_date_only_notice_uses_same_effective_time_for_approved_and_reversed_rows(): void
    {
        $approvedCustomer = $this->wholesaleCustomer();
        $dateOnlyApproved = WholesalePaymentNotice::factory()->create([
            'customer_id' => $approvedCustomer->id,
            'amount' => 100,
            'status' => WholesalePaymentNoticeStatus::Approved,
            'transfer_date' => '2026-11-10',
            'approved_at' => null,
        ]);
        $timestampedApproved = WholesalePaymentNotice::factory()->create([
            'customer_id' => $approvedCustomer->id,
            'amount' => 50,
            'status' => WholesalePaymentNoticeStatus::Approved,
            'transfer_date' => '2026-11-09',
            'approved_at' => '2026-11-09 22:30:00',
        ]);

        $approvedStatement = app(WholesalePaymentNoticeService::class)->statement($approvedCustomer, '2026-11-10', '2026-11-10');
        $this->assertSame(
            [$dateOnlyApproved->notice_number, $timestampedApproved->notice_number],
            array_column($approvedStatement['entries'], 'reference'),
        );
        $this->assertSame(['-100.00', '-150.00'], array_column($approvedStatement['entries'], 'balance'));

        $reversedCustomer = $this->wholesaleCustomer();
        $dateOnlyReversed = WholesalePaymentNotice::factory()->create([
            'customer_id' => $reversedCustomer->id,
            'amount' => 200,
            'status' => WholesalePaymentNoticeStatus::Reversed,
            'transfer_date' => '2026-11-10',
            'approved_at' => null,
            'reversed_at' => '2026-11-09 22:15:00',
        ]);
        $timestampedAfterReversal = WholesalePaymentNotice::factory()->create([
            'customer_id' => $reversedCustomer->id,
            'amount' => 50,
            'status' => WholesalePaymentNoticeStatus::Approved,
            'transfer_date' => '2026-11-09',
            'approved_at' => '2026-11-09 22:30:00',
        ]);

        $reversedStatement = app(WholesalePaymentNoticeService::class)->statement($reversedCustomer, '2026-11-10', '2026-11-10');
        $this->assertSame(
            [$dateOnlyReversed->notice_number, $dateOnlyReversed->notice_number, $timestampedAfterReversal->notice_number],
            array_column($reversedStatement['entries'], 'reference'),
        );
        $this->assertSame(['200.00', '0.00', '-50.00'], array_column($reversedStatement['entries'], 'balance'));
    }

    public function test_database_guard_excludes_non_credit_order_returns_from_credit_coverage(): void
    {
        $customer = $this->wholesaleCustomer();
        $cashOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'status' => OrderStatus::Delivered,
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'order_id' => $cashOrder->id,
            'amount' => 60,
            'status' => WholesalePaymentNoticeStatus::Approved,
        ]);
        ReturnRequest::factory()->create([
            'order_id' => $cashOrder->id,
            'customer_id' => $customer->id,
            'status' => ReturnStatus::Refunded,
            'refund_method' => RefundMethod::CreditNote,
            'total_refund_amount' => 60,
        ]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertFalse((bool) DB::scalar('SELECT wholesale_order_overallocated(?)', [$cashOrder->id]));
    }

    public function test_database_guard_keeps_refunded_credit_notes_in_credit_order_coverage(): void
    {
        $customer = $this->wholesaleCustomer();
        $creditOrder = $this->creditOrder($customer, 100, '2026-11-16 00:00:00');
        WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'order_id' => $creditOrder->id,
            'amount' => 60,
            'status' => WholesalePaymentNoticeStatus::Approved,
        ]);
        ReturnRequest::factory()->create([
            'order_id' => $creditOrder->id,
            'customer_id' => $customer->id,
            'status' => ReturnStatus::Refunded,
            'refund_method' => RefundMethod::CreditNote,
            'total_refund_amount' => 41,
        ]);

        $this->assertTrue((bool) DB::scalar('SELECT wholesale_order_overallocated(?)', [$creditOrder->id]));
    }

    public function test_reversed_notice_statement_query_orders_rows_before_its_limit(): void
    {
        $customer = $this->wholesaleCustomer();
        WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'status' => WholesalePaymentNoticeStatus::Reversed,
            'reversed_at' => now(),
        ]);

        DB::enableQueryLog();
        app(WholesalePaymentNoticeService::class)->statement($customer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $reversedQuery = collect($queries)->first(fn (array $query): bool =>
            str_contains($query['query'], 'from "wholesale_payment_notices"')
            && str_contains($query['query'], 'limit 1501')
            && str_contains($query['query'], 'order by "id" asc')
        );

        $this->assertNotNull($reversedQuery, 'The bounded reversed-notice query must have deterministic ID ordering.');
    }

    public function test_recent_statement_count_excludes_non_credit_credit_note_returns(): void
    {
        $customer = $this->wholesaleCustomer();
        $cashOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'status' => OrderStatus::Delivered,
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        ReturnRequest::factory()->create([
            'order_id' => $cashOrder->id,
            'customer_id' => $customer->id,
            'status' => ReturnStatus::Refunded,
            'refund_method' => RefundMethod::CreditNote,
            'total_refund_amount' => 50,
            'refunded_at' => now(),
        ]);

        $service = app(WholesalePaymentNoticeService::class);

        $this->assertSame([], $service->statement($customer)['entries']);
        $this->assertSame(0, $service->recentStatementCount($customer));
    }

    public function test_credit_suggestions_filter_overdue_orders_and_rank_net_candidates_before_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 00:30:00', 'UTC'));

        $ineligible = [];
        for ($index = 0; $index < 100; $index++) {
            $customer = $this->wholesaleCustomer(1000);
            $order = $this->creditOrder($customer, 1000, '2026-11-15 20:00:00');
            ReturnRequest::factory()->create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'status' => ReturnStatus::Refunded,
                'refund_method' => RefundMethod::CreditNote,
                'total_refund_amount' => 50,
            ]);
            $ineligible[] = $customer->id;
        }

        $todayCustomer = $this->wholesaleCustomer(1000);
        $this->creditOrder($todayCustomer, 910, '2026-11-15 22:15:00');

        DB::enableQueryLog();
        $suggestions = app(WholesaleInsights::class)->creditSuggestions();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $suggestedIds = array_column($suggestions, 'customer_id');
        $this->assertContains($todayCustomer->id, $suggestedIds, 'An order due earlier today in Cairo is not overdue yet.');
        $this->assertSame(91.0, (float) collect($suggestions)->firstWhere('customer_id', $todayCustomer->id)['used_percent']);
        $this->assertSame([], array_values(array_intersect($ineligible, $suggestedIds)));
        $this->assertLessThanOrEqual(10, count($queries), 'Candidate evaluation must remain bounded and query based.');
    }

    public function test_credit_suggestions_ignore_soft_deleted_overdue_credit_orders(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 00:30:00', 'UTC'));
        $customer = $this->wholesaleCustomer(1000);
        $this->creditOrder($customer, 950, '2026-11-20 12:00:00');
        $deletedOrder = $this->creditOrder($customer, 2000, '2026-11-14 12:00:00');
        $deletedOrder->delete();

        $suggestions = app(WholesaleInsights::class)->creditSuggestions();

        $suggestion = collect($suggestions)->firstWhere('customer_id', $customer->id);
        $this->assertNotNull($suggestion, 'A soft-deleted overdue order must not disqualify an otherwise eligible customer.');
        $this->assertSame(95.0, (float) $suggestion['used_percent']);
    }

    public function test_daily_briefing_sums_clamped_net_exposure_per_overdue_order(): void
    {
        $customer = $this->wholesaleCustomer();
        $overcovered = $this->creditOrder($customer, 50, '2026-11-14 12:00:00');
        $uncovered = $this->creditOrder($customer, 40, '2026-11-14 12:00:00');
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 100,
            'status' => WholesalePaymentNoticeStatus::Approved,
        ]);
        WholesalePaymentAllocation::query()->create([
            'notice_id' => $notice->id,
            'order_id' => $overcovered->id,
            'amount' => 100,
        ]);
        $cashCustomer = $this->wholesaleCustomer();
        Order::factory()->create([
            'customer_id' => $cashCustomer->id,
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'subtotal' => 999,
            'grand_total' => 999,
            'due_at' => '2026-11-14 12:00:00',
        ]);
        $deletedCustomer = $this->wholesaleCustomer();
        $deletedOrder = $this->creditOrder($deletedCustomer, 1000, '2026-11-14 12:00:00');
        $deletedOrder->delete();

        $this->artisan('wholesale:daily-briefing')->assertSuccessful();

        $message = OutboundMessage::query()->where('template', 'staff_daily_briefing')->firstOrFail()->payload['message'];
        $this->assertStringContainsString('بإجمالي 40.', (string) $message);
        $this->assertStringContainsString('و1 تاجر متأخرين', (string) $message);
    }

    private function wholesaleCustomer(float $creditLimit = 100000): Customer
    {
        return Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => $creditLimit,
        ]);
    }

    private function creditOrder(Customer $customer, float $amount, string $createdAt): Order
    {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'subtotal' => $amount,
            'grand_total' => $amount,
            'due_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }
}
