<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Services\Finance\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WalletLedgerGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith([-100.01, 'adjustment', 2, 'test', 422, 'رصيد المحفظة لا يكفي قيمة الطلب كاملة.'])]
    #[TestWith([9999999900.00, 'adjustment', 2, 'test', 422, 'رصيد المحفظة يتجاوز الحد المسموح.'])]
    #[TestWith([10.00, 'adjustment', 1, 'test', 409, 'حركة المحفظة مسجلة سابقًا.'])]
    #[TestWith([-10.00, 'checkout_payment', 2, 'test', 422, 'الدفع بالمحفظة يتطلب حساب عميل نشطًا.'])]
    #[TestWith([10.00, 'refund', 7, 'order', 422, 'استرداد المحفظة يتم عبر مستند تسوية فقط.'])]
    public function test_rejected_entries_leave_balance_and_ledger_unchanged(
        float $amount,
        string $type,
        int $referenceId,
        string $referenceType,
        int $status,
        string $message,
    ): void {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Pending]);
        $wallet = app(WalletService::class);
        $wallet->record($customer->id, 100, 'adjustment', 'test', 1);

        try {
            $wallet->record($customer->id, $amount, $type, $referenceType, $referenceId);
            $this->fail('Expected wallet entry to be rejected.');
        } catch (BusinessException $exception) {
            $this->assertSame($status, $exception->status);
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame('100.00', $customer->fresh()->wallet_balance);
        $this->assertSame(1, CustomerWalletTransaction::query()->where('customer_id', $customer->id)->count());
    }

    public function test_legacy_order_reference_cannot_be_replayed_with_the_alias(): void
    {
        $customer = Customer::factory()->create(['wallet_balance' => 100]);
        $entry = [
            'customer_id' => $customer->id,
            'reference_id' => 7,
            'reference_type' => Order::class,
            'type' => 'order_reversal',
            'amount' => 100,
            'balance_after' => 100,
        ];
        DB::table('customer_wallet_transactions')->insert($entry);

        try {
            app(WalletService::class)->record($customer->id, 100, 'order_reversal', 'order', 7);
            $this->fail('Expected a duplicate wallet entry conflict.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame('100.00', $customer->fresh()->wallet_balance);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        DB::transaction(fn () => DB::table('customer_wallet_transactions')->insert([
            ...$entry,
            'reference_type' => 'order',
        ]));
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_postgres_prevents_mutating_existing_ledger_entries(string $operation): void
    {
        $customer = Customer::factory()->create();
        $entry = app(WalletService::class)->record($customer->id, 100, 'adjustment', 'test', 1);
        $query = DB::table('customer_wallet_transactions')->where('id', $entry->id);

        try {
            DB::transaction(fn () => match ($operation) {
                'update' => $query->update(['amount' => 99]),
                'delete' => $query->delete(),
            });
            $this->fail('Expected PostgreSQL to reject the ledger mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0]);
            $this->assertStringContainsString('Wallet ledger is append-only', $exception->getMessage());
        }

        $this->assertSame('100.00', $entry->fresh()->amount);
        $this->assertSame('100.00', $customer->fresh()->wallet_balance);
    }

    public function test_postgres_rejects_negative_wallet_balances(): void
    {
        $customer = Customer::factory()->create();

        foreach ([
            'customers_wallet_nonnegative' => fn () => DB::table('customers')
                ->where('id', $customer->id)->update(['wallet_balance' => -0.01]),
            'wallet_balance_nonnegative' => fn () => DB::table('customer_wallet_transactions')->insert([
                'customer_id' => $customer->id, 'type' => 'adjustment', 'amount' => -0.01,
                'balance_after' => -0.01, 'reference_type' => 'test', 'reference_id' => 1,
            ]),
        ] as $constraint => $write) {
            try {
                DB::transaction($write);
                $this->fail("Expected constraint {$constraint} to reject the write.");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }

        $this->assertSame('0.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseMissing('customer_wallet_transactions', ['customer_id' => $customer->id]);
    }
}
