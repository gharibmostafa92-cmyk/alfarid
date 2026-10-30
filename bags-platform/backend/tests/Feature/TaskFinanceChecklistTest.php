<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SupplierInvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SupplierFinanceService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskFinanceChecklistTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_payment_replays_equivalent_decimals_but_rejects_changed_amount_or_supplier(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $other = Supplier::factory()->create();
        $service = app(SupplierFinanceService::class);
        $data = ['supplier_id' => $supplier->id, 'amount' => '5', 'payment_method' => 'cash', 'payment_date' => now()->toDateString()];
        $first = $service->createPayment($data, $actor, 'checklist-payment');
        foreach ([5.0, '5.00'] as $amount) {
            $this->assertSame($first->id, $service->createPayment([...$data, 'amount' => $amount], $actor, 'checklist-payment')->id);
        }
        foreach ([['amount' => '5.01'], ['supplier_id' => $other->id]] as $changes) {
            try {
                $service->createPayment([...$data, ...$changes], $actor, 'checklist-payment');
                $this->fail('Changed payment payload must conflict.');
            } catch (BusinessException $exception) {
                $this->assertSame(409, $exception->status);
            }
        }
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertDatabaseCount('operation_idempotency_keys', 1);
        $this->assertSame('-5.00', $supplier->fresh()->current_balance);
        $this->assertSame('0.00', $other->fresh()->current_balance);

        // Historical rows stored the class name rather than the result alias.
        DB::table('operation_idempotency_keys')->update(['result_type' => SupplierPayment::class]);
        $this->assertSame($first->id, $service->createPayment($data, $actor, 'checklist-payment')->id);
        // A removed result must never trigger another payment.
        DB::table('operation_idempotency_keys')->update(['result_id' => 999999999]);
        try {
            $service->createPayment($data, $actor, 'checklist-payment');
            $this->fail('A missing historical document must conflict.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertDatabaseCount('supplier_payments', 1);
    }

    public function test_generated_invoice_payment_void_and_allocation_sequences_conserve_supplier_balance(): void
    {
        $this->freezeTime();
        $this->seed(SettingSeeder::class);
        $actor = User::factory()->create();
        $service = app(SupplierFinanceService::class);
        for ($sample = 1; $sample <= 20; $sample++) {
            $supplier = Supplier::factory()->create(['opening_balance' => 17.25, 'current_balance' => 17.25]);
            $total = 100 + $sample * 1.13;
            $invoice = $service->createInvoice(['supplier_id' => $supplier->id, 'supplier_invoice_no' => 'PROPERTY-'.$sample, 'issue_date' => now()->toDateString(), 'subtotal' => $total]);
            $service->postInvoice($invoice);
            $check = function () use ($service, $supplier): void {
                $statement = $service->statement($supplier->fresh(), now()->subDay()->toDateString(), now()->toDateString());
                $this->assertEqualsWithDelta((float) $supplier->fresh()->current_balance, $statement['closing_balance'], 0.00001);
                $this->assertEqualsWithDelta($statement['opening_balance'] + $statement['total_credit'] - $statement['total_debit'], $statement['closing_balance'], 0.00001);
            };
            $check();
            $data = ['supplier_id' => $supplier->id, 'amount' => 40.01, 'payment_method' => 'cash', 'payment_date' => now()->toDateString()];
            $partial = $service->createPayment([...$data, 'supplier_invoice_id' => $invoice->id], $actor, 'partial-'.$sample);
            $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
            $check();
            $service->voidPayment($partial, $actor, 'checklist');
            $this->assertSame(SupplierInvoiceStatus::Posted, $invoice->fresh()->status);
            $check();
            $free = $service->createPayment([...$data, 'amount' => $total], $actor, 'free-'.$sample);
            $check();
            $service->allocatePayment($free, $invoice->id);
            $this->assertSame(SupplierInvoiceStatus::Paid, $invoice->fresh()->status);
            $check();
            $service->voidPayment($free, $actor, 'checklist');
            $check();
            $service->cancelInvoice($invoice->fresh());
            $check();
            $this->assertSame('17.25', $supplier->fresh()->current_balance);
        }
    }

    public function test_payment_before_statement_start_and_void_after_start_are_counted_once(): void
    {
        $this->travelTo(now()->startOfDay());
        $this->seed(SettingSeeder::class);
        $supplier = Supplier::factory()->create();
        $actor = User::factory()->create();
        $service = app(SupplierFinanceService::class);
        $payment = $service->createPayment(['supplier_id' => $supplier->id, 'amount' => 300, 'payment_method' => 'cash', 'payment_date' => now()->subDays(2)->toDateString()], $actor, 'before-period');
        $service->voidPayment($payment, $actor, 'checklist');
        $statement = $service->statement($supplier->fresh(), now()->subDay()->toDateString(), now()->toDateString());
        $this->assertSame(-300.0, $statement['opening_balance']);
        $this->assertSame(0.0, $statement['closing_balance']);
        $this->assertCount(1, $statement['entries']);
        $this->assertSame('payment_void', $statement['entries'][0]['type']);
    }
}
