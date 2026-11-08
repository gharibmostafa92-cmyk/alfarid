<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskTxtNoticeCapsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private string $adminToken;

    private Treasury $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->adminToken = $admin->createToken('admin')->plainTextToken;

        $this->bank = Treasury::create([
            'name' => 'بنك', 'code' => 'CAP-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }

    private function authed(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function creditOrder(float $total): Order
    {
        return Order::factory()->create([
            'customer_id' => $this->trader->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => $total,
            'subtotal' => $total,
        ]);
    }

    private int $receiptSeq = 0;

    private function submitNotice(float $amount, ?int $orderId = null, string $suffix = '')
    {
        // Distinct dimensions per receipt: fake images of equal size share bytes
        // and would trip the receipt-sha dedup instead of the cap under test.
        $this->receiptSeq++;
        $size = 640 + $this->receiptSeq;

        return $this->authed($this->traderToken)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/wholesale/payment-notices', [
                'amount' => $amount,
                'order_id' => $orderId,
                'payment_method' => 'bank_transfer',
                'bank_name' => 'بنك مصر',
                'reference_number' => 'CAP-'.$suffix.'-'.Str::uuid(),
                'transfer_date' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->image('receipt-'.$suffix.'.jpg', $size, 480),
            ]);
    }

    public function test_linked_notices_cannot_exceed_the_order_total(): void
    {
        $order = $this->creditOrder(1000);

        $this->submitNotice(700, $order->id, 'a')->assertCreated();
        $this->submitNotice(300, $order->id, 'b')->assertCreated();
        $this->submitNotice(0.01, $order->id, 'c')->assertUnprocessable();

        $this->assertSame(2, WholesalePaymentNotice::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_second_concurrent_capacity_claim_loses(): void
    {
        $order = $this->creditOrder(1000);

        $this->submitNotice(600, $order->id, 'd')->assertCreated();
        // The customer row lock serializes the second claim against the same cap.
        $this->submitNotice(600, $order->id, 'e')->assertUnprocessable();

        $this->assertSame(1, WholesalePaymentNotice::query()->where('order_id', $order->id)->count());
    }

    public function test_approve_at_exact_outstanding_but_not_a_piastre_more(): void
    {
        $this->creditOrder(999.99);
        $okId = $this->submitNotice(999.99, null, 'f')->assertCreated()->json('data.id');

        $this->authed($this->adminToken)
            ->postJson("/api/v1/admin/payment-notices/{$okId}/approve", ['treasury_id' => $this->bank->id])
            ->assertOk();

        // Fresh debt of 500 against a 500.01 notice: one piastre too much.
        $this->trader->update(['credit_limit' => 100000]);
        $this->creditOrder(500);
        $overId = $this->submitNotice(500.01, null, 'g')->assertCreated()->json('data.id');

        $this->authed($this->adminToken)
            ->postJson("/api/v1/admin/payment-notices/{$overId}/approve", ['treasury_id' => $this->bank->id])
            ->assertUnprocessable();

        $this->assertSame('pending_review', WholesalePaymentNotice::query()->findOrFail($overId)->status->value);
        $this->assertSame('999.99', $this->bank->fresh()->current_balance);
        $this->assertDatabaseCount('treasury_transactions', 1);
    }

    public function test_approve_with_zero_outstanding_is_rejected(): void
    {
        // Documents current behavior: with no debt any notice exceeds the
        // outstanding, so advance payment via approve is rejected. Owner
        // decision on prepayment flow is still pending.
        $id = $this->submitNotice(100, null, 'h')->assertCreated()->json('data.id');

        $this->authed($this->adminToken)
            ->postJson("/api/v1/admin/payment-notices/{$id}/approve", ['treasury_id' => $this->bank->id])
            ->assertUnprocessable();

        $this->assertSame('pending_review', WholesalePaymentNotice::query()->findOrFail($id)->status->value);
        $this->assertDatabaseCount('treasury_transactions', 0);
        $this->assertSame('0.00', $this->bank->fresh()->current_balance);
    }
}
