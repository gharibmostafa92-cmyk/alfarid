<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TreasuryType;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 5.5: insufficient balance rejections carry a one-click top-up suggestion. */
class FinanceTopUpSuggestionTest extends TestCase
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

    private function user(array $perms): User
    {
        $u = User::factory()->create();
        foreach ([...$perms, 'ai_agent.finance'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function treasury(Branch $branch, string $type, string $balance, string $name): Treasury
    {
        return Treasury::create([
            'name' => $name, 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => $type,
            'current_balance' => $balance, 'is_active' => true,
        ]);
    }

    private function confirm(User $u, AiPendingAction $action, ?string $typed = null)
    {
        Auth::forgetGuards();

        return $this->withToken($u->createToken('t')->plainTextToken)->postJson(
            "/api/v1/admin/ai/actions/{$action->id}/confirm",
            $typed === null ? [] : ['typed_confirmation' => $typed]
        );
    }

    public function test_approve_shortfall_suggests_donor_and_fails_with_prompt(): void
    {
        $creator = $this->user(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.create']);
        $approver = $this->user(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.view', 'treasury_transfers.approve']);
        $branch = Branch::factory()->create(['is_active' => true]);
        $poor = $this->treasury($branch, TreasuryType::MainSafe->value, '100.00', 'الخزينة الفقيرة');
        $rich = $this->treasury($branch, TreasuryType::MainSafe->value, '5000.00', 'الخزينة الغنية');
        $transfer = app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $poor->id, 'to_treasury_id' => $rich->id, 'amount' => '1000.00',
        ], $creator->id);

        $out = app(ToolRegistry::class)->dispatch($approver, AiConversation::create(['user_id' => $approver->id, 'model' => 'gemini-test']), [
            'name' => 'approve_treasury_transfer', 'args' => ['transfer_id' => $transfer->id],
        ]);
        $this->assertSame('proposal', $out->type);
        $warnings = implode("\n", $out->uiBlock['warnings'] ?? []);
        $this->assertStringContainsString('الخزينة الغنية', $warnings);
        $this->assertStringContainsString('بضغطة', $warnings);

        $action = AiPendingAction::findOrFail($out->data['pending_action_id']);
        $res = $this->confirm($approver, $action)->assertOk();
        $res->assertJsonPath('data.ok', false);
        $this->assertStringContainsString('الخزينة الغنية', (string) $res->json('data.message'));
        $this->assertStringContainsString('بضغطة', (string) $res->json('data.message'));
        $this->assertSame('pending', $transfer->fresh()->status->value);
    }

    public function test_expense_shortfall_warning_suggests_donor(): void
    {
        $admin = $this->user(['ai_agent.use', 'ai_agent.execute', 'expenses.create']);
        $branch = Branch::factory()->create(['is_active' => true]);
        $poor = $this->treasury($branch, TreasuryType::MainSafe->value, '100.00', 'خزينة المصروف');
        $this->treasury($branch, TreasuryType::MainSafe->value, '9000.00', 'خزينة الاحتياطي');

        $out = app(ToolRegistry::class)->dispatch($admin, AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']), [
            'name' => 'create_expense', 'args' => [
                'branch_id' => $branch->id, 'treasury_id' => $poor->id,
                'category' => 'كهرباء', 'amount' => '1000',
            ],
        ]);
        $this->assertSame('proposal', $out->type);
        $warnings = implode("\n", $out->uiBlock['warnings'] ?? []);
        $this->assertStringContainsString('خزينة الاحتياطي', $warnings);
        $this->assertStringContainsString('بضغطة', $warnings);
    }
}
