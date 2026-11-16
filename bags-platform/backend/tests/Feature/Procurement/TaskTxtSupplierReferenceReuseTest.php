<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * F-04: a voided payment releases its reference number for reuse, while the
 * database partial unique index still blocks concurrent active duplicates.
 */
class TaskTxtSupplierReferenceReuseTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_a_voided_bank_reference_can_be_reused(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Super Admin');
        $supplier = Supplier::factory()->create(['current_balance' => 1000]);
        $treasury = Treasury::create([
            'name' => 'Bank', 'code' => 'F04-BANK', 'type' => 'bank_account',
            'branch_id' => null, 'is_active' => true,
        ]);
        $treasury->current_balance = '1000.00';
        $treasury->save();
        $service = app(SupplierFinanceService::class);

        $first = $service->createPayment([
            'supplier_id' => $supplier->id,
            'treasury_id' => $treasury->id,
            'amount' => 100,
            'payment_method' => 'bank_transfer',
            'reference_no' => 'TRX-99881',
            'payment_date' => now()->toDateString(),
        ], $actor);

        $service->voidPayment($first, $actor, 'reissued');

        $second = $service->createPayment([
            'supplier_id' => $supplier->id,
            'treasury_id' => $treasury->id,
            'amount' => 50,
            'payment_method' => 'bank_transfer',
            'reference_no' => 'TRX-99881',
            'payment_date' => now()->toDateString(),
        ], $actor);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('TRX-99881', $second->reference_no);
        $this->assertNotNull($first->fresh()->voided_at);
        $this->assertNull($second->fresh()->voided_at);
    }

    public function test_payment_method_cannot_be_changed_on_a_historical_payment(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Super Admin');
        $supplier = Supplier::factory()->create();
        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $supplier->id,
            'treasury_id' => null,
            'payment_method' => 'bank_transfer',
            'reference_no' => 'LEGACY-REF-1',
        ]);

        $this->withToken($actor->createToken('historical-payment')->plainTextToken)
            ->putJson("/api/v1/admin/supplier-payments/{$payment->id}", ['payment_method' => 'cash'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('supplier_payments', [
            'id' => $payment->id,
            'payment_method' => 'bank_transfer',
            'reference_no' => 'LEGACY-REF-1',
        ]);
    }
}
