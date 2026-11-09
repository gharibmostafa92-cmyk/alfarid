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
use App\Services\Ai\Finance\BulkUndoGuard;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
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

/** Phase 2: transfer write tools (W5-W7 + bulk) — drafts, approvals, rejects, concurrency. */
class FinanceTransferToolsTest extends TestCase
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

    private function propose(User $u, string $tool, array $args)
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

    /** @return array{from: Treasury, to: Treasury} */
    private function pair(Branch $branch, string $balance = '1000.00'): array
    {
        $make = fn (string $name) => Treasury::create(['name' => $name, 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => $balance, 'is_active' => true]);

        return ['from' => $make('من'), 'to' => $make('إلى')];
    }

    public function test_create_draft_warns_creator_cannot_approve(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch);

        $outcome = $this->propose($admin, 'create_treasury_transfer', [
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
        ]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertStringContainsString('منشئه', json_encode($outcome->uiBlock, JSON_UNESCAPED_UNICODE));
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);

        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $transfer = \App\Models\TreasuryTransfer::findOrFail($action->fresh()->result['transfer_id']);
        $this->assertSame('pending', $transfer->status->value);
        $this->assertTrue(app(ToolRegistry::class)->canUndo($action->fresh()));

        $self = $this->propose($admin, 'approve_treasury_transfer', ['transfer_id' => $transfer->id]);
        $this->assertSame('denied', $self->type);
        $this->assertTrue($self->auditable);

        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk();
        $this->assertSame('rejected', $transfer->fresh()->status->value);
        $this->assertSame('تراجع من المساعد', $transfer->fresh()->rejection_reason);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_high_value_transfer_requires_the_amount_and_rejects_a_stale_destination(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch);
        $proposal = $this->propose($admin, 'create_treasury_transfer', [
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '25000',
        ]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertSame('high', $action->risk);
        $this->confirm($admin, $action)->assertStatus(422);

        app(\App\Services\Pos\TreasuryService::class)->record([
            'treasury_id' => $to->id,
            'type' => \App\Enums\TreasuryTransactionType::OpeningBalance,
            'amount' => '5.00',
            'created_by' => $admin->id,
        ]);

        $this->confirm($admin, $action, '25000')->assertStatus(409);
        $this->assertDatabaseCount('treasury_transfers', 0);
    }

    public function test_approve_moves_balances_and_second_confirm_conflicts(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch);
        $approver = $this->staff(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.approve'], $branch->id);

        $first = $this->propose($approver, 'approve_treasury_transfer', []);
        $this->assertSame('denied', $first->type); // transfer_id required

        $transfer = app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
        ], $admin->id);

        $a = AiPendingAction::findOrFail($this->propose($approver, 'approve_treasury_transfer', ['transfer_id' => $transfer->id])->data['pending_action_id']);
        $b = AiPendingAction::findOrFail($this->propose($approver, 'approve_treasury_transfer', ['transfer_id' => $transfer->id])->data['pending_action_id']);

        $this->confirm($approver, $a)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(900.0, (float) $from->fresh()->current_balance);
        $this->assertSame(1100.0, (float) $to->fresh()->current_balance);

        // Second approval of the same transfer conflicts: only one wins.
        $this->confirm($approver, $b)->assertStatus(409);
        $this->assertSame('failed', $b->fresh()->status);

        // No orphaned pending actions remain behind after the race.
        $this->assertSame('executed', $a->fresh()->status);
        foreach ([$a->conversation_id, $b->conversation_id] as $convId) {
            $this->assertSame(0, AiPendingAction::query()->where('conversation_id', $convId)->where('status', 'pending')->count());
        }

        $reverse = $this->propose($admin, 'create_treasury_transfer', [
            'reverse_transfer_id' => $transfer->id, 'amount' => '1',
        ]);
        $this->assertSame('proposal', $reverse->type);
        $this->assertDatabaseCount('treasury_transfers', 1);
        $reverseAction = AiPendingAction::findOrFail($reverse->data['pending_action_id']);
        $this->confirm($admin, $reverseAction)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertDatabaseHas('treasury_transfers', [
            'from_treasury_id' => $to->id, 'to_treasury_id' => $from->id, 'amount' => '100.00', 'status' => 'pending',
        ]);
        $this->assertSame('approved', $transfer->fresh()->status->value);
        $this->assertSame(900.0, (float) $from->fresh()->current_balance);
    }

    public function test_high_value_approval_requires_typed_confirmation(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch, '50000.00');
        $approver = $this->staff(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.approve'], $branch->id);

        $transfer = app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '25000.00',
        ], $admin->id);

        $outcome = $this->propose($approver, 'approve_treasury_transfer', ['transfer_id' => $transfer->id]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame('high', $action->risk);

        $this->confirm($approver, $action)->assertStatus(422);
        $this->confirm($approver, $action, $transfer->transfer_number)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('approved', $transfer->fresh()->status->value);
    }

    public function test_approve_with_insufficient_balance_fails_cleanly(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch, '50.00');
        $approver = $this->staff(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.approve'], $branch->id);

        $transfer = app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '500.00',
        ], $admin->id);

        $outcome = $this->propose($approver, 'approve_treasury_transfer', ['transfer_id' => $transfer->id]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertNotEmpty($outcome->uiBlock['warnings']);

        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $res = $this->confirm($approver, $action)->assertOk();
        $res->assertJsonPath('data.ok', false);
        $this->assertStringContainsString('غير كاف', $res->json('data.message'));
        $this->assertSame('pending', $transfer->fresh()->status->value);
    }

    public function test_reject_stores_reason_without_moving_money(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch);
        $approver = $this->staff(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.approve'], $branch->id);

        $transfer = app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
        ], $admin->id);

        $outcome = $this->propose($approver, 'reject_treasury_transfer', ['transfer_id' => $transfer->id, 'reason' => 'مبلغ مبالغ فيه']);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($approver, $action)->assertOk()->assertJsonPath('data.ok', true);

        $fresh = $transfer->fresh();
        $this->assertSame('rejected', $fresh->status->value);
        $this->assertSame('مبلغ مبالغ فيه', $fresh->rejection_reason);
        $this->assertSame(1000.0, (float) $from->fresh()->current_balance);
    }

    public function test_bulk_approves_only_selected_eligible_transfers(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch);
        $approver = $this->staff(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.approve'], $branch->id);
        $svc = app(\App\Services\Pos\TreasuryService::class);

        $mine = $svc->createTransfer(['from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '10.00'], $approver->id);
        $ok1 = $svc->createTransfer(['from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '20.00'], $admin->id);
        $ok2 = $svc->createTransfer(['from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '30.00'], $admin->id);

        $outcome = $this->propose($approver, 'approve_treasury_transfers_bulk', [
            'transfer_ids' => [$mine->id, $ok1->id, $ok2->id],
        ]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertSame('bulk_plan', $outcome->uiBlock['type']);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame([$ok1->id, $ok2->id], array_values($action->args['transfer_ids']));
        $this->assertCount(1, $action->args['skipped']);

        $blockedKey = $outcome->uiBlock['items'][0]['key'];
        $selectedKey = array_search($ok1->id, $action->args['selection'], true);
        $url = "/api/v1/admin/ai/actions/{$action->id}/confirm";
        $this->withToken($this->token($approver))->postJson($url, ['selected_items' => [$blockedKey]])->assertStatus(422);
        $this->withToken($this->token($approver))->postJson($url, ['selected_items' => [$selectedKey]])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('approved', $ok1->fresh()->status->value);
        $this->assertSame('pending', $ok2->fresh()->status->value);
        $this->assertSame('pending', $mine->fresh()->status->value);
        $this->assertSame(980.0, (float) $from->fresh()->current_balance);

        $this->assertFalse(app(ToolRegistry::class)->canUndo($action->fresh()));
        $this->assertFalse((new BulkUndoGuard)->allowsUndo());
        $this->assertNotEmpty((new BulkUndoGuard)->reason());
    }

    public function test_bulk_denied_when_nothing_approvable(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        ['from' => $from, 'to' => $to] = $this->pair($branch);
        $approver = $this->staff(['ai_agent.use', 'ai_agent.execute', 'treasury_transfers.approve'], $branch->id);

        $mine = app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '10.00',
        ], $approver->id);

        $outcome = $this->propose($approver, 'approve_treasury_transfers_bulk', ['transfer_ids' => [$mine->id]]);
        $this->assertSame('denied', $outcome->type);
    }
}
