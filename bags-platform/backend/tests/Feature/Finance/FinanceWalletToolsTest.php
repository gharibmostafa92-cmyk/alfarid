<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\CustomerStatus;
use App\Enums\StaffRole;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Ai\ToolRegistry;
use App\Services\Finance\WalletAdjustmentService;
use App\Services\Finance\WalletService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 4: wallet AI tools (W13/W14) — guard, reversal window, undo chain. */
class FinanceWalletToolsTest extends TestCase
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

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
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

    private function undo(User $u, AiPendingAction $action)
    {
        return $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/actions/{$action->id}/undo");
    }

    private function last4(Customer $customer): string
    {
        return substr((string) $customer->phone, -4);
    }

    public function test_adjust_confirms_and_key_is_required(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $noKey = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية',
        ]);
        $this->assertSame('denied', $noKey->type);

        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-1',
        ]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame('high', $action->risk);
        $this->confirm($admin, $action)->assertStatus(422);
        $this->confirm($admin, $action, '0000')->assertStatus(422);
        $this->confirm($admin, $action, $this->last4($customer))->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(100.0, (float) $customer->fresh()->wallet_balance);
        $adj = WalletAdjustment::findOrFail($action->fresh()->result['adjustment_id']);
        $this->assertSame('ai', $adj->source);
        $this->assertSame((string) $action->id, (string) $adj->ai_action_id);

        $replay = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-1',
        ]);
        $replayAction = AiPendingAction::findOrFail($replay->data['pending_action_id']);
        $this->confirm($admin, $replayAction, $this->last4($customer))->assertOk();
        $this->assertSame($adj->id, $replayAction->fresh()->result['adjustment_id']);
        $this->assertSame(100.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_guard_blocks_amount_over_max(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'refund', 'amount' => '6000',
            'reason' => 'استرداد كبير', 'idempotency_key' => 'w13-big',
        ]);
        $this->assertSame('denied', $outcome->type);

        $ok = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'refund', 'amount' => '4000',
            'reason' => 'استرداد', 'idempotency_key' => 'w13-ok',
        ]);
        $this->assertSame('proposal', $ok->type);
        $action = AiPendingAction::findOrFail($ok->data['pending_action_id']);
        $this->assertSame('high', $action->risk);
        $this->confirm($admin, $action, $this->last4($customer))->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(4000.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_stale_wallet_balance_conflicts(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-stale',
        ]);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        app(WalletService::class)->record($customer->id, 5.0, 'opening', 'customer', $customer->id);

        $this->confirm($admin, $action, $this->last4($customer))->assertStatus(409);
    }

    public function test_wallet_movements_conflict_even_when_the_balance_returns_to_its_original_value(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-offset',
        ]);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $wallet = app(WalletService::class);
        $wallet->record($customer->id, 5.0, 'opening', 'customer', $customer->id);
        $wallet->record($customer->id, -5.0, 'checkout_payment', 'order', 987654);

        $this->confirm($admin, $action, $this->last4($customer))->assertStatus(409);
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_adjust_preview_warnings_and_movements(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Pending]);
        app(WalletAdjustmentService::class)->adjust([
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100.00',
            'direction' => 'credit', 'reason' => 'رصيد افتتاحي', 'source' => 'admin',
        ], $admin, 'w13-seed');

        $denied = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'debit', 'reason' => 'سحب', 'idempotency_key' => 'w13-warn',
        ]);
        $this->assertSame('denied', $denied->type);
        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'إضافة', 'idempotency_key' => 'w13-warn-credit',
        ]);
        $this->assertSame('proposal', $outcome->type);
        $warnings = $outcome->uiBlock['warnings'] ?? [];
        $this->assertContains('اتعمل تعديل يدوي للعميل ده في آخر 7 أيام.', $warnings);
        $this->assertContains('العميل مش معتمد — راجع الحالة قبل التنفيذ.', $warnings);
        $this->assertSame(200.0, (float) ($outcome->uiBlock['after']['wallet_balance'] ?? null));
        $this->assertNotEmpty($outcome->uiBlock['recent_movements'] ?? []);
    }

    public function test_adjust_undo_reverses_with_counter_entry(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-undo',
        ]);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action, $this->last4($customer))->assertOk();
        $this->assertTrue(app(ToolRegistry::class)->canUndo($action->fresh()));

        $this->undo($admin, $action->fresh())->assertOk();
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(2, WalletAdjustment::query()->where('customer_id', $customer->id)->count());
        $this->assertFalse(app(ToolRegistry::class)->canUndo($action->fresh()));
    }

    public function test_adjust_undo_blocked_when_balance_spent(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-spent',
        ]);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action, $this->last4($customer))->assertOk();
        app(WalletService::class)->record($customer->id, -90.0, 'checkout_payment', 'order', 987654);

        $this->assertFalse(app(ToolRegistry::class)->canUndo($action->fresh()));
        $this->undo($admin, $action->fresh())->assertStatus(409);
    }

    public function test_reverse_window_and_undo_chain(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();

        $outcome = $this->propose($admin, 'adjust_customer_wallet', [
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '100',
            'direction' => 'credit', 'reason' => 'تسوية', 'idempotency_key' => 'w13-rev',
        ]);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->confirm($admin, $action, $this->last4($customer))->assertOk();
        $adjId = $action->fresh()->result['adjustment_id'];
        $this->assertTrue(app(ToolRegistry::class)->canUndo($action->fresh()));

        $rev = $this->propose($admin, 'reverse_wallet_adjustment', ['adjustment_id' => $adjId, 'idempotency_key' => 'w14-1']);
        $revAction = AiPendingAction::findOrFail($rev->data['pending_action_id']);
        $this->confirm($admin, $revAction)->assertOk();
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);

        $double = $this->propose($admin, 'reverse_wallet_adjustment', ['adjustment_id' => $adjId, 'idempotency_key' => 'w14-2']);
        $this->assertSame('denied', $double->type);

        $this->undo($admin, $revAction->fresh())->assertOk();
        $this->assertSame(100.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(3, WalletAdjustment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_reverse_after_window_denied(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $this->travel(-20)->minutes();
        $adj = app(WalletAdjustmentService::class)->adjust([
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '10.00',
            'direction' => 'credit', 'reason' => 'قديم', 'source' => 'admin',
        ], $admin, 'w13-old');
        $this->travelBack();

        $out = $this->propose($admin, 'reverse_wallet_adjustment', ['adjustment_id' => $adj->id, 'idempotency_key' => 'w14-old']);
        $this->assertSame('denied', $out->type);
    }

    public function test_reverse_by_stranger_is_auditable_denied(): void
    {
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $adj = app(WalletAdjustmentService::class)->adjust([
            'customer_id' => $customer->id, 'kind' => 'adjustment', 'amount' => '10.00',
            'direction' => 'credit', 'reason' => 'تسوية', 'source' => 'admin',
        ], $admin, 'w13-stranger');

        $stranger = $this->staff(['ai_agent.use', 'ai_agent.execute', 'customers.wallet.adjust']);
        $out = $this->propose($stranger, 'reverse_wallet_adjustment', ['adjustment_id' => $adj->id, 'idempotency_key' => 'w14-s']);
        $this->assertSame('denied', $out->type);
        $this->assertTrue($out->auditable);
    }
}
