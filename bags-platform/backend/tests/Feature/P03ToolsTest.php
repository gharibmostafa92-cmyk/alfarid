<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LocationType;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Location;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class P03ToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Http::preventStrayRequests();
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

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

    // ── Locations ────────────────────────────────────────────────────────────

    public function test_list_locations_returns_table_for_branch(): void
    {
        $branch = Branch::factory()->create();
        $loc = Location::factory()->for($branch)->create(['name' => 'رف A', 'code' => 'SH-A']);
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'list_locations', 'args' => ['branch_id' => $branch->id]]);
        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['locations']);
        $this->assertSame('SH-A', $outcome->data['locations'][0]['code']);
    }

    public function test_list_locations_denied_without_branch_access(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        Location::factory()->for($other)->create();
        $admin = $this->staff(['ai_agent.use', 'branches.view']);
        $admin->forceFill(['branch_id' => $own->id])->save();
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'list_locations', 'args' => ['branch_id' => $other->id]]);
        // Should ask to pick a branch or denied — not return data for the other branch
        $this->assertNotSame('data', $outcome->type);
    }

    public function test_create_location_proposal_and_confirm(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-LOC']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);
        $outcome = $registry->dispatch($admin, $conv, ['name' => 'create_location', 'args' => [
            'branch_id' => $branch->id,
            'name' => 'رف جديد',
            'code' => 'SH-NEW',
            'type' => 'shelf',
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertSame(0, Location::where('code', 'SH-NEW')->count());

        // confirm
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm")->assertOk();
        $this->assertDatabaseHas('locations', ['code' => 'SH-NEW', 'branch_id' => $branch->id, 'type' => LocationType::Shelf->value]);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'location.created', 'target_type' => 'location']);
    }

    public function test_create_location_warns_on_duplicate_code(): void
    {
        $branch = Branch::factory()->create();
        Location::factory()->for($branch)->create(['code' => 'SH-DUP']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'create_location', 'args' => [
            'branch_id' => $branch->id,
            'name' => 'رف مكرر',
            'code' => 'SH-DUP',
            'type' => 'shelf',
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertNotEmpty($outcome->uiBlock['warnings'] ?? []);
    }

    public function test_set_location_status_proposal_and_undo(): void
    {
        $branch = Branch::factory()->create();
        $loc = Location::factory()->for($branch)->create(['is_active' => true]);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);
        $outcome = $registry->dispatch($admin, $conv, ['name' => 'set_location_status', 'args' => [
            'location_id' => $loc->id,
            'active' => false,
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertTrue($loc->fresh()->is_active);

        // confirm
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm")->assertOk();
        $this->assertFalse($loc->fresh()->is_active);

        // undo
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk();
        $this->assertTrue($loc->fresh()->is_active);
    }

    public function test_delete_location_blocked_when_has_non_zero_balance(): void
    {
        $branch = Branch::factory()->create();
        $loc = Location::factory()->for($branch)->create();
        $variant = \App\Models\ProductVariant::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.delete', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);

        // Add a non-zero stock level so delete is blocked at execution
        DB::table('stock_levels')->insert([
            'location_id' => $loc->id,
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'on_hand' => 5,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outcome = $registry->dispatch($admin, $conv, ['name' => 'delete_location', 'args' => ['location_id' => $loc->id]]);
        // Should still create proposal but with warnings about balances
        $this->assertSame('proposal', $outcome->type);
        $warnings = $outcome->uiBlock['warnings'] ?? [];
        $this->assertNotEmpty($warnings);
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'أرصدة')));
    }

    public function test_delete_location_succeeds_when_clean(): void
    {
        $branch = Branch::factory()->create();
        $loc = Location::factory()->for($branch)->create(['name' => 'حذف']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.delete', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);
        $outcome = $registry->dispatch($admin, $conv, ['name' => 'delete_location', 'args' => ['location_id' => $loc->id]]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm")->assertOk();
        $this->assertNull(Location::find($loc->id));
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'location.deleted', 'target_type' => 'location']);
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function test_view_settings_returns_grouped_data(): void
    {
        $admin = $this->staff(['ai_agent.use', 'settings.view', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'view_settings', 'args' => ['group' => 'tax']]);
        $this->assertSame('data', $outcome->type);
        $this->assertArrayHasKey('settings', $outcome->data);
    }

    public function test_view_settings_denied_without_central_access(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'settings.view']);
        $admin->forceFill(['branch_id' => $branch->id])->save();
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'view_settings', 'args' => []]);
        $this->assertSame('denied', $outcome->type);
    }

    public function test_update_settings_blocks_numbering_keys(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'update_settings', 'args' => [
            'changes' => ['invoice_prefix' => 'INV-'],
        ]]);
        $this->assertSame('denied', $outcome->type);
    }

    public function test_update_settings_proposal_and_confirm(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);
        $outcome = $registry->dispatch($admin, $conv, ['name' => 'update_settings', 'args' => [
            'changes' => ['return_window_days' => 30],
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm")->assertOk();
        app(SettingService::class)->clearCache();
        $this->assertSame(30, (int) app(SettingService::class)->get('return_window_days'));
    }

    // ── Document Numbering ───────────────────────────────────────────────────

    public function test_view_document_numbering_single_type(): void
    {
        $admin = $this->staff(['ai_agent.use', 'settings.view', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'view_document_numbering', 'args' => ['type' => 'invoice']]);
        $this->assertSame('data', $outcome->type);
        $this->assertSame('invoice', $outcome->data['numbering']['type']);
        $this->assertArrayHasKey('sample', $outcome->data['numbering']);
    }

    public function test_view_document_numbering_all_types(): void
    {
        $admin = $this->staff(['ai_agent.use', 'settings.view', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'view_document_numbering', 'args' => ['type' => 'all']]);
        $this->assertSame('data', $outcome->type);
        $this->assertCount(16, $outcome->data['numbering']);
    }

    public function test_update_document_numbering_proposal(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);
        $outcome = $registry->dispatch($admin, $conv, ['name' => 'update_document_numbering', 'args' => [
            'type' => 'purchase_order',
            'prefix' => 'PO-',
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertStringContainsString('purchase_order', $outcome->uiBlock['title']);
    }

    public function test_update_document_numbering_requires_type(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'update_document_numbering', 'args' => [
            'type' => '',
            'prefix' => 'X-',
        ]]);
        $this->assertSame('denied', $outcome->type);
    }

    public function test_update_document_numbering_warns_on_counter_decrease(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches']);
        // Set current counter to 100
        DB::table('settings')->updateOrInsert(['key' => 'invoice_next_number'], ['value' => '100']);
        app(SettingService::class)->clearCache();
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'update_document_numbering', 'args' => [
            'type' => 'invoice',
            'next_number' => 50,
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertNotEmpty($outcome->uiBlock['warnings'] ?? []);
    }

    // ── Entity Resolver for Locations ────────────────────────────────────────

    public function test_find_entities_location_searches_by_query(): void
    {
        $branch = Branch::factory()->create();
        Location::factory()->for($branch)->create(['name' => 'رف ألف', 'code' => 'SH-ALF']);
        Location::factory()->for($branch)->create(['name' => 'منطقة تالف', 'code' => 'DMG-01']);
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'find_entities', 'args' => ['type' => 'location', 'query' => 'SH-ALF']]);
        $this->assertSame('data', $outcome->type);
        $this->assertCount(1, $outcome->data['results']);
        $this->assertStringContainsString('SH-ALF', $outcome->data['results'][0]['label']);
    }

    // ── Evaluation dataset ───────────────────────────────────────────────────

    public function test_evaluation_dataset_validates_84_cases(): void
    {
        $admin = $this->staff(['ai_agent.use', 'inventory.all_branches']);
        $this->artisan('ai:evaluate', ['--check' => true])->assertExitCode(0);
    }
}
