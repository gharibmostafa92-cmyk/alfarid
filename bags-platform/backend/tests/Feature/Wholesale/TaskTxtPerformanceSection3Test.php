<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\SalesChannel;
use App\Enums\WholesalePaymentMethod;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderAllocationService;
use App\Services\Wholesale\BankStatementMatcher;
use App\Services\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * task.txt Section 3: اختبارات الأداء والحمل (Performance Tests)
 */
class TaskTxtPerformanceSection3Test extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Customer $trader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->trader = Customer::factory()->create([
            'name' => 'تاجر الأداء والحمل',
            'company_name' => 'شركة الفريد الكبرى للتجارة',
            'phone' => '01091112233',
            'email' => 'perf-sec3@example.com',
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => '10000000.00',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_wholesale_catalog_executes_bounded_constant_queries_without_n_plus_one(): void
    {
        $category = Category::create([
            'name_ar' => 'قسم الأداء',
            'name_en' => 'Perf Cat',
            'slug' => 'perf-cat',
            'is_active' => true,
        ]);

        $branch = Branch::create(['name' => 'فرع الأداء', 'code' => 'BR-PERF-1', 'is_active' => true]);

        // Seed 20 products x 5 variants = 100 variants
        for ($p = 1; $p <= 20; $p++) {
            $product = Product::factory()->create([
                'name_ar' => "منتج أداء {$p}",
                'category_id' => $category->id,
                'status' => 'published',
                'sales_channel' => SalesChannel::Both,
            ]);
            // Clear default factory variant so we create exactly 5 variants
            $product->variants()->delete();
            for ($v = 1; $v <= 5; $v++) {
                $variant = ProductVariant::factory()->create([
                    'product_id' => $product->id,
                    'sku' => "PERF-P{$p}-V{$v}",
                    'retail_price' => 200,
                    'wholesale_price' => 150,
                    'is_active' => true,
                ]);
                DB::table('stock_levels')->insert([
                    'branch_id' => $branch->id,
                    'product_variant_id' => $variant->id,
                    'on_hand' => 50,
                    'reserved' => 0,
                    'damaged' => 0,
                    'in_transit' => 0,
                    'reorder_level' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $token = $this->trader->createToken('portal')->plainTextToken;

        DB::enableQueryLog();
        DB::flushQueryLog();

        $res = $this->withToken($token)->getJson('/api/v1/wholesale/products?per_page=100')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(20, $res->json('data'));
        // With 20 products and 100 variants, N+1 would fire > 100 queries.
        // Preloading via contextFor + eager loading keeps query count <= 15!
        $this->assertLessThanOrEqual(15, count($queries), 'GET /wholesale/products must not exhibit N+1 queries.');
    }

    public function test_statement_and_balance_breakdown_handle_bulk_history_within_budget(): void
    {
        $now = now();
        $orderRows = [];
        for ($i = 1; $i <= 600; $i++) {
            $orderRows[] = [
                'order_number' => "ORD-BULK-{$i}",
                'customer_id' => $this->trader->id,
                'channel' => 'wholesale',
                'shipping_address_line' => 'شارع الطاقة',
                'subtotal' => '100.00',
                'discount_amount' => '0.00',
                'shipping_cost' => '0.00',
                'tax_amount' => '0.00',
                'grand_total' => '100.00',
                'payment_method' => 'credit',
                'payment_status' => $i <= 300 ? 'paid' : 'unpaid',
                'status' => OrderStatus::Confirmed->value,
                'created_at' => $now->copy()->subDays(600 - $i),
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($orderRows, 200) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        $noticeRows = [];
        for ($i = 1; $i <= 300; $i++) {
            $noticeRows[] = [
                'notice_number' => "PN-BULK-{$i}",
                'customer_id' => $this->trader->id,
                'order_id' => null,
                'amount' => '100.00',
                'payment_method' => WholesalePaymentMethod::BankTransfer->value,
                'bank_name' => 'CIB',
                'reference_number' => "REF-BULK-{$i}",
                'transfer_date' => $now->copy()->subDays(300 - $i)->toDateString(),
                'receipt_file_path' => "wholesale_payment_notices/bulk-{$i}.pdf",
                'status' => WholesalePaymentNoticeStatus::Approved->value,
                'approved_at' => $now->copy()->subDays(300 - $i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($noticeRows, 200) as $chunk) {
            DB::table('wholesale_payment_notices')->insert($chunk);
        }

        $service = app(WholesalePaymentNoticeService::class);

        $t0 = microtime(true);
        $breakdown = $service->balanceBreakdown($this->trader);
        $statement = $service->statement($this->trader);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        // 300 unpaid orders * 100 = 30000.00 debt; 300 unallocated free notices * 100 = 30000.00 free_pool -> 0.00 outstanding
        $this->assertSame('30000.00', $breakdown['debt']);
        $this->assertSame('30000.00', $breakdown['free_pool']);
        $this->assertSame('0.00', $breakdown['outstanding']);
        $this->assertCount(900, $statement['entries']);
        $this->assertLessThan(2000.0, $elapsedMs, 'Bulk statement + balanceBreakdown must complete in under 2 seconds.');
    }

    public function test_bank_statement_matcher_reconciles_bulk_rows_against_pending_notices_quickly(): void
    {
        $now = now();
        $today = $now->toDateString();

        $pendingNotices = [];
        for ($i = 1; $i <= 200; $i++) {
            $pendingNotices[] = [
                'notice_number' => "PN-MATCH-{$i}",
                'customer_id' => $this->trader->id,
                'order_id' => null,
                'amount' => number_format(1000 + $i, 2, '.', ''),
                'payment_method' => WholesalePaymentMethod::BankTransfer->value,
                'bank_name' => 'CIB',
                'reference_number' => "BNK-REF-{$i}",
                'transfer_date' => $today,
                'receipt_file_path' => "wholesale_payment_notices/m-{$i}.pdf",
                'status' => WholesalePaymentNoticeStatus::PendingReview->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('wholesale_payment_notices')->insert($pendingNotices);

        $statementRows = [];
        for ($r = 1; $r <= 300; $r++) {
            if ($r <= 100) {
                // Exact matches
                $statementRows[] = [
                    'amount' => 1000 + $r,
                    'reference' => "BNK-REF-{$r}",
                    'date' => $today,
                    'description' => "تحويل من شركة الفريد الكبرى للتجارة {$r}",
                ];
            } elseif ($r <= 200) {
                // Probable matches (different reference, same amount and date)
                $statementRows[] = [
                    'amount' => 1000 + $r,
                    'reference' => "OTHER-REF-{$r}",
                    'date' => $today,
                    'description' => 'تحويل بنكي',
                ];
            } else {
                // Unmatched with customer name in description
                $statementRows[] = [
                    'amount' => 90000 + $r,
                    'reference' => "UNMATCHED-{$r}",
                    'date' => $today,
                    'description' => 'تحويل وارد من شركة الفريد الكبرى للتجارة',
                ];
            }
        }

        $matcher = app(BankStatementMatcher::class);
        $t0 = microtime(true);
        $result = $matcher->match($statementRows);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        $this->assertCount(100, $result['exact']);
        $this->assertCount(100, $result['probable']);
        $this->assertCount(100, $result['unmatched']);
        $this->assertSame($this->trader->id, $result['unmatched'][0]['suggestion']['customer_id'] ?? null);
        $this->assertLessThan(2000.0, $elapsedMs, 'BankStatementMatcher bulk match must finish within 2 seconds.');
    }

    public function test_order_allocation_service_allocates_100_items_across_10_branches(): void
    {
        $branches = [];
        for ($b = 1; $b <= 10; $b++) {
            $branches[$b] = Branch::create([
                'name' => "فرع توزيع {$b}",
                'code' => "BR-ALLOC-{$b}",
                'is_active' => true,
            ]);
        }

        $product = Product::factory()->create(['status' => 'published', 'sales_channel' => SalesChannel::Both]);
        $product->variants()->delete();

        $order = Order::create([
            'order_number' => 'ORD-ALLOC-100',
            'customer_id' => $this->trader->id,
            'channel' => 'wholesale',
            'recipient_name' => $this->trader->name,
            'recipient_phone' => $this->trader->phone,
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address_line' => 'شارع التوزيع',
            'subtotal' => '10000.00',
            'discount_amount' => '0.00',
            'shipping_fee' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '10000.00',
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
        ]);

        // Create 100 distinct variants, each stocked in one of the 10 branches (item $i in branch (($i - 1) % 10) + 1)
        for ($i = 1; $i <= 100; $i++) {
            $targetBranch = $branches[(($i - 1) % 10) + 1];
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->id,
                'sku' => "SKU-ALLOC-{$i}",
                'retail_price' => 120,
                'wholesale_price' => 100,
                'is_active' => true,
            ]);

            DB::table('stock_levels')->insert([
                'branch_id' => $targetBranch->id,
                'product_variant_id' => $variant->id,
                'on_hand' => 20,
                'reserved' => 0,
                'damaged' => 0,
                'in_transit' => 0,
                'reorder_level' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $product->name_ar,
                'variant_name_snapshot' => "V-{$i}",
                'sku_snapshot' => $variant->sku,
                'qty' => 5,
                'unit_price' => '100.00',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'line_total' => '500.00',
            ]);
        }

        $allocator = app(OrderAllocationService::class);
        $allocatedOrder = $allocator->allocate($order, null, $this->admin->id);

        $this->assertCount(100, $allocatedOrder->items);
        $distinctBranches = $allocatedOrder->items->pluck('allocated_branch_id')->unique()->filter()->count();
        $this->assertSame(10, $distinctBranches, 'All 10 branches should receive 10 item allocations each.');
    }
}
