<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Enums\WholesalePaymentMethod;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Events\Wholesale\PaymentNoticeSubmitted;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Pos\TreasuryService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * task.txt Section 3: إشعارات السداد (Payment Notices) والخزينة
 */
class TaskTxtPaymentNoticeSection3Test extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Customer $trader;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->branch = Branch::create([
            'name' => 'فرع السداد',
            'code' => 'BR-PAY3',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->trader = Customer::factory()->create([
            'name' => 'تاجر إشعارات السداد',
            'phone' => '01098887766',
            'email' => 'notice-sec3@example.com',
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => '100000.00',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_trader_payment_notice_validation_and_linked_order_constraints(): void
    {
        Storage::fake('r2');
        $token = $this->trader->createToken('portal')->plainTextToken;

        $validPdf = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        // 1. amount = 0 -> 422
        $this->withToken($token)->postJson('/api/v1/wholesale/payment-notices', [
            'amount' => 0,
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-ZERO',
            'transfer_date' => now()->toDateString(),
            'receipt' => $validPdf,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // 2. amount with 3 decimals (100.123) -> 422
        $this->withToken($token)->postJson('/api/v1/wholesale/payment-notices', [
            'amount' => '100.123',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-3DEC',
            'transfer_date' => now()->toDateString(),
            'receipt' => $validPdf,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // 3. transfer_date tomorrow -> 422
        $this->withToken($token)->postJson('/api/v1/wholesale/payment-notices', [
            'amount' => '250.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-TOMORROW',
            'transfer_date' => now()->addDay()->toDateString(),
            'receipt' => $validPdf,
        ])->assertStatus(422)->assertJsonValidationErrors(['transfer_date']);

        // 4. receipt > 10MB -> 422
        $hugeFile = UploadedFile::fake()->create('huge.pdf', 10500, 'application/pdf');
        $this->withToken($token)->postJson('/api/v1/wholesale/payment-notices', [
            'amount' => '250.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-HUGE',
            'transfer_date' => now()->toDateString(),
            'receipt' => $hugeFile,
        ])->assertStatus(422)->assertJsonValidationErrors(['receipt']);

        // 5. receipt .gif -> 422
        $gifFile = UploadedFile::fake()->image('anim.gif');
        $this->withToken($token)->postJson('/api/v1/wholesale/payment-notices', [
            'amount' => '250.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-GIF',
            'transfer_date' => now()->toDateString(),
            'receipt' => $gifFile,
        ])->assertStatus(422)->assertJsonValidationErrors(['receipt']);

        // 6. order_id for already paid, cancelled, or non-credit (cod) order -> rejected
        $paidOrder = $this->createOrder('ORD-PAID-1', '1000.00', 'credit', 'paid', OrderStatus::Confirmed);
        $cancelledOrder = $this->createOrder('ORD-CANC-1', '1000.00', 'credit', 'unpaid', OrderStatus::Cancelled);
        $codOrder = $this->createOrder('ORD-COD-1', '1000.00', 'cod', 'unpaid', OrderStatus::Confirmed);

        foreach ([$paidOrder, $cancelledOrder, $codOrder] as $idx => $invalidOrder) {
            $this->withToken($token)->postJson('/api/v1/wholesale/payment-notices', [
                'order_id' => $invalidOrder->id,
                'amount' => '500.00',
                'payment_method' => WholesalePaymentMethod::Deposit->value,
                'bank_name' => 'IPN',
                'reference_number' => "REF-INV-{$idx}",
                'transfer_date' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->createWithContent("r-{$idx}.pdf", "%PDF-1.4 unique-{$idx}"),
            ])->assertStatus(422);
        }
    }

    public function test_payment_notice_submitted_dispatched_and_r2_file_cleaned_up_on_db_failure(): void
    {
        Storage::fake('r2');
        Event::fake([PaymentNoticeSubmitted::class]);

        $this->createOrder('ORD-OPEN-1', '2000.00', 'credit', 'unpaid', OrderStatus::Confirmed);

        $service = app(WholesalePaymentNoticeService::class);
        $receipt = UploadedFile::fake()->createWithContent('ok.pdf', '%PDF-1.4 success-receipt');

        $notice = $service->create($this->trader, [
            'amount' => '600.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'QNB',
            'reference_number' => 'REF-OK-1',
            'transfer_date' => now()->toDateString(),
        ], $receipt);

        Event::assertDispatched(PaymentNoticeSubmitted::class, fn (PaymentNoticeSubmitted $e) => $e->notice->id === $notice->id);
        Storage::disk('r2')->assertExists($notice->receipt_file_path);

        // Now force WholesalePaymentNotice::creating to throw after PrivateAttachments pre-uploaded the file
        $existingFiles = Storage::disk('r2')->allFiles();
        WholesalePaymentNotice::creating(static function (): void {
            throw new RuntimeException('Simulated DB failure during notice creation');
        });

        try {
            $failingReceipt = UploadedFile::fake()->createWithContent('fail.pdf', '%PDF-1.4 rollback-receipt');
            try {
                $service->create($this->trader, [
                    'amount' => '400.00',
                    'payment_method' => WholesalePaymentMethod::BankTransfer->value,
                    'bank_name' => 'QNB',
                    'reference_number' => 'REF-FAIL-1',
                    'transfer_date' => now()->toDateString(),
                ], $failingReceipt);
                $this->fail('Expected RuntimeException was not thrown.');
            } catch (RuntimeException $e) {
                $this->assertSame('Simulated DB failure during notice creation', $e->getMessage());
            }

            // R2 must have cleaned up the pre-uploaded file
            $this->assertSame($existingFiles, Storage::disk('r2')->allFiles());
        } finally {
            WholesalePaymentNotice::flushEventListeners();
             WholesalePaymentNotice::boot();
        }
    }

    public function test_admin_review_rules_treasury_resolution_rejection_and_cancelled_linked_order_turns_into_free_credit(): void
    {
        Storage::fake('r2');
        $service = app(WholesalePaymentNoticeService::class);

        $order1 = $this->createOrder('ORD-ADM-1', '1000.00', 'credit', 'unpaid', OrderStatus::Confirmed);
        $order2 = $this->createOrder('ORD-ADM-2', '1000.00', 'credit', 'unpaid', OrderStatus::Confirmed);

        $notice1 = $service->create($this->trader, [
            'order_id' => $order1->id,
            'amount' => '1000.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-ADM-1',
            'transfer_date' => now()->toDateString(),
        ], UploadedFile::fake()->createWithContent('a1.pdf', '%PDF-1.4 adm-1'));

        // If order1 becomes paid before notice1 is approved -> approving notice1 fails
        $order1->update(['payment_status' => 'paid']);
        try {
            $service->approve($notice1, $this->admin->id);
            $this->fail('Expected BusinessException when linked order is already paid.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('تم سداده بالفعل', $e->getMessage());
        }
        $order1->update(['payment_status' => 'unpaid']);

        // Treasury validation: inactive treasury or PosDrawer treasury rejected
        Treasury::query()->update(['is_active' => false]);
        $inactiveBank = Treasury::create([
            'name' => 'بنك معطل',
            'code' => 'TR-INACT-BNK',
            'type' => TreasuryType::BankAccount,
            'branch_id' => $this->branch->id,
            'is_active' => false,
            'current_balance' => '0.00',
        ]);
        $posDrawer = Treasury::create([
            'name' => 'درج كاشير',
            'code' => 'TR-POS-1',
            'type' => TreasuryType::PosDrawer,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);

        try {
            $service->approve($notice1, $this->admin->id, $inactiveBank->id);
            $this->fail('Expected BusinessException for inactive treasury.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('غير نشطة', $e->getMessage());
        }

        try {
            $service->approve($notice1, $this->admin->id, $posDrawer->id);
            $this->fail('Expected BusinessException for POS drawer treasury.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('البنكية أو الرئيسية فقط', $e->getMessage());
        }

        // resolveTreasury(null): when no active BankAccount or MainSafe exists -> fails
        try {
            $service->approve($notice1, $this->admin->id, null);
            $this->fail('Expected BusinessException when no active bank or main safe exists.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('لا توجد خزينة بنكية أو رئيسية نشطة', $e->getMessage());
        }

        // Create MainSafe first, then BankAccount: resolveTreasury(null) picks BankAccount first, and falls back to MainSafe if BankAccount is deactivated
        $mainSafe = Treasury::create([
            'name' => 'الخزينة الرئيسية',
            'code' => 'TR-MAIN-S3',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);
        $bankAccount = Treasury::create([
            'name' => 'الحساب البنكي',
            'code' => 'TR-BANK-S3',
            'type' => TreasuryType::BankAccount,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);

        $approved1 = $service->approve($notice1, $this->admin->id, null);
        $this->assertSame($bankAccount->id, $approved1->treasury_id);

        // Cannot reject an already approved notice
        try {
            $service->reject($approved1, $this->admin->id, 'محاولة رفض بعد الاعتماد');
            $this->fail('Expected BusinessException when rejecting approved notice.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('تمت مراجعته من قبل', $e->getMessage());
        }

        // Rejecting a pending notice creates 0 treasury transactions
        $noticeToReject = $service->create($this->trader, [
            'amount' => '300.00',
            'payment_method' => WholesalePaymentMethod::Check->value,
            'bank_name' => 'Vodafone',
            'reference_number' => 'REF-REJ-1',
            'transfer_date' => now()->toDateString(),
        ], UploadedFile::fake()->createWithContent('rej.pdf', '%PDF-1.4 rej-1'));

        $txCountBefore = TreasuryTransaction::count();
        $rejected = $service->reject($noticeToReject, $this->admin->id, 'إيصال غير واضح');
        $this->assertSame(WholesalePaymentNoticeStatus::Rejected, $rejected->status);
        $this->assertSame($txCountBefore, TreasuryTransaction::count());

        // If order1 (which had approved notice1 of 1000.00) is later cancelled, notice1 turns into free pool credit!
        $order1->update(['status' => OrderStatus::Cancelled]);
        $breakdown = $service->balanceBreakdown($this->trader);
        $this->assertSame('1000.00', $breakdown['free_pool']);
        $this->assertSame('1000.00', $breakdown['floating_credit']);
        // order2 is 1000.00 unpaid debt, minus 1000.00 floating credit = 0.00 outstanding
        $this->assertSame('0.00', $breakdown['outstanding']);
    }

    public function test_treasury_invariants_and_random_operations_property_test(): void
    {
        Storage::fake('r2');
        $treasuryService = app(TreasuryService::class);
        $noticeService = app(WholesalePaymentNoticeService::class);

        $mainSafe = Treasury::create([
            'name' => 'خزينة رئيسية اختبارية',
            'code' => 'TR-INV-SAFE',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);
        $posDrawer = Treasury::create([
            'name' => 'درج كاشير اختباري',
            'code' => 'TR-INV-POS',
            'type' => TreasuryType::PosDrawer,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);
        $bankAccount = Treasury::create([
            'name' => 'بنك اختباري',
            'code' => 'TR-INV-BANK',
            'type' => TreasuryType::BankAccount,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);

        // 1. MainSafe and PosDrawer cannot go negative, while BankAccount can
        foreach ([$mainSafe, $posDrawer] as $physicalTreasury) {
            try {
                $treasuryService->record([
                    'treasury_id' => $physicalTreasury->id,
                    'type' => TreasuryTransactionType::Expense,
                    'amount' => '-50.00',
                    'created_by' => $this->admin->id,
                    'notes' => 'سحب بالسالب',
                ]);
                $this->fail("Expected BusinessException when {$physicalTreasury->type->value} goes negative.");
            } catch (BusinessException $e) {
                $this->assertStringContainsString('رصيد الخزينة غير كافٍ', $e->getMessage());
            }
        }

        $overdraftTx = $treasuryService->record([
            'treasury_id' => $bankAccount->id,
            'type' => TreasuryTransactionType::Expense,
            'amount' => '-200.00',
            'created_by' => $this->admin->id,
            'notes' => 'سحب على المكشوف من البنك',
        ]);
        $this->assertSame('-200.00', $overdraftTx->balance_after);

        // Reset bank balance back to 0.00 via deposit
        $treasuryService->record([
            'treasury_id' => $bankAccount->id,
            'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '200.00',
            'created_by' => $this->admin->id,
        ]);

        // 2. Inactive treasury rejects normal receipts, but accepts CustomerReceiptReversal
        $this->createOrder('ORD-PROP-BIG', '50000.00', 'credit', 'unpaid', OrderStatus::Confirmed);

        $approvedOnMain = [];
        for ($i = 1; $i <= 6; $i++) {
            $amt = (string) ($i * 150.25);
            $notice = $noticeService->create($this->trader, [
                'amount' => $amt,
                'payment_method' => WholesalePaymentMethod::BankTransfer->value,
                'bank_name' => 'Misr',
                'reference_number' => "REF-PROP-{$i}",
                'transfer_date' => now()->toDateString(),
            ], UploadedFile::fake()->createWithContent("p{$i}.pdf", "%PDF-1.4 prop-{$i}"));

            $approvedOnMain[] = $noticeService->approve($notice, $this->admin->id, $mainSafe->id);
        }

        // Reverse notices 2 and 5 while active
        $noticeService->reverse($approvedOnMain[1], $this->admin->id, 'عكس رقم 2');
        $noticeService->reverse($approvedOnMain[4], $this->admin->id, 'عكس رقم 5');

        // Deactivate mainSafe: normal deposit fails, but reversing notice 3 succeeds!
        $mainSafe->update(['is_active' => false]);
        try {
            $treasuryService->record([
                'treasury_id' => $mainSafe->id,
                'type' => TreasuryTransactionType::CustomerReceipt,
                'amount' => '100.00',
                'created_by' => $this->admin->id,
            ]);
            $this->fail('Expected BusinessException when recording normal receipt on inactive treasury.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('غير نشطة', $e->getMessage());
        }

        $reversedOnInactive = $noticeService->reverse($approvedOnMain[2], $this->admin->id, 'عكس على خزينة معطلة');
        $this->assertSame(WholesalePaymentNoticeStatus::Reversed, $reversedOnInactive->status);

        // Property invariant: sum(treasury_transactions.amount) == treasury.current_balance
        foreach ([$mainSafe, $bankAccount, $posDrawer] as $t) {
            $t->refresh();
            $sumTx = (string) TreasuryTransaction::query()
                ->where('treasury_id', $t->id)
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->value('total');
            $this->assertSame(
                number_format((float) $sumTx, 2, '.', ''),
                number_format((float) $t->current_balance, 2, '.', ''),
                "Treasury {$t->code} current_balance must equal sum of its transactions."
            );
        }
    }

    private function createOrder(
        string $orderNumber,
        string $grandTotal,
        string $paymentMethod,
        string $paymentStatus,
        OrderStatus $status,
    ): Order {
        return Order::create([
            'order_number' => $orderNumber,
            'customer_id' => $this->trader->id,
            'channel' => 'wholesale',
            'recipient_name' => $this->trader->name,
            'recipient_phone' => $this->trader->phone,
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address_line' => 'شارع عباس العقاد',
            'subtotal' => $grandTotal,
            'discount_amount' => '0.00',
            'shipping_fee' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => $grandTotal,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'status' => $status,
            'allocated_branch_id' => $this->branch->id,
        ]);
    }
}
