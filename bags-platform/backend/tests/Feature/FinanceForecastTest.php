<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TreasuryType;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\ExpenseService;
use App\Services\SettingService;
use App\Services\TreasuryService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 5.3: cash forecast what-if math lives in PHP, never in the model. */
class FinanceForecastTest extends TestCase
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
        foreach (['ai_agent.use', 'ai_agent.finance', 'treasuries.view', 'treasury_transfers.view', 'expenses.view', 'finance.reports.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function treasury(Branch $branch, string $balance): Treasury
    {
        return Treasury::create([
            'name' => 'خزينة '.Str::random(6), 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => $balance, 'is_active' => true,
        ]);
    }

    public function test_projected_balances_and_coverage_days(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $from = $this->treasury($branch, '2000.00');
        $to = $this->treasury($branch, '200.00');
        $transfer = app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '300.00',
        ], $admin->id);

        for ($i = 0; $i < 10; $i++) {
            app(ExpenseService::class)->create([
                'branch_id' => $branch->id, 'treasury_id' => $from->id,
                'category' => 'كهرباء', 'amount' => '100.00',
                'expense_date' => now()->subDays($i)->toDateString(),
            ], $admin, 'fc-'.$i.'-'.Str::uuid()->toString());
        }

        $out = app(ToolRegistry::class)->dispatch($admin, AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']), [
            'name' => 'cash_forecast', 'args' => ['branch_id' => $branch->id, 'window_days' => 10],
        ]);
        $this->assertSame('data', $out->type);
        $this->assertSame([$transfer->id], $out->data['simulated_transfers']);

        $rows = collect($out->data['treasuries'])->keyBy('treasury.untrusted_text');
        // 1000 out 300 → 700; 200 in 300 → 500. Daily avg = 1000/10 = 100.
        $this->assertSame(700.0, $rows[$from->name]['projected']);
        $this->assertSame(500.0, $rows[$to->name]['projected']);
        $this->assertSame(100.0, $rows[$from->name]['daily_expense_avg']);
        $this->assertSame(7.0, $rows[$from->name]['coverage_days']);
        $this->assertSame(5.0, $rows[$to->name]['coverage_days']);
        $this->assertSame('table', $out->uiBlock['type']);
    }

    public function test_selected_transfers_only(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $from = $this->treasury($branch, '1000.00');
        $mid = $this->treasury($branch, '0.00');
        $t1 = app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $mid->id, 'amount' => '100.00',
        ], $admin->id);
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $mid->id, 'amount' => '400.00',
        ], $admin->id);

        $out = app(ToolRegistry::class)->dispatch($admin, AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']), [
            'name' => 'cash_forecast', 'args' => ['branch_id' => $branch->id, 'transfer_ids' => [$t1->id]],
        ]);
        $this->assertSame('data', $out->type);
        $this->assertSame([$t1->id], $out->data['simulated_transfers']);
        $rows = collect($out->data['treasuries'])->keyBy('treasury.untrusted_text');
        $this->assertSame(900.0, $rows[$from->name]['projected']);
        $this->assertSame(100.0, $rows[$mid->name]['projected']);
    }
}
