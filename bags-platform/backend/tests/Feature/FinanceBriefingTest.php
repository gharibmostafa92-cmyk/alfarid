<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PosShiftStatus;
use App\Enums\StaffRole;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Pos\ExpenseService;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 5.4: daily close briefing summary, shifts, shortages, quick actions. */
class FinanceBriefingTest extends TestCase
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
        foreach (['ai_agent.use', 'ai_agent.execute', 'ai_agent.finance', 'treasuries.view', 'treasury_transfers.view', 'expenses.view', 'finance.reports.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    public function test_briefing_summarizes_day_with_actions(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $safe = Treasury::create([
            'name' => 'رئيسية', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true,
        ]);
        $drawer = Treasury::create([
            'name' => 'درج 1', 'code' => 'POS-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => '2500.00', 'is_active' => true,
        ]);
        app(TreasuryService::class)->record([
            'treasury_id' => $safe->id, 'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '5000.00', 'created_by' => $admin->id,
        ]);
        Order::factory()->create([
            'allocated_branch_id' => $branch->id, 'payment_method' => 'cash',
            'payment_status' => 'paid', 'grand_total' => '400.00',
        ]);
        Order::factory()->create([
            'allocated_branch_id' => $branch->id, 'payment_method' => 'card',
            'payment_status' => 'paid', 'grand_total' => '999.00',
        ]);
        app(ExpenseService::class)->create([
            'branch_id' => $branch->id, 'treasury_id' => $safe->id, 'category' => 'كهرباء',
            'amount' => '150.00', 'expense_date' => now()->toDateString(),
        ], $admin, 'brief-1-'.Str::uuid()->toString());
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $drawer->id, 'to_treasury_id' => $safe->id, 'amount' => '500.00',
        ], $admin->id);
        PosShift::create([
            'shift_number' => 'SHIFT-1', 'user_id' => $admin->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer->id, 'status' => PosShiftStatus::ClosedPendingApproval,
            'opened_at' => now()->subHours(8), 'expected_closing_cash' => '1000.00',
            'actual_closing_cash' => '990.00', 'cash_difference' => '-10.00',
        ]);
        $drawer2 = Treasury::create([
            'name' => 'درج 2', 'code' => 'POS-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::PosDrawer, 'current_balance' => '500.00', 'is_active' => true,
        ]);
        PosShift::create([
            'shift_number' => 'SHIFT-2', 'user_id' => $admin->id, 'branch_id' => $branch->id,
            'treasury_id' => $drawer2->id, 'status' => PosShiftStatus::Open, 'opened_at' => now()->subHour(),
        ]);

        $out = app(ToolRegistry::class)->dispatch($admin, AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']), [
            'name' => 'daily_close_briefing', 'args' => ['branch_id' => $branch->id],
        ]);
        $this->assertSame('data', $out->type);
        $data = $out->data;
        $this->assertSame(400.0, $data['cash_sales']);
        $this->assertSame(150.0, $data['expenses']);
        $this->assertSame(1, $data['transfers']['created']);
        $this->assertSame(1, $data['transfers']['pending']);
        $this->assertCount(2, $data['open_shifts']);
        $this->assertSame(10.0, $data['shortage']);
        $this->assertSame(0.0, $data['surplus']);

        $suggested = $data['suggested_transfers'];
        $this->assertCount(1, $suggested);
        $this->assertSame($drawer->id, $suggested[0]['from_treasury_id']);
        $this->assertSame($safe->id, $suggested[0]['to_treasury_id']);
        $this->assertSame(1000.0, $suggested[0]['amount']);

        $prompts = array_column($data['quick_actions'], 'prompt');
        $this->assertContains('اعتمد الورديات اللي مستنية التسوية', $prompts);
        $this->assertSame("جهّز دفعة تحويل فائض الأدراج للرئيسية للفرع رقم {$branch->id}", $prompts[1]);
        $this->assertSame('table', $out->uiBlock['type']);

        $conversation = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $this->mock(\App\Services\Ai\GeminiClient::class)->shouldNotReceive('converse');
        $proposal = app(\App\Services\Ai\AgentOrchestrator::class)->handle($admin, $conversation, ['text' => $prompts[1]]);
        $this->assertSame('bulk_plan', $proposal->blocks[0]['type'] ?? null);
        $this->assertDatabaseCount('treasury_transfers', 1);
        $action = \App\Models\AiPendingAction::findOrFail($proposal->blocks[0]['action_id']);
        $result = app(\App\Services\Ai\PendingActionService::class)->confirm($admin, $action, 'تنفيذ');
        $this->assertSame('result', $result->type);
        $this->assertDatabaseHas('treasury_transfers', [
            'from_treasury_id' => $drawer->id, 'to_treasury_id' => $safe->id, 'amount' => '1000.00', 'status' => 'pending',
        ]);
    }
}
