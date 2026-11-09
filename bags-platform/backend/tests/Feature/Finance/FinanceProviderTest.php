<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\Finance\FinanceToolProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinanceProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_finance_tools_enabled',
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

    public function test_provider_registers_under_finance_domain(): void
    {
        $provider = app(FinanceToolProvider::class);
        $this->assertSame('finance', $provider->domain());
        $this->assertFalse($provider->usesGlobalAdminLock());
        $this->assertTrue($provider->enabled());
        $this->assertIsArray($provider->tools());
    }

    public function test_wallet_and_vision_tools_hide_behind_their_flags(): void
    {
        $provider = app(FinanceToolProvider::class);
        $this->assertTrue($provider->isToolHidden('customer_wallet'));
        $this->assertTrue($provider->isToolHidden('parse_receipt'));
        $this->assertFalse($provider->isToolHidden('list_treasuries'));

        DB::table('settings')->where('key', 'ai_finance_wallet_tools_enabled')->update(['value' => '1']);
        DB::table('settings')->where('key', 'ai_finance_receipt_vision_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();

        $this->assertFalse($provider->isToolHidden('customer_wallet'));
        $this->assertFalse($provider->isToolHidden('parse_receipt'));
    }

    public function test_can_read_entity_maps_finance_types_to_permissions(): void
    {
        $provider = app(FinanceToolProvider::class);
        $this->assertNull($provider->canReadEntity($this->staff([]), 'variant'));

        $plain = $this->staff(['ai_agent.use']);
        $this->assertFalse($provider->canReadEntity($plain, 'treasury'));
        $this->assertFalse($provider->canReadEntity($plain, 'treasury_transfer'));
        $this->assertFalse($provider->canReadEntity($plain, 'expense'));
        $this->assertFalse($provider->canReadEntity($plain, 'wallet'));

        $viewer = $this->staff(['ai_agent.use', 'treasuries.view', 'treasury_transfers.view', 'expenses.view', 'customers.wallet.view']);
        $this->assertTrue($provider->canReadEntity($viewer, 'treasury'));
        $this->assertTrue($provider->canReadEntity($viewer, 'treasury_transfer'));
        $this->assertTrue($provider->canReadEntity($viewer, 'expense'));
        $this->assertTrue($provider->canReadEntity($viewer, 'wallet'));
    }

    public function test_system_prompt_requires_a_finance_permission(): void
    {
        $provider = app(FinanceToolProvider::class);
        $this->assertNull($provider->systemPromptSection($this->staff(['ai_agent.use'])));

        $section = $provider->systemPromptSection($this->staff(['ai_agent.use', 'treasuries.view']));
        $this->assertIsString($section);
        $this->assertStringContainsString('explain_order_money', $section);
        $this->assertStringContainsString('untrusted_text', $section);
    }

    public function test_finance_welcome_card_stays_null_without_rows(): void
    {
        $admin = $this->staff(['ai_agent.use', 'treasury_transfers.approve', 'treasuries.view', 'expenses.view']);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);

        $block = app(ToolRegistry::class)->welcomeBlock($admin);
        $this->assertNull($block);
        $this->assertTrue($conv->exists());
    }

    public function test_finance_welcome_card_surfaces_pending_approvals_first(): void
    {
        $branch = \App\Models\Branch::factory()->create(['is_active' => true]);
        $creator = $this->staff(['ai_agent.use', 'treasury_transfers.create'], $branch->id);
        $approver = $this->staff(['ai_agent.use', 'treasury_transfers.approve', 'treasuries.view'], $branch->id);
        $from = \App\Models\Treasury::create([
            'name' => 'خزينة', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => \App\Enums\TreasuryType::MainSafe, 'current_balance' => '1000.00', 'is_active' => true,
        ]);
        $to = \App\Models\Treasury::create([
            'name' => 'خزينة 2', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => \App\Enums\TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true,
        ]);
        app(\App\Services\Pos\TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
            'notes' => 'اختبار',
        ], $creator->id);

        $block = app(ToolRegistry::class)->welcomeBlock($approver);
        $this->assertNotNull($block);
        $this->assertSame('welcome_card', $block['type']);
        $this->assertSame('finance', $block['domain'] ?? null);
        $this->assertSame(1, $block['rows'][0]['value']);
    }
}
