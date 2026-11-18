<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\FinanceReconcileRun;
use App\Models\User;
use App\Services\Finance\WalletAdjustmentService;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MEDIUM-05: the nightly wallet audit must scan customers in bounded chunks so
 * a growing ledger cannot exhaust the statement budget as one unbounded query.
 */
class Task2ReconcileFinanceChunkingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
    }

    public function test_wallet_reconciliation_runs_in_bounded_chunks_and_detects_tampering(): void
    {
        $admin = User::factory()->create();
        $customers = Customer::factory()->count(5)->create(['wallet_balance' => '0.00']);
        $target = $customers->last();

        app(WalletAdjustmentService::class)->adjust([
            'customer_id' => $target->id,
            'kind' => 'adjustment',
            'amount' => '100.00',
            'direction' => 'credit',
            'reason' => 'رصيد افتتاحي',
            'source' => 'admin',
        ], $admin, 'chunk-seed-1');

        DB::table('customers')->where('id', $target->id)->update(['wallet_balance' => '250.00']);

        $exit = Artisan::call('finance:reconcile', ['--wallet-chunk' => 2]);

        $this->assertSame(1, $exit);
        $run = FinanceReconcileRun::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $run->problem_count);
        $this->assertGreaterThanOrEqual(5, $run->checked);
        $this->assertSame('wallet_balance', $run->samples[0]['issue'] ?? null);
    }
}
