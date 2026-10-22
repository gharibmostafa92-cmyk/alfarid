<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
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

class WholesalePortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

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
            'credit_limit' => 100000,
        ]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'is_active' => true]);
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin->createToken('admin')->plainTextToken;
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->adminToken());
    }

    private function createQuotation(): int
    {
        return $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2, 'requested_target_price' => 95],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.offered_unit_price', '100.00')
            ->assertJsonPath('data.grand_total', '200.00')
            ->json('data.id');
    }

    public function test_retail_and_pending_customers_are_blocked_by_the_triple_middleware(): void
    {
        // الزائر بلا توكن ممنوع من البوابة أصلًا
        $this->getJson('/api/v1/wholesale/quotations')->assertUnauthorized();

        $retail = Customer::factory()->create(['type' => 'retail', 'status' => 'approved']);
        Auth::forgetGuards();
        $this->withToken($retail->createToken('r')->plainTextToken)
            ->getJson('/api/v1/wholesale/quotations')->assertForbidden();

        $pending = Customer::factory()->create(['type' => 'wholesale', 'status' => 'pending']);
        Auth::forgetGuards();
        $this->withToken($pending->createToken('p')->plainTextToken)
            ->getJson('/api/v1/wholesale/quotations')->assertForbidden();
    }

    public function test_quotation_lifecycle_create_submit_price_accept(): void
    {
        // مخزون يغطي التحويل التلقائي لطلب عند القبول (المرحلة 12.2)
        StockLevel::create([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 10,
        ]);

        $id = $this->createQuotation();
        $number = Quotation::findOrFail($id)->quotation_number;
        $this->assertStringStartsWith('QT-', $number);

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'requested');

        // القبول قبل تسعير الإدارة مرفوض
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id}/accept")->assertUnprocessable();

        $itemId = Quotation::findOrFail($id)->items()->first()->id;
        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$id}/price", [
            'items' => [['id' => $itemId, 'offered_unit_price' => 90]],
            'discount_amount' => 10,
            'valid_until' => now()->addDays(30)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'priced_by_admin')
            ->assertJsonPath('data.subtotal', '180.00')
            ->assertJsonPath('data.grand_total', '170.00');

        // القبول يحوّل العرض تلقائيًا إلى طلب مؤكد آجل بنفس الأسعار المتفق عليها
        $response = $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id}/accept")
            ->assertOk()->assertJsonPath('data.status', 'converted_to_order');

        $orderId = $response->json('data.converted_order_id');
        $this->assertNotNull($orderId);
        $order = Order::findOrFail($orderId);
        $this->assertSame('credit', $order->payment_method);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('170.00', $order->grand_total);
        $this->assertSame('90.00', $order->items()->first()->unit_price);

        // الطلب الآجل الجديد يظهر في مديونية التاجر
        $this->asTrader()->getJson('/api/v1/wholesale/payment-notices/outstanding')
            ->assertOk()->assertJsonPath('data.outstanding_balance', '170.00');
    }

    public function test_quotation_reject_and_expired_accept_guard(): void
    {
        $id = $this->createQuotation();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id}/submit")->assertOk();
        $this->asAdmin()->postJson("/api/v1/admin/quotations/{$id}/price", [
            'valid_until' => now()->addDay()->toDateString(),
        ])->assertOk();

        // انتهاء الصلاحية فعليًا يمنع القبول ويحوّل الحالة إلى expired
        Quotation::findOrFail($id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id}/accept")->assertUnprocessable();
        $this->assertSame('expired', Quotation::findOrFail($id)->status->value);

        $id2 = $this->createQuotation();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id2}/submit")->assertOk();
        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$id2}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_other_customers_quotation_is_not_found(): void
    {
        $id = $this->createQuotation();
        $other = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        Auth::forgetGuards();
        $this->withToken($other->createToken('o')->plainTextToken)
            ->getJson("/api/v1/wholesale/quotations/{$id}")->assertNotFound();
    }

    private function creditOrder(float $total): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->trader->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'subtotal' => $total,
            'grand_total' => $total,
        ]);
    }

    private function bankTreasury(): Treasury
    {
        return Treasury::create([
            'name' => 'الحساب البنكي الرئيسي',
            'code' => 'BANK-1',
            'type' => TreasuryType::BankAccount,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function submitNotice(array $overrides = []): int
    {
        $payload = array_merge([
            'amount' => 1000,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك مصر',
            'reference_number' => 'TRX-123',
            'transfer_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ], $overrides);

        return $this->asTrader()->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/payment-notices', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->json('data.id');
    }

    public function test_notice_approval_records_treasury_receipt_and_settles_the_order(): void
    {
        $treasury = $this->bankTreasury();
        $order = $this->creditOrder(1000);
        $noticeId = $this->submitNotice(['order_id' => $order->id]);

        $this->asTrader()->getJson('/api/v1/wholesale/payment-notices/outstanding')
            ->assertOk()->assertJsonPath('data.outstanding_balance', '1000.00');

        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$noticeId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.treasury_id', $treasury->id);

        $this->assertDatabaseHas('treasury_transactions', [
            'treasury_id' => $treasury->id,
            'type' => 'customer_receipt',
            'amount' => 1000,
            'reference_type' => 'wholesale_payment_notice',
            'reference_id' => $noticeId,
        ]);
        $this->assertSame('1000.00', $treasury->fresh()->current_balance);
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->asTrader()->getJson('/api/v1/wholesale/payment-notices/outstanding')
            ->assertOk()->assertJsonPath('data.outstanding_balance', '0.00');

        // مراجعة ثانية على نفس الإشعار مرفوضة
        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$noticeId}/approve", [])->assertUnprocessable();
    }

    public function test_notice_approval_above_outstanding_debt_is_rejected(): void
    {
        $this->bankTreasury();
        $this->creditOrder(1000);
        $noticeId = $this->submitNotice(['amount' => 1500]);

        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$noticeId}/approve", [])->assertUnprocessable();
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_notice_reject_flow_and_creation_guards(): void
    {
        $this->bankTreasury();
        $order = $this->creditOrder(1000);

        // مبلغ يتجاوز إجمالي الطلب المرتبط يُرفض عند الإنشاء
        $this->asTrader()->withHeaders(['Accept' => 'application/json'])->post('/api/v1/wholesale/payment-notices', [
            'order_id' => $order->id,
            'amount' => 1200,
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك مصر',
            'reference_number' => 'TRX-9',
            'transfer_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertUnprocessable();

        // طلب تاجر آخر لا يمكن ربط إشعار به (404 — اصطلاح نطاق الملكية)
        $otherOrder = Order::factory()->create(['payment_method' => 'credit', 'payment_status' => 'unpaid']);
        $this->asTrader()->withHeaders(['Accept' => 'application/json'])->post('/api/v1/wholesale/payment-notices', [
            'order_id' => $otherOrder->id,
            'amount' => 10,
            'payment_method' => 'deposit',
            'bank_name' => 'بنك مصر',
            'reference_number' => 'TRX-10',
            'transfer_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ])->assertNotFound();

        $noticeId = $this->submitNotice(['amount' => 400]);
        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$noticeId}/reject", ['admin_notes' => 'الإيصال غير واضح'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->asAdmin()->postJson("/api/v1/admin/payment-notices/{$noticeId}/approve", [])->assertUnprocessable();
    }
}
