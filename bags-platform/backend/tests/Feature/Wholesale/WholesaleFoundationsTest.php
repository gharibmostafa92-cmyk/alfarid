<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Quotation;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Notifications\WholesaleDeskNotification;
use App\Services\Ai\DomainRouter;
use App\Services\Ai\Wholesale\WholesaleToolProvider;
use App\Services\OrderService;
use App\Services\QuotationService;
use App\Services\SettingService;
use App\Services\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WholesaleFoundationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_provider_is_registered_locked_off_and_disabled_by_default(): void
    {
        $provider = app(WholesaleToolProvider::class);

        $this->assertSame('wholesale', $provider->domain());
        $this->assertFalse($provider->usesGlobalAdminLock());
        $this->assertFalse($provider->enabled());
        $this->assertTrue($provider->isToolHidden('wholesale_inbox'));
        $this->assertArrayHasKey('explain_outstanding', $provider->tools());
    }

    public function test_router_sends_receipt_transfers_to_wholesale_and_keeps_stock_transfers(): void
    {
        $router = app(DomainRouter::class);

        $receipt = $router->route('تحويل إيصال البنك بتاع التاجر');
        $this->assertContains('wholesale', $receipt ?? []);
        $this->assertNotContains('inventory', $receipt ?? []);

        $goods = $router->route('حوّل 20 شنطة لفرع المعادي');
        $this->assertContains('inventory', $goods ?? []);
        $this->assertNotContains('wholesale', $goods ?? []);

        $quote = $router->route('سعّر عرض السعر Q-0120');
        $this->assertContains('wholesale', $quote ?? []);
    }

    public function test_hourly_command_expires_priced_quotations_past_valid_until(): void
    {
        Notification::fake();
        $quotation = Quotation::factory()->create([
            'status' => QuotationStatus::PricedByAdmin,
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertSame(QuotationStatus::Expired, $quotation->fresh()->status);
        Notification::assertSentTo($quotation->customer, WholesaleDeskNotification::class);
    }

    public function test_statement_uses_approved_at_after_a_later_note_edit(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 10000]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $bank = Treasury::create([
            'name' => 'بنك', 'code' => 'STMT-BANK', 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
            'created_at' => now()->subDays(3),
        ]);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 400,
            'status' => 'pending_review',
            'transfer_date' => now()->subDays(2)->toDateString(),
        ]);

        $service = app(WholesalePaymentNoticeService::class);
        $service->approve($notice, $admin->id, $bank->id);
        $approvedOn = now()->subDay();
        $notice->forceFill(['approved_at' => $approvedOn])->save();
        $notice->update(['admin_notes' => 'ملاحظة بعد الاعتماد']);

        $credit = collect($service->statement($customer)['entries'])->firstWhere('reference', $notice->notice_number);

        $this->assertSame($approvedOn->toDateString(), $credit['date']);
        $this->assertNotSame($notice->fresh()->updated_at->toDateString(), $credit['date']);
    }

    public function test_fifo_skip_to_fit_settles_a_smaller_later_order(): void
    {
        DB::table('settings')->where('key', 'wholesale_fifo_mode')->update(['value' => 'skip_to_fit']);
        app(SettingService::class)->clearCache();

        [$customer, $admin, $bank, $older, $newer] = $this->twoOpenOrders();
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 30000,
            'status' => 'pending_review',
        ]);

        app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $bank->id);

        $this->assertSame('unpaid', $older->fresh()->payment_status);
        $this->assertSame('paid', $newer->fresh()->payment_status);
    }

    public function test_fifo_partial_allocates_what_the_balance_can_cover(): void
    {
        DB::table('settings')->where('key', 'wholesale_fifo_mode')->update(['value' => 'partial']);
        app(SettingService::class)->clearCache();

        [$customer, $admin, $bank, $older, $newer] = $this->twoOpenOrders();
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 30000,
            'status' => 'pending_review',
        ]);

        app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $bank->id);

        $this->assertSame('unpaid', $older->fresh()->payment_status);
        $this->assertSame('unpaid', $newer->fresh()->payment_status);
        $this->assertDatabaseHas('wholesale_payment_allocations', [
            'notice_id' => $notice->id,
            'order_id' => $older->id,
            'amount' => '30000.00',
        ]);
    }

    public function test_fifo_strict_stops_before_the_smaller_order(): void
    {
        [$customer, $admin, $bank, $older, $newer] = $this->twoOpenOrders();
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 30000,
            'status' => 'pending_review',
        ]);

        app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $bank->id);

        $this->assertSame('unpaid', $older->fresh()->payment_status);
        $this->assertSame('unpaid', $newer->fresh()->payment_status);
        $this->assertDatabaseMissing('wholesale_payment_allocations', ['notice_id' => $notice->id]);
    }

    public function test_same_receipt_is_rejected_across_customers(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $bytes = 'same-receipt-bytes';
        $service = app(WholesalePaymentNoticeService::class);
        $service->create($first, $this->noticePayload('REF-A'), UploadedFile::fake()->createWithContent('a.jpg', $bytes));

        try {
            $service->create($second, $this->noticePayload('REF-B'), UploadedFile::fake()->createWithContent('b.jpg', $bytes));
            $this->fail('Duplicate receipt was accepted.');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
            $this->assertStringContainsString('نفس الإيصال', $e->getMessage());
        }
    }

    public function test_confirming_a_credit_order_sets_due_at_from_payment_terms(): void
    {
        $customer = Customer::factory()->create(['payment_terms_days' => 10]);
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'allocated_branch_id' => $branch->id,
        ]);

        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, User::factory()->create());

        $this->assertSame(now()->addDays(10)->toDateString(), $order->fresh()->due_at?->toDateString());
    }

    public function test_pricing_warns_before_the_trader_accepts(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => 100]);
        $quotation = Quotation::factory()->create([
            'customer_id' => $customer->id,
            'status' => QuotationStatus::PricedByAdmin,
            'grand_total' => 500,
        ]);

        $warnings = app(QuotationService::class)->acceptanceWarnings($quotation);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('حد الائتمان', $warnings[0]);
    }

    public function test_preview_allocation_does_not_write(): void
    {
        [$customer, $admin, $bank, $older, $newer] = $this->twoOpenOrders();
        unset($admin, $bank);
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 5000,
            'status' => 'pending_review',
        ]);

        $preview = app(WholesalePaymentNoticeService::class)->previewNoticeAllocation($notice);

        $this->assertSame('pending_review', $notice->fresh()->status->value);
        $this->assertSame('unpaid', $older->fresh()->payment_status);
        $this->assertTrue(collect($preview['fifo'])->contains(fn (array $line) => $line['order_id'] === $newer->id && $line['closes'] === false && $line['skipped'] === true || $line['order_id'] === $older->id));
        $this->assertDatabaseCount('wholesale_payment_allocations', 0);
    }

    public function test_overdue_command_is_idempotent(): void
    {
        $customer = Customer::factory()->create(['whatsapp_opt_in_at' => now()]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'due_at' => now()->subDays(2),
            'grand_total' => 800,
        ]);

        $this->artisan('wholesale:mark-overdue')->assertSuccessful();
        $this->artisan('wholesale:mark-overdue')->assertSuccessful();

        $this->assertSame(1, OutboundMessage::query()->where('template', 'order_overdue_reminder')->count());
    }

    /** @return array{0: Customer, 1: User, 2: Treasury, 3: Order, 4: Order} */
    private function twoOpenOrders(): array
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $bank = Treasury::create([
            'name' => 'بنك', 'code' => 'FIFO-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $older = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 50000,
            'subtotal' => 50000,
        ]);
        $newer = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 5000,
            'subtotal' => 5000,
        ]);

        return [$customer, $admin, $bank, $older, $newer];
    }

    /** @return array<string, mixed> */
    private function noticePayload(string $reference): array
    {
        return [
            'amount' => 100,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك مصر',
            'reference_number' => $reference,
            'transfer_date' => now()->toDateString(),
        ];
    }
}
