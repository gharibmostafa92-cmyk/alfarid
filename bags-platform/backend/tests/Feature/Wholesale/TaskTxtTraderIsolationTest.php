<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\QuotationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskTxtTraderIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $traderA;

    private Customer $traderB;

    private string $tokenA;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->traderA = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->traderB = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->tokenA = $this->traderA->createToken('a')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 100,
        ]);
    }

    private function asA(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->tokenA);
    }

    private function asB(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderB->createToken('b')->plainTextToken);
    }

    private function bQuotation(QuotationStatus $status): Quotation
    {
        $id = $this->asB()->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json('data.id');

        $quotation = Quotation::query()->findOrFail($id);
        if ($status !== QuotationStatus::Draft) {
            $quotation->update(['status' => $status]);
        }

        return $quotation->fresh();
    }

    private function bOrder(): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->traderB->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => 1000,
            'subtotal' => 1000,
        ]);
    }

    private function bNotice(): WholesalePaymentNotice
    {
        $id = $this->asB()->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/payment-notices', [
                'amount' => 100,
                'payment_method' => 'bank_transfer',
                'bank_name' => 'بنك مصر',
                'reference_number' => 'IDOR-'.Str::uuid(),
                'transfer_date' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])->assertCreated()->json('data.id');

        return WholesalePaymentNotice::query()->findOrFail($id);
    }

    public function test_trader_cannot_read_or_mutate_another_traders_quotations(): void
    {
        $draft = $this->bQuotation(QuotationStatus::Draft);
        $requested = $this->bQuotation(QuotationStatus::Requested);
        $priced = $this->bQuotation(QuotationStatus::PricedByAdmin);

        $this->asA()->getJson("/api/v1/wholesale/quotations/{$draft->id}")->assertNotFound();
        $this->asA()->putJson("/api/v1/wholesale/quotations/{$draft->id}", ['customer_notes' => 'hijack'])
            ->assertNotFound();
        $this->asA()->postJson("/api/v1/wholesale/quotations/{$draft->id}/submit")->assertNotFound();
        $this->asA()->postJson("/api/v1/wholesale/quotations/{$priced->id}/accept")->assertNotFound();
        $this->asA()->postJson("/api/v1/wholesale/quotations/{$requested->id}/reject")->assertNotFound();

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->customer_notes);
        $this->assertSame(QuotationStatus::Requested, $requested->fresh()->status);
        $this->assertSame(QuotationStatus::PricedByAdmin, $priced->fresh()->status);
        $this->assertSame(0, Order::query()->where('customer_id', $this->traderA->id)->count());
    }

    public function test_trader_cannot_touch_another_traders_orders_invoices_and_notices(): void
    {
        $order = $this->bOrder();
        $notice = $this->bNotice();

        $this->asA()->getJson("/api/v1/wholesale/orders/{$order->id}")->assertNotFound();
        $this->asA()->postJson("/api/v1/wholesale/orders/{$order->id}/reorder")->assertNotFound();
        $this->asA()->getJson("/api/v1/wholesale/invoices/{$order->id}/pdf")->assertNotFound();
        $this->asA()->getJson("/api/v1/wholesale/payment-notices/{$notice->id}")->assertNotFound();
        $this->asA()->getJson("/api/v1/wholesale/payment-notices/{$notice->id}/receipt")->assertNotFound();

        $this->asA()->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/payment-notices', [
                'amount' => 100,
                'order_id' => $order->id,
                'payment_method' => 'bank_transfer',
                'bank_name' => 'بنك مصر',
                'reference_number' => 'IDOR-HIJACK-'.Str::uuid(),
                'transfer_date' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->image('hijack.jpg', 200, 200),
            ])->assertNotFound();

        $this->assertSame(0, Order::query()->where('customer_id', $this->traderA->id)->count());
        $this->assertSame(0, WholesalePaymentNotice::query()->where('customer_id', $this->traderA->id)->count());
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }
}
