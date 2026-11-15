<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\StaffRole;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryTransferStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Finance\WalletAdjustmentService;
use App\Services\Finance\WalletService;
use App\Services\Pos\TreasuryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task2Slice2SecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_critical_02_treasury_controller_index_rejects_cross_branch_with_403(): void
    {
        $this->seed([\Database\Seeders\SettingSeeder::class, \Database\Seeders\FinancialPermissionSeeder::class]);

        $branch1 = Branch::query()->firstOrCreate(['code' => 'T2_B1'], ['name' => 'Branch 1', 'is_active' => true]);
        $branch2 = Branch::query()->firstOrCreate(['code' => 'T2_B2'], ['name' => 'Branch 2', 'is_active' => true]);

        $user = User::factory()->create([
            'branch_id' => $branch1->id,
            'is_active' => true,
        ]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('branch_manager', 'staff');
        $role->givePermissionTo('treasuries.view');
        $user->assignRole($role);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/treasuries?branch_id={$branch2->id}");

        $response->assertStatus(403);
    }

    public function test_high_06_wallet_service_record_catches_unique_violation_as_409(): void
    {
        $customer1 = Customer::factory()->create(['wallet_balance' => '0.00', 'status' => \App\Enums\CustomerStatus::Approved]);
        $customer2 = Customer::factory()->create(['wallet_balance' => '0.00', 'status' => \App\Enums\CustomerStatus::Approved]);
        $order = Order::factory()->create(['customer_id' => $customer1->id]);

        $wallet = app(WalletService::class);
        $user = User::factory()->create();

        // Top up customer 1 and 2 first so ledger trigger is happy
        $wallet->record($customer1->id, '500.00', 'adjustment', 'order', random_int(100000, 999999), $user->id);
        $wallet->record($customer2->id, '500.00', 'adjustment', 'order', random_int(100000, 999999), $user->id);

        // Record for customer 1
        $wallet->record($customer1->id, '-100.00', 'checkout_payment', 'order', $order->id);

        // Record duplicate for same customer on same order with conflicting amount triggers 409
        $this->expectException(BusinessException::class);
        $this->expectExceptionCode(409);

        $wallet->record($customer1->id, '-50.00', 'checkout_payment', 'order', $order->id);
    }

    public function test_high_06_wallet_service_record_rejects_zero_checkout_payment(): void
    {
        $customer = Customer::factory()->create(['wallet_balance' => '0.00', 'status' => \App\Enums\CustomerStatus::Approved]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $wallet = app(WalletService::class);

        $this->expectException(BusinessException::class);
        $this->expectExceptionCode(422);

        $wallet->record($customer->id, '0.00', 'checkout_payment', 'order', $order->id);
    }

    public function test_medium_06_transfer_version_is_null_safe_and_hashes_financial_fields(): void
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'T2_MV'], ['name' => 'Branch MV', 'is_active' => true]);
        $t1 = Treasury::query()->create(['code' => 'T2_MV1_'.uniqid(), 'name' => 'T1', 'type' => 'main_safe', 'branch_id' => $branch->id, 'current_balance' => '1000.00', 'is_active' => true]);
        $t2 = Treasury::query()->create(['code' => 'T2_MV2_'.uniqid(), 'name' => 'T2', 'type' => 'main_safe', 'branch_id' => $branch->id, 'current_balance' => '1000.00', 'is_active' => true]);

        $user = User::factory()->create();

        $transfer = TreasuryTransfer::query()->forceCreate([
            'transfer_number' => 'TR-'.uniqid(),
            'from_treasury_id' => $t1->id,
            'to_treasury_id' => $t2->id,
            'amount' => '100.00',
            'status' => TreasuryTransferStatus::Pending,
            'created_by' => $user->id,
            'updated_at' => null, // null updated_at should not crash
        ]);

        $version1 = FinanceWriteGuards::transferVersion($transfer);
        $this->assertIsString($version1);
        $this->assertSame(64, strlen($version1)); // sha256 hash length

        // Changing amount must change version even within same second
        $transfer->amount = '200.00';
        $version2 = FinanceWriteGuards::transferVersion($transfer);
        $this->assertNotSame($version1, $version2);
    }

    public function test_high_03_wallet_adjustment_reverse_is_idempotent(): void
    {
        $customer = Customer::factory()->create(['wallet_balance' => '0.00', 'status' => \App\Enums\CustomerStatus::Approved]);
        $user = User::factory()->create();

        $service = app(WalletAdjustmentService::class);
        $adj = $service->adjust([
            'customer_id' => $customer->id,
            'kind' => 'adjustment',
            'amount' => '100.00',
            'direction' => 'credit',
            'reason' => 'Test credit',
            'source' => 'admin',
        ], $user, 'adj-key-'.uniqid());

        $this->assertSame('100.00', (string) $customer->fresh()->wallet_balance);

        // Reverse
        $rev = $service->reverse($adj, $user, 'rev-key-'.uniqid());
        $this->assertSame('0.00', (string) $customer->fresh()->wallet_balance);

        // Reversing again should throw 409
        $this->expectException(BusinessException::class);
        $this->expectExceptionCode(409);

        $service->reverse($adj, $user, 'rev-key-second');
    }

    public function test_critical_02_treasury_service_delete_account_checks_transfers(): void
    {
        $branch = Branch::query()->firstOrCreate(['code' => 'T2_DEL'], ['name' => 'Branch Del', 'is_active' => true]);
        $t1 = Treasury::query()->create(['code' => 'T2_DEL1_'.uniqid(), 'name' => 'Del 1', 'type' => 'main_safe', 'branch_id' => $branch->id, 'current_balance' => '0.00', 'is_active' => true]);
        $t2 = Treasury::query()->create(['code' => 'T2_DEL2_'.uniqid(), 'name' => 'Del 2', 'type' => 'main_safe', 'branch_id' => $branch->id, 'current_balance' => '0.00', 'is_active' => true]);

        $user = User::factory()->create(['branch_id' => $branch->id]);

        TreasuryTransfer::query()->forceCreate([
            'transfer_number' => 'TR-DEL-'.uniqid(),
            'from_treasury_id' => $t1->id,
            'to_treasury_id' => $t2->id,
            'amount' => '50.00',
            'status' => TreasuryTransferStatus::Pending,
            'created_by' => $user->id,
        ]);

        $service = app(TreasuryService::class);

        $this->expectException(BusinessException::class);
        $service->deleteAccount($t1, $user);
    }
}
