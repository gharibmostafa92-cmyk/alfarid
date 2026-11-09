<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\StaffRole;
use App\Enums\Finance\TreasuryType;
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

/** Phase 2: treasury write tools (W1-W4) — proposal, guards, confirm, no-undo. */
class FinanceWriteToolsTest extends TestCase
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
            'ai_finance_wallet_tools_enabled',
            'ai_action_ttl_minutes',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function staff(array $perms, ?int $branchId = null): User
    {
        $u = User::factory()->create(['branch_id' => $branchId]);
        foreach ([...$perms, 'ai_agent.finance'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function admin(): User
    {
        $u = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function propose(User $u, string $tool, array $args): \App\Services\Ai\ToolOutcome
    {
        return app(ToolRegistry::class)->dispatch($u, $this->conv($u), ['name' => $tool, 'args' => $args]);
    }

    private function confirm(User $u, AiPendingAction $action, ?string $typed = null)
    {
        return $this->withToken($this->token($u))->postJson(
            "/api/v1/admin/ai/actions/{$action->id}/confirm",
            $typed === null ? [] : ['typed_confirmation' => $typed]
        );
    }

    public function test_create_treasury_confirms_with_opening_balance(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);

        $outcome = $this->propose($admin, 'create_treasury', [
            'name' => 'خزينة جديدة', 'code' => 'SAFE-'.Str::uuid(),
            'type' => 'main_safe', 'branch_id' => $branch->id, 'initial_balance' => '500.00',
        ]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame('medium', $action->risk);

        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);

        $treasury = Treasury::query()->where('code', $action->args['code'])->firstOrFail();
        $this->assertSame(500.0, (float) $treasury->current_balance);
        $this->assertFalse(app(ToolRegistry::class)->canUndo($action->fresh()));
    }

    public function test_create_treasury_rejects_duplicate_code_and_foreign_branch(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        Treasury::create(['name' => 'قديمة', 'code' => 'SAFE-DUP', 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true]);

        $dup = $this->propose($admin, 'create_treasury', [
            'name' => 'جديدة', 'code' => 'SAFE-DUP', 'type' => 'main_safe', 'branch_id' => $branch->id,
        ]);
        $this->assertSame('denied', $dup->type);

        $restricted = $this->staff(['ai_agent.use'], Branch::factory()->create(['is_active' => true])->id);
        $central = $this->propose($restricted, 'create_treasury', [
            'name' => 'مركزية', 'code' => 'SAFE-'.Str::uuid(), 'type' => 'bank_account',
        ]);
        $this->assertSame('denied', $central->type);
    }

    public function test_update_treasury_name_ok_but_type_change_is_auditable_denied(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = Treasury::create(['name' => 'قديم', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true]);

        $locked = $this->propose($admin, 'update_treasury', ['treasury_id' => $treasury->id, 'type' => 'bank_account']);
        $this->assertSame('denied', $locked->type);
        $this->assertTrue($locked->auditable);

        $outcome = $this->propose($admin, 'update_treasury', ['treasury_id' => $treasury->id, 'name' => 'جديد']);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('جديد', $treasury->fresh()->name);
    }

    public function test_stop_treasury_blocked_by_balance_and_clean_stop_confirms(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $rich = Treasury::create(['name' => 'مليانة', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '100.00', 'is_active' => true]);
        $empty = Treasury::create(['name' => 'فاضية', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true]);

        $blocked = $this->propose($admin, 'set_treasury_status', ['treasury_id' => $rich->id, 'is_active' => false]);
        $this->assertSame('denied', $blocked->type);
        $this->assertStringContainsString('الرصيد مش صفر', $blocked->message);

        $outcome = $this->propose($admin, 'set_treasury_status', ['treasury_id' => $empty->id, 'is_active' => false]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action)->assertStatus(422);
        $this->confirm($admin, $action, $empty->code)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertFalse($empty->fresh()->is_active);
        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk()->assertJsonPath('data.ok', true);
        $this->assertTrue($empty->fresh()->is_active);
    }

    public function test_delete_treasury_blocked_by_history_and_empty_delete_confirms(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $used = Treasury::create(['name' => 'مستخدمة', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '1000.00', 'is_active' => true]);
        app(TreasuryService::class)->record([
            'treasury_id' => $used->id, 'type' => \App\Enums\TreasuryTransactionType::OpeningBalance,
            'amount' => '1000.00', 'created_by' => $admin->id,
        ]);
        $empty = Treasury::create(['name' => 'فاضية', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true]);

        $blocked = $this->propose($admin, 'delete_treasury', ['treasury_id' => $used->id]);
        $this->assertSame('denied', $blocked->type);

        $outcome = $this->propose($admin, 'delete_treasury', ['treasury_id' => $empty->id]);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action)->assertStatus(422);
        $this->confirm($admin, $action, $empty->code)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull(Treasury::find($empty->id));
    }

    public function test_update_treasury_can_restore_code_and_branch(): void
    {
        $admin = $this->admin();
        $original = Branch::factory()->create(['is_active' => true]);
        $destination = Branch::factory()->create(['is_active' => true]);
        $treasury = Treasury::create(['name' => 'خزينة', 'code' => 'SAFE-OLD', 'branch_id' => $original->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true]);

        $proposal = $this->propose($admin, 'update_treasury', [
            'treasury_id' => $treasury->id, 'branch_id' => $destination->id, 'code' => 'SAFE-NEW',
        ]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('SAFE-NEW', $treasury->fresh()->code);
        $this->assertSame($destination->id, $treasury->fresh()->branch_id);

        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('SAFE-OLD', $treasury->fresh()->code);
        $this->assertSame($original->id, $treasury->fresh()->branch_id);
    }

    public function test_stale_version_conflicts_with_409(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = Treasury::create(['name' => 'قديم', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true]);

        $outcome = $this->propose($admin, 'update_treasury', ['treasury_id' => $treasury->id, 'name' => 'جديد']);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);

        DB::table('treasuries')->where('id', $treasury->id)->update(['name' => 'تعديل متزامن', 'updated_at' => now()->addMinutes(5)]);

        $this->confirm($admin, $action)->assertStatus(409);
        $this->assertSame('تعديل متزامن', $treasury->fresh()->name);
    }
}
