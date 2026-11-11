<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\TreasuryTransactionType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\WalletService;
use App\Services\Pos\TreasuryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialDatabaseIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deferred_ledger_checks_verify_the_chain_and_stored_balance(): void
    {
        $customer = Customer::factory()->create();
        $treasury = Treasury::create(['name' => 'Bank', 'code' => 'BANK-LEDGER', 'type' => 'bank_account', 'is_active' => true]);
        $staff = User::factory()->create();

        app(WalletService::class)->record($customer->id, 100, 'adjustment', 'test', 1);
        app(WalletService::class)->record($customer->id, -30, 'adjustment', 'test', 2);
        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id, 'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '-20.00', 'created_by' => $staff->id,
        ]);

        $this->flushConstraints();

        $this->assertCheckViolation('Stored wallet balance differs', function () use ($customer): void {
            $customer->newQuery()->whereKey($customer->id)->update(['wallet_balance' => 71]);
        });

        $this->assertCheckViolation('Invalid wallet ledger balance', function () use ($customer): void {
            DB::table('customer_wallet_transactions')->insert([
                'customer_id' => $customer->id, 'type' => 'adjustment', 'amount' => 5,
                'balance_after' => 80, 'reference_type' => 'test', 'reference_id' => 3,
            ]);
            $customer->newQuery()->whereKey($customer->id)->update(['wallet_balance' => 80]);
        });

        $this->assertCheckViolation('Stored treasury balance differs', function () use ($treasury, $staff): void {
            DB::table('treasury_transactions')->insert([
                'treasury_id' => $treasury->id, 'type' => 'customer_receipt',
                'amount' => 5, 'balance_after' => -15, 'created_by' => $staff->id,
            ]);
        });
    }

    public function test_allocation_caps_cover_linked_payments_and_preserve_cancelled_order_credit(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale']);
        $first = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'credit']);
        $second = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'credit']);
        $notice = $this->createNotice($customer, 'FREE', 60);
        $linked = $this->createNotice($customer, 'LINKED', 50, $first);

        $this->allocate($notice, $first, 50);
        $this->flushConstraints();

        $this->assertCheckViolation('exceed available credit for notice', fn () => $this->allocate($notice, $second, 11));
        $this->assertCheckViolation('exceed the remaining balance for order', function () use ($linked): void {
            DB::table('wholesale_payment_notices')->where('id', $linked)->update(['amount' => 51]);
        });
        $this->assertCheckViolation('exceed available credit for notice', function () use ($linked): void {
            DB::table('wholesale_payment_notices')->where('id', $linked)->update(['amount' => -1]);
        });
        $this->assertCheckViolation('exceed the remaining balance for order', function () use ($first): void {
            $first->newQuery()->whereKey($first->id)->update(['subtotal' => 99, 'grand_total' => 99]);
        });

        $first->update(['status' => 'cancelled']);
        $this->allocate($notice, $second, 60);
        $this->flushConstraints();

        $this->assertCheckViolation('exceed available credit for notice', function () use ($first): void {
            $first->newQuery()->whereKey($first->id)->update(['status' => 'pending']);
        });
    }

    public function test_order_total_must_equal_its_priced_components(): void
    {
        $order = Order::factory()->create();

        $this->assertCheckViolation('orders_total_formula', function () use ($order): void {
            $order->newQuery()->whereKey($order->id)->update(['grand_total' => 40]);
        });
    }

    public function test_wallet_ledger_rejects_non_finite_amounts(): void
    {
        $customer = Customer::factory()->create();

        $this->assertCheckViolation('Invalid wallet ledger balance', function () use ($customer): void {
            DB::statement(
                "INSERT INTO customer_wallet_transactions (customer_id, type, amount, balance_after, reference_type, reference_id) VALUES (?, 'adjustment', 'NaN'::numeric, 0, 'test', 9)",
                [$customer->id],
            );
            $this->flushConstraints();
        });
    }

    public function test_wholesale_notices_prevent_permanent_customer_deletion(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale']);
        $this->createNotice($customer, 'RETAIN', 60);

        try {
            DB::transaction(fn () => $customer->forceDelete());
            $this->fail('Expected PostgreSQL to retain the wholesale notice.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], ['23001', '23503']);
            $this->assertStringContainsString('wholesale_payment_notices_customer_id_foreign', $exception->getMessage());
        }
    }

    private function createNotice(Customer $customer, string $number, int $amount, ?Order $order = null): int
    {
        return DB::table('wholesale_payment_notices')->insertGetId([
            'notice_number' => $number, 'customer_id' => $customer->id, 'order_id' => $order?->id,
            'amount' => $amount, 'payment_method' => 'bank_transfer', 'bank_name' => 'Test Bank',
            'reference_number' => $number, 'transfer_date' => '2026-11-11',
            'receipt_file_path' => 'test-receipt.pdf', 'status' => 'approved',
        ]);
    }

    private function allocate(int $notice, Order $order, int $amount): void
    {
        DB::table('wholesale_payment_allocations')->insert([
            'notice_id' => $notice, 'order_id' => $order->id, 'amount' => $amount,
        ]);
    }

    private function assertCheckViolation(string $message, callable $write): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                $this->flushConstraints();
            });
            $this->fail('Expected PostgreSQL to reject the invalid financial write.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function flushConstraints(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }
}
