<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Jobs\ScoreWholesaleReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\GeminiClient;
use App\Services\Shared\SettingService;
use App\Services\Shared\SpokenQuantity;
use App\Services\Wholesale\BankStatementMatcher;
use App\Services\Wholesale\ReceiptMatchScorer;
use App\Services\Wholesale\WholesaleOutbox;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class Task3Slice4ReconciliationTest extends TestCase
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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->bank = Treasury::create([
            'name' => 'بنك الحركات',
            'code' => 'BNK-'.uniqid(),
            'type' => TreasuryType::BankAccount,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
    }

    public function test_high04_statement_includes_reversed_notices_as_dual_entries(): void
    {
        $service = app(WholesalePaymentNoticeService::class);

        // Order 1: 5000 EGP on Nov 1
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '5000.00',
            'status' => OrderStatus::Delivered,
            'created_at' => Carbon::parse('2026-11-01 10:00:00', 'Africa/Cairo')->utc(),
        ]);

        // Notice 1: 5000 EGP approved on Nov 2
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'order_id' => $order->id,
            'amount' => '5000.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'created_at' => Carbon::parse('2026-11-02 09:00:00', 'Africa/Cairo')->utc(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'Africa/Cairo'));
        $service->approve($notice, $this->admin->id, $this->bank->id);

        // Notice 1 reversed on Nov 5
        Carbon::setTestNow(Carbon::parse('2026-11-05 14:00:00', 'Africa/Cairo'));
        $service->reverse($notice, $this->admin->id, 'شيك بدون رصيد');

        Carbon::setTestNow(null);

        // Query statement for Nov 1 to Nov 7
        $statement = $service->statement($this->customer, '2026-11-01', '2026-11-07');

        $this->assertSame('0.00', $statement['opening_balance']);
        $this->assertSame('5000.00', $statement['closing_balance']);

        // Check entries: Debit order, Credit approval, Debit reversal
        $entries = $statement['entries'];
        $this->assertCount(3, $entries);

        $this->assertSame('debit', $entries[0]['type']);
        $this->assertSame('5000.00', $entries[0]['debit']);

        $this->assertSame('credit', $entries[1]['type']);
        $this->assertSame('5000.00', $entries[1]['credit']);

        $this->assertSame('debit', $entries[2]['type']);
        $this->assertSame('5000.00', $entries[2]['debit']);
        $this->assertStringContainsString('عكس', $entries[2]['description']);
    }

    public function test_high08_score_wholesale_receipt_unpacks_gemini_data_structure(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'ai_wholesale_receipt_vision_enabled'],
            ['value' => '1', 'group' => 'ai', 'type' => 'boolean']
        );
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key']);

        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => '1500.00',
            'reference_number' => 'REF-998877',
            'bank_name' => 'CIB',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'receipt_file_path' => "wholesale_payment_notices/{$this->customer->id}/receipt.png",
        ]);

        Storage::disk('r2')->put($notice->receipt_file_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true));

        $gemini = Mockery::mock(GeminiClient::class);
        // GeminiClient::generateStructured returns wrapped structure: ['data' => [...], 'model' => 'gemini-1.5-flash']
        $gemini->shouldReceive('generateStructured')->once()->andReturn([
            'data' => [
                'amount' => '1500.00',
                'reference' => 'REF-998877',
                'date' => now()->toDateString(),
                'bank' => 'CIB',
                'sender' => 'VARYA Trader',
            ],
            'model' => 'gemini-1.5-flash',
            'usage' => ['total_tokens' => 120],
        ]);
        app()->instance(GeminiClient::class, $gemini);

        $job = new ScoreWholesaleReceipt($notice->id);
        $job->handle(app(SettingService::class), $gemini, app(ReceiptMatchScorer::class));

        $notice->refresh();
        $this->assertNotNull($notice->match_score);
        $this->assertEquals(100.0, (float) $notice->match_score);
        $this->assertIsArray($notice->ocr_payload);
        $this->assertSame('1500.00', $notice->ocr_payload['amount']);
    }

    public function test_medium03_payment_reminders_filters_weekly_cap_in_sql(): void
    {
        Queue::fake([\App\Jobs\SendOutboundMessage::class]);

        // Customer 1 reached cap of 3 messages
        $cust1 = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'whatsapp_opt_in_at' => now(),
        ]);
        for ($i = 0; $i < 3; $i++) {
            OutboundMessage::create([
                'idempotency_key' => 'reminder-test-'.$i,
                'channel' => 'customer_whatsapp',
                'customer_id' => $cust1->id,
                'template' => 'payment_reminder',
                'payload' => ['order' => 'ORD-1'],
                'status' => 'sent',
                'created_at' => now()->subDays(2),
            ]);
        }

        Order::factory()->create([
            'customer_id' => $cust1->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '1000.00',
            'due_at' => now()->addDay(),
        ]);

        // Customer 2 has not reached cap
        $cust2 = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'whatsapp_opt_in_at' => now(),
        ]);
        $order2 = Order::factory()->create([
            'customer_id' => $cust2->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '2000.00',
            'due_at' => now()->addDay(),
        ]);

        Artisan::call('wholesale:payment-reminders');

        // Customer 1 still has only the original 3 messages
        $this->assertSame(3, OutboundMessage::query()->where('customer_id', $cust1->id)->count());

        // Customer 2 received 1 reminder message
        $this->assertSame(1, OutboundMessage::query()->where('customer_id', $cust2->id)->count());
        $msg = OutboundMessage::query()->where('customer_id', $cust2->id)->first();
        $this->assertSame('payment_reminder', $msg->template);
        $this->assertSame('pending', $msg->status);
    }

    public function test_medium04_bank_statement_matcher_sanitizes_commas_and_normalizes_references(): void
    {
        $matcher = app(BankStatementMatcher::class);

        // Pending notice for 12,500.00 EGP with normalized reference REF12345
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => '12500.00',
            'reference_number' => 'REF-12345',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        // Bank row with comma in amount, Arabic numerals in reference: "REF-١٢٣٤٥"
        $rows = [
            [
                'amount' => '12,500.00',
                'reference' => 'REF-١٢٣٤٥',
                'date' => now()->toDateString(),
                'description' => 'تحويل وارد',
            ],
        ];

        $matched = $matcher->match($rows);

        $this->assertCount(1, $matched['exact']);
        $this->assertSame($notice->id, $matched['exact'][0]['notice_id']);
    }

    public function test_medium04_bank_statement_matcher_rejects_conflicting_references_and_short_names(): void
    {
        $matcher = app(BankStatementMatcher::class);

        // Customer with short name "مصر"
        $shortCustomer = Customer::factory()->create([
            'name' => 'مصر',
            'company_name' => 'مصر',
            'type' => 'wholesale',
            'status' => 'approved',
        ]);

        // Pending notice with explicit reference A-111
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => '5000.00',
            'reference_number' => 'REF-A-111',
            'transfer_date' => now()->toDateString(),
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        // Row has same amount and date, but CONFLICTING reference REF-B-999
        $rows = [
            [
                'amount' => '5000.00',
                'reference' => 'REF-B-999',
                'date' => now()->toDateString(),
                'description' => 'تحويل عبر بنك مصر وارد',
            ],
        ];

        $matched = $matcher->match($rows);

        // Must NOT be matched as probable because references conflict!
        $this->assertCount(0, $matched['probable'], 'Conflicting references must not be matched as probable');
        $this->assertCount(1, $matched['unmatched']);

        // Must NOT suggest short customer "مصر" from description "بنك مصر"
        $this->assertNull($matched['unmatched'][0]['suggestion'], 'Short customer names (< 4 chars) must not match common bank words');
    }

    public function test_medium07_spoken_quantity_supports_compound_hundreds_and_duals(): void
    {
        // Compound hundreds
        $this->assertSame(300.0, SpokenQuantity::parse('تلتميه'));
        $this->assertSame(300.0, SpokenQuantity::parse('تلاتميه'));
        $this->assertSame(300.0, SpokenQuantity::parse('ثلاثمائه'));
        $this->assertSame(450.0, SpokenQuantity::parse('اربعميه وخمسين'));
        $this->assertSame(500000.0, SpokenQuantity::parse('خمسميه الف'));
        $this->assertSame(700.0, SpokenQuantity::parse('سبعميه'));
        $this->assertSame(900.0, SpokenQuantity::parse('تسعميه'));

        // Dual forms
        $this->assertSame(24.0, SpokenQuantity::parse('دستتين'));
        $this->assertSame(2000.0, SpokenQuantity::parse('الفين'));
        $this->assertSame(2300.0, SpokenQuantity::parse('الفين وتلتميه'));
    }

    public function test_medium04_bank_statement_matcher_requires_word_boundaries_for_customer_suggestions(): void
    {
        $matcher = app(BankStatementMatcher::class);

        $subCustomer = Customer::factory()->create([
            'name' => 'امير',
            'company_name' => 'امير',
            'type' => 'wholesale',
            'status' => 'approved',
        ]);

        $rows = [
            [
                'amount' => '1234.56',
                'reference' => 'UNMATCHED-WB-1',
                'date' => now()->toDateString(),
                'description' => 'تحويل من اميرة محمد',
            ],
            [
                'amount' => '1234.57',
                'reference' => 'UNMATCHED-WB-2',
                'date' => now()->toDateString(),
                'description' => 'تحويل من امير للتجارة',
            ],
        ];

        $matched = $matcher->match($rows);

        $this->assertCount(2, $matched['unmatched']);
        $this->assertNull($matched['unmatched'][0]['suggestion'], 'Partial word match "امير" inside "اميرة" must not suggest customer');
        $this->assertSame($subCustomer->id, $matched['unmatched'][1]['suggestion']['customer_id']);
    }
}
