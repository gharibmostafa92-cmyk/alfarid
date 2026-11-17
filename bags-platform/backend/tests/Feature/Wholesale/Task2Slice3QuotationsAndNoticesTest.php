<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Catalog\ProductStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\SalesChannel;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\QuotationVersion;
use App\Services\Wholesale\QuoteAdvisor;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2Slice3QuotationsAndNoticesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_quotation_version_hash_changes_when_discount_tax_valid_until_or_revision_changes(): void
    {
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Wholesale]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'quotation_number' => 'QT-HASH-1',
            'status' => QuotationStatus::PricedByAdmin,
            'revision' => 1,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 1000,
            'valid_until' => '2026-12-01',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_variant_id' => $variant->id,
            'qty' => 10,
            'offered_unit_price' => 100,
            'line_total' => 1000,
        ]);

        $hash1 = QuotationVersion::hash($quotation);
        // Simulate discount/grand_total update within the same second (identical updated_at).
        $quotation->discount_amount = '100.00';
        $quotation->grand_total = '900.00';
        $hash2 = QuotationVersion::hash($quotation);

        $this->assertNotSame($hash1, $hash2, 'QuotationVersion::hash must reflect discount/grand_total changes even within the same second.');
    }

    public function test_restore_priced_snapshot_preserves_original_status_and_increments_revision(): void
    {
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Wholesale]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'quotation_number' => 'QT-REST-1',
            'status' => QuotationStatus::PricedByAdmin,
            'revision' => 2,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 1000,
            'valid_until' => '2026-12-01',
        ]);
        $item = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_variant_id' => $variant->id,
            'qty' => 10,
            'offered_unit_price' => 100,
            'line_total' => 1000,
        ]);

        $restored = app(QuotationService::class)->restorePricedSnapshot($quotation, [
            'status' => QuotationStatus::PricedByAdmin->value,
            'valid_until' => '2026-12-05',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'items' => [['id' => $item->id, 'offered_unit_price' => '95.00']],
        ]);

        $this->assertSame(QuotationStatus::PricedByAdmin, $restored->status);
        $this->assertSame(3, (int) $restored->revision);
    }

    public function test_whatsapp_reorder_ignores_cancelled_orders_and_requires_confirmation_instead_of_mutating_cart(): void
    {
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Wholesale]);
        $variantA = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'wholesale_price' => 100, 'retail_price' => 120]);
        $variantB = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'wholesale_price' => 200, 'retail_price' => 220]);

        $validOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::Wholesale->value,
            'payment_method' => 'credit',
            'status' => OrderStatus::Delivered,
            'grand_total' => 100,
        ]);
        OrderItem::create([
            'order_id' => $validOrder->id,
            'product_variant_id' => $variantA->id,
            'product_name_snapshot' => 'Test Bag A',
            'sku_snapshot' => $variantA->sku,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        $cancelledOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::Wholesale->value,
            'payment_method' => 'credit',
            'status' => OrderStatus::Cancelled,
            'grand_total' => 200,
        ]);
        OrderItem::create([
            'order_id' => $cancelledOrder->id,
            'product_variant_id' => $variantB->id,
            'product_name_snapshot' => 'Test Bag B',
            'sku_snapshot' => $variantB->sku,
            'qty' => 1,
            'unit_price' => 200,
            'line_total' => 200,
        ]);

        $phone = \App\Models\CustomerChannel::canonicalPhone((string) $customer->phone);
        \App\Models\CustomerChannel::create([
            'customer_id' => $customer->id,
            'provider' => 'whatsapp_cloud',
            'phone' => $phone,
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'عيد الطلب');

        $cartCount = Cart::query()->where('customer_id', $customer->id)->count();
        $this->assertSame(0, $cartCount, 'Reorder must stage confirmation instead of immediately mutating the live cart.');
        $this->assertStringContainsString($validOrder->order_number, $reply);
        $this->assertStringContainsString('لتأكيد الإضافة للسلة أرسل: سلة', $reply);
        $this->assertStringNotContainsString($cancelledOrder->order_number, $reply);
    }

    public function test_statement_orders_credit_before_its_same_day_reversal_and_recent_count_counts_both(): void
    {
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $admin = User::factory()->create();
        $treasury = Treasury::create(['name' => 'Main Safe', 'code' => 'MAIN-1', 'type' => 'main_safe', 'is_active' => true, 'current_balance' => 10000]);

        $notice = WholesalePaymentNotice::create([
            'customer_id' => $customer->id,
            'notice_number' => 'NOT-REV-1',
            'amount' => 500.00,
            'payment_method' => 'bank_transfer',
            'transfer_date' => '2026-11-16',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-REV-1',
            'receipt_file_path' => 'receipts/rev1.pdf',
            'status' => WholesalePaymentNoticeStatus::Reversed,
            'reviewed_by' => $admin->id,
            'approved_at' => '2026-11-16 10:00:00',
            'reversed_at' => '2026-11-16 14:00:00',
            'treasury_id' => $treasury->id,
        ]);

        $svc = app(WholesalePaymentNoticeService::class);
        $stmt = $svc->statement($customer, '2026-11-16', '2026-11-16');

        $this->assertCount(2, $stmt['entries']);
        $this->assertSame('credit', $stmt['entries'][0]['type'], 'Original payment must precede its same-day reversal.');
        $this->assertSame('debit', $stmt['entries'][1]['type']);

        $count = $svc->recentStatementCount($customer);
        $this->assertSame(2, $count, 'Reversed notice with both approval and reversal in window must count as 2 entries.');
    }

    public function test_quote_advisor_warns_on_zero_credit_limit_and_cancelled_order_notice_approves_as_free_credit(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => '0.00',
        ]);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Wholesale]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'wholesale_price' => 100]);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'quotation_number' => 'QT-CRED-0',
            'status' => QuotationStatus::Requested,
            'revision' => 1,
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);

        $advice = app(QuoteAdvisor::class)->suggest($quotation, null);
        $this->assertNotEmpty($advice['warnings'], 'QuoteAdvisor must warn when customer has 0 credit limit.');

        // F-12: Approving a notice linked to a cancelled order detaches order_id and succeeds as advance credit.
        $admin = User::factory()->create(['branch_id' => null]);
        $admin->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $treasury = Treasury::create(['name' => 'Central Safe', 'code' => 'CENTRAL-1', 'type' => 'main_safe', 'is_active' => true, 'branch_id' => null, 'current_balance' => 0]);

        $cancelledOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::Wholesale->value,
            'payment_method' => 'credit',
            'status' => OrderStatus::Cancelled,
            'grand_total' => 500,
        ]);
        $notice = WholesalePaymentNotice::create([
            'customer_id' => $customer->id,
            'order_id' => $cancelledOrder->id,
            'notice_number' => 'NOT-CANC-1',
            'amount' => 500.00,
            'payment_method' => 'bank_transfer',
            'transfer_date' => now()->toDateString(),
            'bank_name' => 'CIB',
            'reference_number' => 'REF-CANC-1',
            'receipt_file_path' => 'receipts/canc1.pdf',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $approved = app(WholesalePaymentNoticeService::class)->approve($notice, $admin->id, $treasury->id);
        $this->assertSame(WholesalePaymentNoticeStatus::Approved, $approved->status);
        $this->assertNull($approved->order_id);
    }
}
