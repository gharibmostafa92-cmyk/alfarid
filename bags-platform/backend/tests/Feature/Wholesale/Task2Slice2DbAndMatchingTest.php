<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\SalesChannel;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\BankStatementMatcher;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task2Slice2DbAndMatchingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_lock_wholesale_allocation_parents_orders_notices_deterministically_on_reopen(): void
    {
        $fnDef = DB::selectOne("SELECT prosrc AS def FROM pg_proc WHERE proname = 'lock_wholesale_allocation_parents'");
        $this->assertNotNull($fnDef);
        $this->assertStringContainsString('ORDER BY n.id', $fnDef->def);
        $this->assertStringContainsString('FOR NO KEY UPDATE', $fnDef->def);
    }

    public function test_wholesale_order_overallocated_ignores_credit_notes_on_non_credit_orders(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);

        $cashOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::Wholesale->value,
            'payment_method' => 'cash',
            'status' => OrderStatus::Delivered,
            'grand_total' => 100.00,
        ]);

        ReturnRequest::create([
            'order_id' => $cashOrder->id,
            'customer_id' => $customer->id,
            'return_number' => 'RET-900001',
            'status' => 'refunded',
            'refund_method' => 'credit_note',
            'total_refund_amount' => 150.00,
            'reason' => 'test',
        ]);

        $over = DB::selectOne('SELECT wholesale_order_overallocated(?) AS over', [$cashOrder->id]);
        $this->assertFalse((bool) $over->over);
    }

    public function test_bank_statement_matcher_and_sql_normalize_persian_digits_and_arabic_bank_aliases(): void
    {
        $this->assertSame('1234.50', BankStatementMatcher::cleanAmount('۱۲۳۴.۵۰'));
        $this->assertSame('ref123ا', BankStatementMatcher::cleanReference('REF-۱۲۳ٱ'));

        $this->assertSame('alexbank', BankStatementMatcher::bankIdentity('بنك الإسكندرية'));
        $this->assertSame('qnb', BankStatementMatcher::bankIdentity('بنك قطر الوطني'));
        $this->assertSame('aaib', BankStatementMatcher::bankIdentity('البنك العربي الأفريقي'));

        $sqlRef = DB::selectOne("SELECT wholesale_reference_identity('REF-۱۲۳ٱ') AS r");
        $this->assertSame('ref123ا', $sqlRef->r);

        $sqlAlex = DB::selectOne("SELECT wholesale_bank_identity('بنك الإسكندرية') AS b");
        $this->assertSame('alexbank', $sqlAlex->b);

        $sqlQnb = DB::selectOne("SELECT wholesale_bank_identity('بنك قطر الوطني') AS b");
        $this->assertSame('qnb', $sqlQnb->b);

        $sqlAaib = DB::selectOne("SELECT wholesale_bank_identity('البنك العربي الأفريقي') AS b");
        $this->assertSame('aaib', $sqlAaib->b);
    }

    public function test_receipt_collisions_flags_same_reference_across_customers_even_if_amount_or_date_differs(): void
    {
        $c1 = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $c2 = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);

        WholesalePaymentNotice::create([
            'customer_id' => $c1->id,
            'notice_number' => 'NOT-COL-1',
            'amount' => 1000.00,
            'payment_method' => 'bank_transfer',
            'transfer_date' => '2026-11-10',
            'bank_name' => 'CIB',
            'reference_number' => 'TXN-887766',
            'receipt_file_path' => 'receipts/col1.pdf',
            'status' => WholesalePaymentNoticeStatus::Approved,
        ]);

        $notice2 = WholesalePaymentNotice::create([
            'customer_id' => $c2->id,
            'notice_number' => 'NOT-COL-2',
            'amount' => 999.99,
            'payment_method' => 'bank_transfer',
            'transfer_date' => '2026-11-11',
            'bank_name' => 'البنك التجاري فرع مدينة نصر',
            'reference_number' => 'TXN 887766',
            'receipt_file_path' => 'receipts/col2.pdf',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $collisions = app(WholesalePaymentNoticeService::class)->receiptCollisions($notice2);
        $this->assertNotEmpty($collisions, 'Cross-customer reference collision must be flagged even when amount/date differ.');
        $this->assertSame('NOT-COL-1', $collisions[0]['notice_number']);
    }
}
