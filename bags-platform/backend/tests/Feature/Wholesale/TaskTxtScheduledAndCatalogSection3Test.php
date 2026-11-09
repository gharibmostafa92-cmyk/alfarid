<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Catalog\PriceListType;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Orders\SalesChannel;
use App\Enums\Finance\TreasuryType;
use App\Enums\Wholesale\WholesalePaymentMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Events\Wholesale\QuotationSubmitted;
use App\Listeners\Wholesale\WholesaleLifecycleSubscriber;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutboundMessage;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\WholesaleOutbox;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use App\Services\Wholesale\WholesalePriceListService;
use App\Services\Wholesale\WholesaleQuickOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * task.txt Section 3: الأوامر المجدولة (Scheduled Commands) + المنتجات والطلب السريع ولوحة التاجر
 */
class TaskTxtScheduledAndCatalogSection3Test extends TestCase
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
            'name' => 'فرع الكتالوج',
            'code' => 'BR-CAT3',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->trader = Customer::factory()->create([
            'name' => 'تاجر الجدولة والكتالوج',
            'phone' => '01095554433',
            'email' => 'sched-cat@example.com',
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => '10000.00',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_scheduled_commands_are_idempotent_and_sla_boundaries_use_documented_timestamps(): void
    {
        // 1. Seed a quotation expiring within 48h (tomorrow) for quote-expiry-reminders
        $expiringQuote = Quotation::create([
            'quotation_number' => 'QT-SCHED-EXP',
            'customer_id' => $this->trader->id,
            'status' => QuotationStatus::PricedByAdmin,
            'valid_until' => now()->addDay()->toDateString(),
            'subtotal' => '500.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '500.00',
            'revision' => 2,
        ]);

        // 2. Seed a quotation whose valid_until was yesterday for quotations:expire
        $pastQuote = Quotation::create([
            'quotation_number' => 'QT-SCHED-PAST',
            'customer_id' => $this->trader->id,
            'status' => QuotationStatus::PricedByAdmin,
            'valid_until' => now()->subDay()->toDateString(),
            'subtotal' => '300.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '300.00',
            'revision' => 2,
        ]);

        // 3. SLA boundary distinction between WholesaleDailyBriefing (uses created_at <= -24h)
        // and EscalateStaleWholesale (uses updated_at <= -quoteHours for quotes, created_at <= -noticeHours for notices):
        // Create a Requested quote with created_at = 30h ago, but updated_at = 2h ago:
        // -> DailyBriefing counts it as stale (1), while EscalateStale does NOT count it (0)!
        $quoteCreatedOldUpdatedRecent = Quotation::create([
            'quotation_number' => 'QT-SLA-DIFF',
            'customer_id' => $this->trader->id,
            'status' => QuotationStatus::Requested,
            'subtotal' => '400.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '400.00',
            'revision' => 1,
        ]);
        DB::table('quotations')->where('id', $quoteCreatedOldUpdatedRecent->id)->update([
            'created_at' => now()->subHours(30),
            'updated_at' => now()->subHours(2),
        ]);

        // Create a pending payment notice with created_at = 30h ago so EscalateStale has 1 notice & 0 quotes
        $staleNotice = WholesalePaymentNotice::create([
            'notice_number' => 'PN-SLA-1',
            'customer_id' => $this->trader->id,
            'amount' => '250.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-SLA-1',
            'transfer_date' => now()->subDays(2)->toDateString(),
            'receipt_file_path' => 'wholesale_payment_notices/fake.pdf',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);
        DB::table('wholesale_payment_notices')->where('id', $staleNotice->id)->update([
            'created_at' => now()->subHours(30),
        ]);

        // 4. Seed an overdue credit order (due 2 days ago) for wholesale:mark-overdue and wholesale:payment-reminders
        $overdueOrder = Order::create([
            'order_number' => 'ORD-SCHED-OVERDUE',
            'customer_id' => $this->trader->id,
            'channel' => 'wholesale',
            'recipient_name' => $this->trader->name,
            'recipient_phone' => $this->trader->phone,
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address_line' => 'شارع الطيران',
            'subtotal' => '1200.00',
            'discount_amount' => '0.00',
            'shipping_fee' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '1200.00',
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'due_at' => now()->subDays(2),
        ]);

        // Run quotations:expire --dry-run first (must not mutate status)
        Artisan::call('quotations:expire', ['--dry-run' => true]);
        $this->assertSame(QuotationStatus::PricedByAdmin, $pastQuote->fresh()->status);

        // Run quotations:expire for real
        Artisan::call('quotations:expire');
        $this->assertSame(QuotationStatus::Expired, $pastQuote->fresh()->status);

        // Run all 5 outbound-generating commands TWICE on the same day and verify OutboundMessage count does not increase on second run
        foreach ([
            'wholesale:daily-briefing',
            'wholesale:escalate-stale',
            'wholesale:quote-expiry-reminders',
            'wholesale:mark-overdue',
            'wholesale:payment-reminders',
        ] as $command) {
            Artisan::call($command);
        }

        $countAfterFirstRun = OutboundMessage::count();
        $this->assertGreaterThanOrEqual(5, $countAfterFirstRun);

        // Verify SLA difference in queued messages:
        // Daily briefing sees 1 stale quote (created_at <= 24h), EscalateStale sees 0 stale quotes (updated_at is 2h ago) and 1 notice
        $briefingMsg = OutboundMessage::query()->where('template', 'staff_daily_briefing')->firstOrFail();
        $escalationMsg = OutboundMessage::query()->where('template', 'staff_escalation')->firstOrFail();
        $this->assertStringContainsString('و1 عروض مستنية تسعير', (string) ($briefingMsg->payload['message'] ?? ''));
        $this->assertStringContainsString('0 عروض تجاوزت', (string) ($escalationMsg->payload['message'] ?? ''));
        $this->assertStringContainsString('و1 إشعارات تجاوزت', (string) ($escalationMsg->payload['message'] ?? ''));

        // Second execution on the same day must be 100% idempotent for OutboundMessage rows
        foreach ([
            'wholesale:daily-briefing',
            'wholesale:escalate-stale',
            'wholesale:quote-expiry-reminders',
            'wholesale:mark-overdue',
            'wholesale:payment-reminders',
        ] as $command) {
            Artisan::call($command);
        }

        $this->assertSame($countAfterFirstRun, OutboundMessage::count());
        $this->assertNotNull($expiringQuote->fresh());
        $this->assertNotNull($overdueOrder->fresh());
    }

    public function test_lifecycle_subscriber_guard_catches_exceptions_without_breaking_caller(): void
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-GUARD-1',
            'customer_id' => $this->trader->id,
            'status' => QuotationStatus::Requested,
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '100.00',
            'revision' => 1,
        ]);

        // Force Notification facade to throw inside WholesaleLifecycleSubscriber::onQuotationSubmitted
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Simulated notification channel outage'));

        $subscriber = app(WholesaleLifecycleSubscriber::class);
        // Must not throw out of onQuotationSubmitted thanks to guard()
        $subscriber->onQuotationSubmitted(new QuotationSubmitted($quotation));
        $this->assertTrue(true);
    }

    public function test_catalog_excludes_inactive_ancestor_categories_and_exports_customer_specific_prices(): void
    {
        $activeRoot = Category::create(['name_ar' => 'جذر نشط', 'name_en' => 'Active Root', 'slug' => 'act-root', 'is_active' => true]);
        $inactiveGrandparent = Category::create(['name_ar' => 'جد معطل', 'name_en' => 'Inactive GP', 'slug' => 'inact-gp', 'is_active' => false]);
        $activeParentUnderInactiveGp = Category::create([
            'name_ar' => 'أب نشط تحت جد معطل',
            'name_en' => 'Active Parent',
            'slug' => 'act-parent-inact-gp',
            'parent_id' => $inactiveGrandparent->id,
            'is_active' => true,
        ]);
        $activeLeafUnderInactiveGp = Category::create([
            'name_ar' => 'فرع نشط تحت جد معطل',
            'name_en' => 'Active Leaf',
            'slug' => 'act-leaf-inact-gp',
            'parent_id' => $activeParentUnderInactiveGp->id,
            'is_active' => true,
        ]);

        $visibleProduct = Product::factory()->create([
            'name_ar' => 'شنطة ظاهرة',
            'category_id' => $activeRoot->id,
            'status' => 'published',
            'sales_channel' => SalesChannel::Both,
        ]);
        $visibleVariant = $visibleProduct->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $visibleProduct->id]);
        $visibleVariant->update(['sku' => 'SKU-VIS-1', 'retail_price' => 200, 'wholesale_price' => 150, 'is_active' => true]);

        $hiddenProduct = Product::factory()->create([
            'name_ar' => 'شنطة تحت جد معطل',
            'category_id' => $activeLeafUnderInactiveGp->id,
            'status' => 'published',
            'sales_channel' => SalesChannel::Both,
        ]);
        $hiddenVariant = $hiddenProduct->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $hiddenProduct->id]);
        $hiddenVariant->update(['sku' => 'SKU-HID-1', 'retail_price' => 200, 'wholesale_price' => 150, 'is_active' => true]);

        // Give trader a custom price list with unit_price = 125.00 and a tier at min_qty = 10 -> 115.00
        $group = CustomerGroup::create([
            'name' => 'مجموعة كبار التجار',
            'code' => 'GRP-VIP-CAT',
            'discount_percentage' => 0,
            'is_active' => true,
        ]);
        $priceList = PriceList::create([
            'name' => 'قائمة أسعار خاصة',
            'type' => PriceListType::CustomerGroup,
            'customer_group_id' => $group->id,
            'currency' => 'EGP',
            'priority' => 10,
            'is_active' => true,
        ]);
        $this->trader->update(['customer_group_id' => $group->id]);

        PriceListItem::create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $visibleVariant->id,
            'min_qty' => 1,
            'price' => '125.00',
        ]);
        PriceListItem::create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $visibleVariant->id,
            'min_qty' => 10,
            'price' => '115.00',
        ]);

        $token = $this->trader->createToken('portal')->plainTextToken;
        $res = $this->withToken($token)->getJson('/api/v1/wholesale/products?per_page=50')->assertOk();

        $returnedIds = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($visibleProduct->id, $returnedIds);
        $this->assertNotContains($hiddenProduct->id, $returnedIds);

        $visData = collect($res->json('data'))->firstWhere('id', $visibleProduct->id);
        $this->assertSame(125.0, (float) $visData['variants'][0]['your_price']);
        $this->assertNotEmpty($visData['variants'][0]['tier_prices']);

        // Price list export rows & endpoints (PDF + XLSX) carry customer-specific price (125.0)
        $rows = app(WholesalePriceListService::class)->rows($this->trader);
        $visRow = collect($rows)->firstWhere('sku', 'SKU-VIS-1');
        $this->assertNotNull($visRow);
        $this->assertSame(125.0, (float) $visRow['wholesale_price']);

        $this->withToken($token)->get('/api/v1/wholesale/price-list/export?format=xlsx')->assertOk();
        $this->withToken($token)->get('/api/v1/wholesale/price-list/export?format=pdf')->assertOk();
    }

    public function test_quick_order_merges_duplicate_skus_respects_existing_cart_stock_and_reorder_uses_todays_price(): void
    {
        $product = Product::factory()->create(['status' => 'published', 'sales_channel' => SalesChannel::Both]);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['sku' => 'SKU-QO-MERGE', 'retail_price' => 200, 'wholesale_price' => 140, 'is_active' => true]);

        DB::table('stock_levels')->insert([
            'branch_id' => $this->branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
            'reorder_level' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quickOrder = app(WholesaleQuickOrderService::class);

        // 1. Import duplicate SKU lines (3 + 4 = 7 <= 10 available) -> merged into 1 added line with qty 7
        $report1 = $quickOrder->importToCart($this->trader, [
            ['sku' => 'SKU-QO-MERGE', 'qty' => 3],
            ['sku' => 'SKU-QO-MERGE', 'qty' => 4],
        ]);
        $this->assertCount(1, $report1['added']);
        $this->assertSame(7.0, (float) $report1['added'][0]['qty']);
        $this->assertEmpty($report1['skipped']);

        // 2. Cart now has 7; available is 10. Trying to import qty = 4 (7 + 4 = 11 > 10) -> skipped with reason = insufficient_stock
        $report2 = $quickOrder->importToCart($this->trader, [
            ['sku' => 'SKU-QO-MERGE', 'qty' => 4],
        ]);
        $this->assertEmpty($report2['added']);
        $this->assertCount(1, $report2['skipped']);
        $this->assertSame('insufficient_stock', $report2['skipped'][0]['reason']);

        // 3. Reorder a historical order where the item was bought at old price (90.00), while today's wholesale_price is 140.00
        $historicalOrder = Order::create([
            'order_number' => 'ORD-HIST-REORDER',
            'customer_id' => $this->trader->id,
            'channel' => 'wholesale',
            'recipient_name' => $this->trader->name,
            'recipient_phone' => $this->trader->phone,
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address_line' => 'شارع مكرم عبيد',
            'subtotal' => '180.00',
            'discount_amount' => '0.00',
            'shipping_fee' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '180.00',
            'payment_method' => 'credit',
            'payment_status' => 'paid',
            'status' => OrderStatus::Delivered,
        ]);
        OrderItem::create([
            'order_id' => $historicalOrder->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $product->name_ar,
            'variant_name_snapshot' => 'Default',
            'sku_snapshot' => $variant->sku,
            'qty' => 2,
            'unit_price' => '90.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => '180.00',
        ]);

        $reorderReport = $quickOrder->reorderToCart($this->trader, $historicalOrder);
        $this->assertCount(1, $reorderReport['added']);
        $this->assertSame(140.0, (float) $reorderReport['added'][0]['unit_price']);
    }

    public function test_dashboard_credit_balance_when_customer_has_floating_credit_after_linked_order_cancellation(): void
    {
        Storage::fake('r2');
        $noticeService = app(WholesalePaymentNoticeService::class);

        Treasury::create([
            'name' => 'بنك لوحة التحكم',
            'code' => 'TR-DASH-BNK',
            'type' => TreasuryType::BankAccount,
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'current_balance' => '0.00',
        ]);

        // Create an unpaid credit order for 1000, submit & approve a linked notice of 1000, then cancel the order
        // -> Trader now has -1000.00 outstandingBalance (i.e. 1000 EGP credit in their favor)
        $order = Order::create([
            'order_number' => 'ORD-DASH-CRED',
            'customer_id' => $this->trader->id,
            'channel' => 'wholesale',
            'recipient_name' => $this->trader->name,
            'recipient_phone' => $this->trader->phone,
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address_line' => 'شارع النزهة',
            'subtotal' => '1000.00',
            'discount_amount' => '0.00',
            'shipping_fee' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '1000.00',
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'allocated_branch_id' => $this->branch->id,
        ]);

        $notice = $noticeService->create($this->trader, [
            'order_id' => $order->id,
            'amount' => '1000.00',
            'payment_method' => WholesalePaymentMethod::BankTransfer->value,
            'bank_name' => 'CIB',
            'reference_number' => 'REF-DASH-1',
            'transfer_date' => now()->toDateString(),
        ], UploadedFile::fake()->createWithContent('dash.pdf', '%PDF-1.4 dash'));

        $noticeService->approve($notice, $this->admin->id);
        $order->update(['status' => OrderStatus::Cancelled]);

        $token = $this->trader->createToken('portal')->plainTextToken;
        $res = $this->withToken($token)->getJson('/api/v1/wholesale/dashboard')->assertOk();

        // Documented behavior in task.txt §3: credit_limit = 10000.00, used_credit = -1000.00, available_credit = 11000.00
        $this->assertSame('10000.00', $res->json('data.credit_limit'));
        $this->assertSame('-1000.00', $res->json('data.used_credit'));
        $this->assertSame('11000.00', $res->json('data.available_credit'));
    }
}
