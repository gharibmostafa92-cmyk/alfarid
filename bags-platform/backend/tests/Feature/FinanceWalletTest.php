<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Orders\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Orders\OrderService;
use App\Services\Finance\WalletAdjustmentService;
use App\Services\Finance\WalletService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 4: wallet adjustments (service, endpoints, immutability, cancel regression). */
class FinanceWalletTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function admin(): User
    {
        $u = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function svc(): WalletAdjustmentService
    {
        return app(WalletAdjustmentService::class);
    }

    public function test_adjust_credit_debit_refund_move_balance_with_typed_ledger(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $credit = $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'adjustment',
            'amount' => '100.00', 'direction' => 'credit', 'reason' => 'تسوية'], $admin, 'wallet-test-1');
        $this->assertSame('credit', $credit->direction);
        $this->assertSame('admin', $credit->source);
        $this->assertSame('100.00', (string) $customer->fresh()->wallet_balance);

        $debit = $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'disbursement',
            'amount' => '30.00', 'reason' => 'صرف'], $admin, 'wallet-test-2');
        $this->assertSame('70.00', (string) $customer->fresh()->wallet_balance);
        $this->assertTrue(CustomerWalletTransaction::where('reference_type', 'wallet_adjustment')
            ->where('reference_id', $debit->id)->where('type', 'adjustment')->where('amount', -30)->exists());

        $refund = $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'refund',
            'amount' => '10.00', 'reason' => 'استرداد'], $admin, 'wallet-test-3');
        $this->assertSame(80.0, (float) $customer->fresh()->wallet_balance);
        $this->assertTrue(CustomerWalletTransaction::where('reference_id', $refund->id)->where('type', 'refund')->exists());
    }

    public function test_adjustment_idempotency_and_key_required(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $first = $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'adjustment',
            'amount' => '50.00', 'direction' => 'credit', 'reason' => 'تسوية'], $admin, 'wallet-dup-1');
        $second = $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'adjustment',
            'amount' => '50.00', 'direction' => 'credit', 'reason' => 'تسوية'], $admin, 'wallet-dup-1');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(50.0, (float) $customer->fresh()->wallet_balance);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'adjustment',
            'amount' => '50.00', 'direction' => 'credit', 'reason' => 'تسوية'], $admin, null);
    }

    public function test_reverse_by_creator_and_double_reverse_rejected(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $other = $this->staff(['ai_agent.use']);

        $adj = $this->svc()->adjust(['customer_id' => $customer->id, 'kind' => 'adjustment',
            'amount' => '40.00', 'direction' => 'credit', 'reason' => 'تسوية'], $admin, 'wallet-rev-1');
        $this->assertSame(40.0, (float) $customer->fresh()->wallet_balance);

        try {
            $this->svc()->reverse($adj, $other, 'wallet-rev-x');
            $this->fail('expected 403');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(403, $e->status);
        }

        $reversal = $this->svc()->reverse($adj, $admin, 'wallet-rev-2');
        $this->assertSame($adj->id, $reversal->reverses_id);
        $this->assertSame('debit', $reversal->direction);
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);

        try {
            $this->svc()->reverse($adj, $admin, 'wallet-rev-3');
            $this->fail('expected rejection');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame('هذا التعديل معكوس بالفعل.', $e->getMessage());
        }
    }

    public function test_wallet_endpoints_with_permissions_and_labels(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        app(WalletService::class)->record($customer->id, 150.0, 'opening', 'customer', $customer->id);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        app(WalletService::class)->record($customer->id, -100.0, 'checkout_payment', 'order', $order->id);

        $viewer = $this->staff(['customers.wallet.view']);
        $show = $this->withToken($this->token($viewer))->getJson("/api/v1/admin/customers/{$customer->id}/wallet")->assertOk();
        $show->assertJsonPath('balance', '50.00');
        $show->assertJsonPath('transactions.data.0.type_label', 'دفع طلب');

        $summary = $this->withToken($this->token($viewer))->getJson("/api/v1/admin/customers/{$customer->id}/wallet/summary")->assertOk();
        $summary->assertJsonPath('breakdown.topups', 150);
        $summary->assertJsonPath('breakdown.payments', -100);

        $noperm = $this->staff(['ai_agent.use']);
        $this->withToken($this->token($noperm))->getJson("/api/v1/admin/customers/{$customer->id}/wallet")->assertForbidden();

        $adjuster = $this->staff(['customers.wallet.adjust']);
        $this->withToken($this->token($adjuster))->postJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments", [
            'kind' => 'adjustment', 'amount' => 25, 'direction' => 'credit', 'reason' => 'تسوية',
        ])->assertStatus(422); // Idempotency-Key required
        $created = $this->withToken($this->token($adjuster))->postJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments", [
            'kind' => 'adjustment', 'amount' => 25, 'direction' => 'credit', 'reason' => 'تسوية',
        ], ['Idempotency-Key' => 'wallet-ep-1'])->assertCreated();
        $adjId = $created->json('data.id');
        $this->assertSame(75.0, (float) $customer->fresh()->wallet_balance);

        $list = $this->withToken($this->token($viewer))->getJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments")->assertOk();
        $this->assertNotEmpty($list->json('data'));

        // Only the creator (or super-admin) may undo.
        $this->withToken($this->token($adjuster))->deleteJson("/api/v1/admin/wallet-adjustments/{$adjId}", [], ['Idempotency-Key' => 'wallet-ep-undo-1'])->assertCreated();
        $reversal = WalletAdjustment::query()->where('reverses_id', $adjId)->firstOrFail();
        $this->assertSame($adjId, $reversal->reverses_id);
        $this->assertSame(50.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_wallet_ledger_is_immutable(): void
    {
        $customer = Customer::factory()->create();
        $tx = CustomerWalletTransaction::query()->create(['customer_id' => $customer->id, 'type' => 'opening',
            'amount' => 10, 'balance_after' => 10, 'reference_type' => 'opening', 'reference_id' => $customer->id, 'created_at' => now()]);

        // Each attempt runs in its own savepoint: the trigger aborts only the
        // inner transaction, leaving the test transaction usable.
        foreach ([
            fn () => CustomerWalletTransaction::query()->whereKey($tx->id)->update(['amount' => 999]),
            fn () => CustomerWalletTransaction::query()->whereKey($tx->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('expected trigger rejection');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(10.0, (float) $tx->fresh()->amount);
    }

    public function test_cancel_wallet_order_reverses_wallet_but_keeps_treasury_untouched(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        app(WalletService::class)->record($customer->id, 150.0, 'opening', 'customer', $customer->id);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'wallet',
            'payment_status' => 'paid', 'grand_total' => 100]);
        app(WalletService::class)->record($customer->id, -100.0, 'checkout_payment', 'order', $order->id);
        $this->assertSame(50.0, (float) $customer->fresh()->wallet_balance);

        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled, $admin, 'test');

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(150.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, CustomerWalletTransaction::where('type', 'order_reversal')->count());
        $this->assertSame(0, \App\Models\TreasuryTransaction::query()
            ->whereIn('reference_type', ['order', \App\Models\Order::class])
            ->where('reference_id', $order->id)->count());
    }

    public function test_cancel_unpaid_cash_order_creates_no_movements(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['payment_method' => 'cash', 'payment_status' => 'unpaid', 'grand_total' => 100]);

        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled, $admin, 'test');

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(0, CustomerWalletTransaction::query()->where('reference_id', $order->id)->count());
        $this->assertSame(0, \App\Models\TreasuryTransaction::query()->where('reference_id', $order->id)->count());
    }

    public function test_backfill_repairs_null_wallet_types(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        CustomerWalletTransaction::query()->create(['customer_id' => $customer->id, 'type' => '',
            'amount' => 50, 'balance_after' => 50, 'reference_type' => 'manual', 'reference_id' => $customer->id, 'created_at' => now()]);
        CustomerWalletTransaction::query()->create(['customer_id' => $customer->id, 'type' => '',
            'amount' => -20, 'balance_after' => 30, 'reference_type' => 'order', 'reference_id' => $order->id, 'created_at' => now()]);

        $migration = require database_path('migrations/2026_11_10_000002_backfill_wallet_transaction_types.php');
        $migration->up();

        $this->assertSame('checkout_payment', CustomerWalletTransaction::query()->where('reference_type', 'order')->value('type'));
        $this->assertSame('opening', CustomerWalletTransaction::query()->where('reference_type', 'manual')->value('type'));
    }
}
