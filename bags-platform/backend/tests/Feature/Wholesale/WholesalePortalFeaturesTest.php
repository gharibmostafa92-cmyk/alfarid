<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WholesalePortalFeaturesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $token;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 5000,
        ]);
        $this->token = $this->trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'is_active' => true, 'barcode' => '6220001']);

        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 100,
        ]);

        // قائمة أسعار جملة بشرائح كميات: 1+ = 100، 10+ = 92، 50+ = 85
        $list = PriceList::create([
            'name' => 'جملة أساسية', 'type' => 'wholesale', 'currency' => 'EGP',
            'priority' => 1, 'is_active' => true,
        ]);
        foreach ([[1, 100], [10, 92], [50, 85]] as [$minQty, $price]) {
            $list->items()->create([
                'product_variant_id' => $this->variant->id,
                'min_qty' => $minQty,
                'price' => $price,
            ]);
        }
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->token);
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        return $this->withToken($admin->createToken('admin')->plainTextToken);
    }

    private function creditOrder(float $total, OrderStatus $status = OrderStatus::Delivered): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->trader->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => $status,
            'subtotal' => $total,
            'grand_total' => $total,
        ]);
    }

    public function test_dashboard_shows_credit_position_and_latest_activity(): void
    {
        $this->creditOrder(1000);
        $this->creditOrder(500, OrderStatus::Processing);

        $this->asTrader()->getJson('/api/v1/wholesale/dashboard')
            ->assertOk()
            ->assertJsonPath('data.credit_limit', '5000.00')
            ->assertJsonPath('data.used_credit', '1500.00')
            ->assertJsonPath('data.available_credit', '3500.00')
            ->assertJsonPath('data.in_progress_orders_count', 1)
            ->assertJsonCount(2, 'data.latest_invoices');
    }

    public function test_products_expose_trader_price_and_quantity_tiers(): void
    {
        $response = $this->asTrader()->getJson('/api/v1/wholesale/products')->assertOk();
        $variant = collect($response->json('data'))->flatMap(fn ($p) => $p['variants'])
            ->firstWhere('id', $this->variant->id);

        $this->assertSame(100.0, (float) $variant['your_price']);
        $this->assertSame(
            [[1.0, 100.0], [10.0, 92.0], [50.0, 85.0]],
            collect($variant['tier_prices'])->map(fn ($t) => [(float) $t['min_qty'], (float) $t['unit_price']])->all(),
        );
        $this->assertSame(100.0, (float) $variant['available_qty']);
    }

    public function test_price_list_export_xlsx_and_pdf(): void
    {
        $xlsx = $this->asTrader()->get('/api/v1/wholesale/price-list/export?format=xlsx');
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheet', (string) $xlsx->headers->get('content-type'));

        $pdf = $this->asTrader()->get('/api/v1/wholesale/price-list/export?format=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_quick_order_lookup_prices_tiers_and_availability(): void
    {
        $response = $this->asTrader()->postJson('/api/v1/wholesale/quick-order/lookup', [
            'items' => [
                ['sku' => $this->variant->sku, 'qty' => 10],
                ['barcode' => '6220001', 'qty' => 3],
                ['sku' => 'NOPE-404', 'qty' => 1],
            ],
        ])->assertOk();

        $lines = $response->json('data');
        $this->assertTrue($lines[0]['found']);
        $this->assertSame(92.0, (float) $lines[0]['unit_price']);
        $this->assertSame(920.0, (float) $lines[0]['line_total']);
        $this->assertTrue($lines[0]['in_stock']);
        $this->assertTrue($lines[1]['found']);
        $this->assertFalse($lines[2]['found']);
    }

    public function test_quick_order_import_adds_available_and_reports_skipped(): void
    {
        $csv = "sku,qty\n{$this->variant->sku},5\nUNKNOWN-1,3\n{$this->variant->sku},500\n";
        $file = UploadedFile::fake()->createWithContent('order.csv', $csv);

        $response = $this->asTrader()->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/quick-order/import', ['file' => $file])
            ->assertOk();

        $added = $response->json('data.added');
        $skipped = collect($response->json('data.skipped'));
        // البندان لنفس الـ SKU دُمجا (505) فتجاوزا المتاح (100) وتخطّيا معًا
        $this->assertCount(0, $added);
        $this->assertTrue($skipped->contains('reason', 'insufficient_stock'));
        $this->assertTrue($skipped->contains('reason', 'not_found_or_inactive'));

        $csv2 = "sku,qty\n{$this->variant->sku},5\n";
        $file2 = UploadedFile::fake()->createWithContent('order2.csv', $csv2);
        $response2 = $this->asTrader()->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/quick-order/import', ['file' => $file2])
            ->assertOk();
        $this->assertCount(1, $response2->json('data.added'));

        $cart = Cart::query()->where('customer_id', $this->trader->id)->first();
        $this->assertSame(5.0, (float) $cart->items()->where('product_variant_id', $this->variant->id)->value('qty'));
    }

    public function test_reorder_copies_previous_order_items_into_cart_at_todays_prices(): void
    {
        $order = $this->creditOrder(300);
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->variant->id,
            'product_name_snapshot' => 'حقيبة',
            'sku_snapshot' => $this->variant->sku,
            'qty' => 12,
            'unit_price' => 25,
            'discount_amount' => 0,
            'line_total' => 300,
        ]);

        $response = $this->asTrader()->postJson("/api/v1/wholesale/orders/{$order->id}/reorder")->assertOk();
        $this->assertCount(1, $response->json('data.added'));
        // أُعيد التسعير بسعر اليوم عند كمية 12 (شريحة 92) لا بسعر الطلب القديم
        $this->assertSame(92.0, (float) $response->json('data.added.0.unit_price'));

        $other = Order::factory()->create();
        $this->asTrader()->postJson("/api/v1/wholesale/orders/{$other->id}/reorder")->assertNotFound();
    }

    public function test_invoices_list_and_pdf_download(): void
    {
        $order = $this->creditOrder(750);

        $this->asTrader()->getJson('/api/v1/wholesale/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.invoice_number', $order->order_number)
            ->assertJsonPath('data.0.grand_total', '750.00');

        $pdf = $this->asTrader()->get("/api/v1/wholesale/invoices/{$order->id}/pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $other = Order::factory()->create(['payment_method' => 'credit']);
        $this->asTrader()->get("/api/v1/wholesale/invoices/{$other->id}/pdf")->assertNotFound();
    }

    public function test_statement_running_balance_with_debits_and_credits(): void
    {
        Treasury::create([
            'name' => 'بنك', 'code' => 'BANK-1', 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $this->creditOrder(1000);

        $noticeId = $this->asTrader()->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/payment-notices', [
                'amount' => 400,
                'payment_method' => 'bank_transfer',
                'bank_name' => 'بنك مصر',
                'reference_number' => 'TRX-1',
                'transfer_date' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->image('r.jpg'),
            ])->assertCreated()->json('data.id');

        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$noticeId}/approve", [])->assertOk();

        $response = $this->asTrader()->getJson('/api/v1/wholesale/statement')->assertOk();
        $entries = $response->json('data.entries');
        $this->assertCount(2, $entries);
        $this->assertSame('debit', $entries[0]['type']);
        $this->assertSame('1000.00', $entries[0]['running_balance']);
        $this->assertSame('credit', $entries[1]['type']);
        $this->assertSame('600.00', $entries[1]['running_balance']);
        $response->assertJsonPath('data.closing_balance', '600.00');
    }
}
