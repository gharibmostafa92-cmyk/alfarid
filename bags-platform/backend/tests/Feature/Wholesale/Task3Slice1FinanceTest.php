<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Finance\TreasuryType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\RefundMethod;
use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Orders\ReturnService;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class Task3Slice1FinanceTest extends TestCase
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

        $this->customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->bank = Treasury::create([
            'name' => 'بنك الحركات',
            'code' => 'BNK-'.uniqid(),
            'type' => TreasuryType::BankAccount,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    public function test_credit_note_on_paid_order_reflects_in_balance_breakdown_and_statement(): void
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
        ]);

        $noticeService = app(WholesalePaymentNoticeService::class);

        // Pay the order in full via linked notice
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'order_id' => $order->id,
            'amount' => 1000,
            'status' => 'pending_review',
        ]);
        $noticeService->approve($notice, $this->admin->id, $this->bank->id);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('0.00', $noticeService->balanceBreakdown($this->customer)['outstanding']);

        // Issue a 300 EGP credit note on the paid order
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'status' => 'received',
            'total_refund_amount' => 300,
        ]);

        app(ReturnService::class)->refund($return, $this->admin, [
            'refund_method' => RefundMethod::CreditNote->value,
        ]);

        $breakdown = $noticeService->balanceBreakdown($this->customer);
        $statement = $noticeService->statement($this->customer);

        // Outstanding debt must be -300.00 (advance/credit balance owed to trader)
        $this->assertSame('-300.00', $breakdown['outstanding'], 'Outstanding breakdown must match -300.00 credit');
        $this->assertSame('-300.00', $statement['closing_balance'], 'Statement closing must match -300.00 credit');
        $this->assertSame($statement['closing_balance'], $breakdown['outstanding']);
    }

    public function test_credit_note_on_paid_order_without_payment_notices_reflects_in_balance_breakdown(): void
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'paid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
        ]);

        ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'status' => 'refunded',
            'refund_method' => RefundMethod::CreditNote,
            'refunded_at' => now(),
            'total_refund_amount' => 300,
        ]);

        $noticeService = app(WholesalePaymentNoticeService::class);
        $breakdown = $noticeService->balanceBreakdown($this->customer);

        // Before fix, $creditNotes only counts unpaid orders, so debt = 0, floating = 0 => outstanding = 0.00, losing the 300 credit note!
        $this->assertSame('700.00', $breakdown['outstanding'], 'Outstanding must reflect the 300 credit note reduction against total credit orders (1000 - 300 = 700)');
    }

    public function test_release_excess_allocations_with_already_refunded_return_does_not_double_count(): void
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'paid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
        ]);

        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'order_id' => $order->id,
            'amount' => 1000,
            'status' => 'approved',
        ]);

        // Return request is already saved as refunded
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'status' => 'refunded',
            'refund_method' => RefundMethod::CreditNote,
            'refunded_at' => now(),
            'total_refund_amount' => 600,
        ]);

        $noticeService = app(WholesalePaymentNoticeService::class);

        // When excludeReturnRequestId is passed, it does not double count (600 + 600 > 1000)
        $released = $noticeService->releaseExcessAllocationsForCreditNote(
            $order,
            '600.00',
            $this->admin->id,
            $return->id
        );

        $this->assertSame('600.00', $released);
    }

    public function test_quotation_accept_rejects_unapproved_customer(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => 200,
            'is_active' => true,
        ]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
        ]);

        $quotation = Quotation::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => QuotationStatus::PricedByAdmin,
            'grand_total' => 400,
            'subtotal' => 400,
            'priced_by' => $this->admin->id,
        ]);
        $quotation->items()->create([
            'product_variant_id' => $variant->id,
            'qty' => 2,
            'requested_target_price' => 200,
            'offered_unit_price' => 200,
            'line_total' => 400,
        ]);

        // Customer account is suspended / unapproved
        $this->customer->update(['status' => 'suspended']);

        $service = app(QuotationService::class);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('حساب التاجر غير معتمد حاليًا لإتمام الطلب.');

        $service->accept($quotation);
    }

    public function test_quotation_accept_rejects_customer_without_shipping_address(): void
    {
        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'retail_price' => 200,
            'is_active' => true,
        ]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
        ]);

        $quotation = Quotation::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => QuotationStatus::PricedByAdmin,
            'grand_total' => 400,
            'subtotal' => 400,
            'priced_by' => $this->admin->id,
        ]);
        $quotation->items()->create([
            'product_variant_id' => $variant->id,
            'qty' => 2,
            'requested_target_price' => 200,
            'offered_unit_price' => 200,
            'line_total' => 400,
        ]);

        // Customer has no address
        $this->customer->addresses()->delete();

        $service = app(QuotationService::class);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('يرجى إضافة عنوان شحن لحسابك قبل قبول عرض السعر.');

        $service->accept($quotation);
    }

    public function test_preview_notice_allocation_returns_structure_accurately(): void
    {
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => 500,
            'status' => 'pending_review',
        ]);

        $preview = app(WholesalePaymentNoticeService::class)->previewNoticeAllocation($notice);

        $this->assertSame($notice->id, $preview['notice_id']);
        $this->assertSame('500.00', $preview['amount']);
        $this->assertIsArray($preview['fifo']);
    }
}
