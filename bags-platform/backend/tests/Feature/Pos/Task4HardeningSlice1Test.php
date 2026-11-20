<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\TreasuryType;
use App\Enums\Inventory\StocktakeStatus;
use App\Enums\Inventory\TransferStatus;
use App\Models\Branch;
use App\Models\PosShift;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task4HardeningSlice1Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_high_01_pos_stocktake_and_transfer_routes_require_explicit_permissions(): void
    {
        $branch = Branch::create([
            'name' => 'Main Branch',
            'code' => 'BR-MAIN',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $otherBranch = Branch::create([
            'name' => 'Donor Branch',
            'code' => 'BR-DONOR',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $posAccess = Permission::findOrCreate('pos.access', 'staff');

        $cashierOnly = User::create([
            'name' => 'Limited Cashier',
            'email' => 'limited.cashier@example.com',
            'phone' => '+201099887701',
            'password' => Hash::make('secret123'),
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $cashierOnly->givePermissionTo($posAccess);

        $treasury = Treasury::create([
            'name' => 'POS Drawer',
            'code' => 'TR-POS-T4',
            'type' => TreasuryType::PosDrawer,
            'branch_id' => $branch->id,
            'is_active' => true,
            'current_balance' => 0,
        ]);
        PosShift::create([
            'shift_number' => 'SH-T4-001',
            'branch_id' => $branch->id,
            'user_id' => $cashierOnly->id,
            'treasury_id' => $treasury->id,
            'opening_cash' => 100,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $stocktake = Stocktake::create([
            'stocktake_number' => 'STK-T4-001',
            'branch_id' => $branch->id,
            'status' => StocktakeStatus::InProgress,
            'type' => 'full',
            'started_at' => now(),
            'created_by' => $cashierOnly->id,
        ]);

        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-T4-001',
            'from_branch_id' => $otherBranch->id,
            'to_branch_id' => $branch->id,
            'status' => TransferStatus::Shipped,
            'created_by' => $cashierOnly->id,
            'shipped_at' => now(),
        ]);

        $token = $cashierOnly->createToken('pos', ['pos'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/pos/stocktakes/current')->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/pos/stocktakes/{$stocktake->id}/scan", ['barcode' => 'NONE'])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/pos/transfers/incoming')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/pos/transfer-sources')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/pos/transfer-requests', [])->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/pos/transfers/{$transfer->id}/receive", [])->assertForbidden();
    }

    public function test_medium_06_ensure_staff_authenticated_falls_back_to_db_when_cache_throws(): void
    {
        $activeBranch = Branch::create([
            'name' => 'Active Branch',
            'code' => 'BR-ACT',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $inactiveBranch = Branch::create([
            'name' => 'Inactive Branch',
            'code' => 'BR-INA',
            'type' => 'branch',
            'is_active' => false,
        ]);

        $posAccess = Permission::findOrCreate('pos.access', 'staff');

        $activeStaff = User::create([
            'name' => 'Active Staff',
            'email' => 'active.staff@example.com',
            'phone' => '+201099887702',
            'password' => Hash::make('secret123'),
            'branch_id' => $activeBranch->id,
            'is_active' => true,
        ]);
        $activeStaff->givePermissionTo($posAccess);

        $inactiveStaff = User::create([
            'name' => 'Inactive Branch Staff',
            'email' => 'inactive.staff@example.com',
            'phone' => '+201099887703',
            'password' => Hash::make('secret123'),
            'branch_id' => $inactiveBranch->id,
            'is_active' => true,
        ]);
        $inactiveStaff->givePermissionTo($posAccess);

        $activeToken = $activeStaff->createToken('pos', ['pos'])->plainTextToken;
        $inactiveToken = $inactiveStaff->createToken('pos', ['pos'])->plainTextToken;

        $failingCache = \Mockery::mock(CacheRepository::class)->shouldIgnoreMissing();
        $failingCache->shouldReceive('remember')
            ->andThrow(new \RuntimeException('OOM command not allowed when used memory > maxmemory'));
        Cache::swap($failingCache);

        $this->withToken($activeToken)->getJson('/api/v1/pos/bootstrap')->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($inactiveToken)->getJson('/api/v1/pos/bootstrap')->assertForbidden();
    }

    public function test_low_01_supplier_token_minutes_defaults_to_60_days(): void
    {
        $this->assertSame(86400, config('auth.supplier_token_minutes'));
    }
}
