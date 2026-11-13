<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryTransferStatus;
use App\Enums\Finance\TreasuryType;
use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Finance\WalletAdjustmentService;
use App\Services\Finance\WalletService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task8Slice3WalletAndIdempotencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    private function makeStaff(int $branchId, array $permissions, bool $superAdmin = false): User
    {
        $u = User::factory()->create(['branch_id' => $branchId]);
        foreach ($permissions as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        if ($superAdmin) {
            $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));
        }

        return $u;
    }

    public function test_f12_customer_wallet_transaction_blocks_mass_assignment_of_id_via_fillable(): void
    {
        $model = new CustomerWalletTransaction([
            'id' => 999999,
            'customer_id' => 1,
            'type' => 'opening',
            'amount' => '10.00',
            'balance_after' => '10.00',
        ]);

        $this->assertNotEmpty($model->getFillable());
        $this->assertFalse($model->isFillable('id'));
        $this->assertNull($model->id);
    }

    public function test_f08_wallet_service_enforces_type_whitelist_and_zero_sign_consistency(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Approved]);
        $wallet = app(WalletService::class);

        try {
            $wallet->record($customer->id, 50, 'invalid_random_type', 'test', 1);
            $this->fail('Expected unknown wallet transaction type to be rejected.');
        } catch (BusinessException) {
        }

        try {
            $wallet->record($customer->id, -10, 'topup', 'test', 2);
            $this->fail('Expected negative topup to be rejected.');
        } catch (BusinessException) {
        }

        foreach (['checkout_payment' => 'order', 'return_refund' => 'return_request'] as $type => $reference) {
            try {
                $wallet->record($customer->id, 0, $type, $reference, 501);
                $this->fail("Expected a zero {$type} to be rejected.");
            } catch (BusinessException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
    }

    public function test_f08_wallet_adjustment_blocks_suspended_or_rejected_customer_and_reverse_locks_db_row(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $creator = $this->makeStaff($branch->id, ['customers.wallet.adjust'], false);
        $attacker = $this->makeStaff($branch->id, ['customers.wallet.adjust'], false);
        $service = app(WalletAdjustmentService::class);

        foreach ([CustomerStatus::Suspended, CustomerStatus::Rejected] as $blockedStatus) {
            $blockedCustomer = Customer::factory()->create(['status' => $blockedStatus]);
            try {
                $service->adjust([
                    'customer_id' => $blockedCustomer->id,
                    'kind' => 'adjustment',
                    'amount' => '25.00',
                    'direction' => 'credit',
                    'reason' => 'تعديل محظور',
                ], $creator, 'blk-'.$blockedStatus->value);
                $this->fail("Expected adjustment for {$blockedStatus->value} customer to be rejected.");
            } catch (BusinessException) {
            }
        }

        $approved = Customer::factory()->create(['status' => CustomerStatus::Approved]);
        $adj = $service->adjust([
            'customer_id' => $approved->id,
            'kind' => 'adjustment',
            'amount' => '40.00',
            'direction' => 'credit',
            'reason' => 'تسوية',
        ], $creator, 'adj-lock-1');

        // Attacker passes an in-memory stale model whose created_by was tampered to attacker->id;
        // reverse() must lock & verify against DB row, rejecting attacker with 403.
        $stale = $adj->replicate();
        $stale->id = $adj->id;
        $stale->exists = true;
        $stale->created_by = $attacker->id;

        try {
            $service->reverse($stale, $attacker, 'rev-attacker-1');
            $this->fail('Expected attacker reverse attempt on stale copy to return 403.');
        } catch (BusinessException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function test_f08_shift_reject_reopen_force_close_and_transfer_reject_honor_idempotency_key(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = $this->makeStaff($branch->id, ['pos.access']);
        $manager = $this->makeStaff($branch->id, [
            'pos_shifts.view',
            'pos_shifts.approve',
            'pos_shifts.force_close',
            'treasury_transfers.approve',
        ]);
        $manager->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));

        $drawer = Treasury::create([
            'name' => 'Drawer 1',
            'code' => 'DRW-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer,
            'is_active' => true,
        ]);
        $safe = Treasury::create([
            'name' => 'Safe 1',
            'code' => 'SF-'.Str::uuid(),
            'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe,
            'is_active' => true,
        ]);

        $pendingShift = PosShift::create([
            'shift_number' => 'SH-IDEMP-1',
            'branch_id' => $branch->id,
            'user_id' => $cashier->id,
            'treasury_id' => $drawer->id,
            'status' => PosShiftStatus::ClosedPendingApproval,
            'opened_at' => now()->subHours(2),
            'closed_at' => now(),
            'opening_cash' => '0.00',
            'actual_closing_cash' => '0.00',
        ]);

        Auth::forgetGuards();
        $token = $manager->createToken('t')->plainTextToken;

        // 1. Shift reject idempotency replay
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$pendingShift->id}/reject", ['reason' => 'إعادة جرد'], ['Idempotency-Key' => 'sh-reject-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$pendingShift->id}/reject", ['reason' => 'إعادة جرد'], ['Idempotency-Key' => 'sh-reject-1'])
            ->assertOk();

        // 2. Shift reopen idempotency replay (move back to pending first)
        $pendingShift->refresh();
        $pendingShift->forceFill(['status' => PosShiftStatus::ClosedPendingApproval, 'closed_at' => now()])->save();
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$pendingShift->id}/reopen", ['reason' => 'فتح مرة أخرى'], ['Idempotency-Key' => 'sh-reopen-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$pendingShift->id}/reopen", ['reason' => 'فتح مرة أخرى'], ['Idempotency-Key' => 'sh-reopen-1'])
            ->assertOk();

        // 3. Shift force-close idempotency replay
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$pendingShift->id}/force-close", [
                'actual_closing_cash' => '0.00',
                'reason' => 'غياب الكاشير',
            ], ['Idempotency-Key' => 'sh-fc-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed_pending_approval');
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$pendingShift->id}/force-close", [
                'actual_closing_cash' => '0.00',
                'reason' => 'غياب الكاشير',
            ], ['Idempotency-Key' => 'sh-fc-1'])
            ->assertOk();

        // 4. Transfer reject idempotency replay
        $transfer = TreasuryTransfer::create([
            'transfer_number' => 'TT-IDEMP-1',
            'from_treasury_id' => $safe->id,
            'to_treasury_id' => $drawer->id,
            'amount' => '100.00',
            'status' => TreasuryTransferStatus::Pending,
            'created_by' => $cashier->id,
        ]);

        // Settle/close shift isn't needed for rejectTransfer; test direct reject idempotency.
        $this->withToken($token)
            ->postJson("/api/v1/admin/treasury-transfers/{$transfer->id}/reject", ['reason' => 'رفض مكرر'], ['Idempotency-Key' => 'tr-reject-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->withToken($token)
            ->postJson("/api/v1/admin/treasury-transfers/{$transfer->id}/reject", ['reason' => 'رفض مكرر'], ['Idempotency-Key' => 'tr-reject-1'])
            ->assertOk();
    }
}
