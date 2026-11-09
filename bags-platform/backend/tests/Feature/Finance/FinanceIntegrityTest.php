<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\StaffRole;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FinanceReconcileRun;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\Finance\FinanceWelcome;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;
use App\Services\Finance\WalletAdjustmentService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 5.1: nightly ledger integrity check + tool + morning card. */
class FinanceIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_finance_tools_enabled',
            'ai_action_ttl_minutes',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.finance', 'ai_agent.execute', 'finance.reports.view', 'treasuries.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function treasury(Branch $branch): Treasury
    {
        return Treasury::create([
            'name' => 'خزينة '.Str::random(6), 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => '0.00', 'is_active' => true,
        ]);
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_balanced_ledgers_reconcile_clean(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id, 'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '1000.00', 'created_by' => $admin->id,
        ]);
        $customer = Customer::factory()->create();
        app(WalletAdjustmentService::class)->adjust([
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '250.00',
            'direction' => 'credit', 'reason' => 'رصيد افتتاحي', 'source' => 'admin',
        ], $admin, 'fi-seed-1');

        $this->assertSame(0, Artisan::call('finance:reconcile'));
        $run = FinanceReconcileRun::query()->orderByDesc('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(0, $run->problem_count);
        $this->assertGreaterThan(0, $run->checked);

        $out = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'ledger_integrity_check', 'args' => []]);
        $this->assertSame('data', $out->type);
        $this->assertTrue($out->data['has_run']);
        $this->assertSame(0, $out->data['problem_count']);
    }

    public function test_tampered_balance_is_reported_with_links(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id, 'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '1000.00', 'created_by' => $admin->id,
        ]);
        DB::table('treasuries')->where('id', $treasury->id)->update(['current_balance' => '1.00']);

        $this->assertSame(1, Artisan::call('finance:reconcile'));
        $run = FinanceReconcileRun::query()->orderByDesc('id')->first();
        $this->assertSame(1, $run->problem_count);

        $out = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'ledger_integrity_check', 'args' => []]);
        $this->assertSame('data', $out->type);
        $this->assertSame(1, $out->data['problem_count']);
        $this->assertNotEmpty($out->data['samples']);
        $this->assertSame('checklist', $out->uiBlock['type']);

        $card = app(FinanceWelcome::class)->card($admin->fresh());
        $this->assertNotNull($card);
        $labels = array_column($card['rows'], 'label');
        $this->assertTrue(collect($labels)->contains(fn ($l) => str_contains((string) $l, 'فحص سلامة')));
    }

    public function test_branch_scoped_run_ignores_other_branches(): void
    {
        $admin = $this->admin();
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);
        $treasuryA = $this->treasury($branchA);
        app(TreasuryService::class)->record([
            'treasury_id' => $treasuryA->id, 'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '500.00', 'created_by' => $admin->id,
        ]);
        DB::table('treasuries')->where('id', $treasuryA->id)->update(['current_balance' => '2.00']);

        $this->assertSame(0, Artisan::call('finance:reconcile', ['--branch_id' => $branchB->id]));
        $run = FinanceReconcileRun::query()->orderByDesc('id')->first();
        $this->assertSame($branchB->id, $run->branch_id);
        $this->assertSame(0, $run->problem_count);
    }
}
