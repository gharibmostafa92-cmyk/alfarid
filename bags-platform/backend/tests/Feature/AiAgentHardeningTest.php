<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiAgentHardeningTest extends TestCase
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

    private function centralAdmin(): User
    {
        return $this->staff(['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches']);
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function token(User $u): string
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    public function test_update_settings_rejects_out_of_range_tax_rate(): void
    {
        $admin = $this->centralAdmin();
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'update_settings', 'args' => ['changes' => ['default_tax_rate' => 1400]],
        ]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_update_settings_rejects_injected_expected_key(): void
    {
        $admin = $this->centralAdmin();
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'update_settings', 'args' => ['changes' => ['expected.company_name' => 'x']],
        ]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_update_document_numbering_rejects_huge_padding(): void
    {
        $admin = $this->centralAdmin();
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'update_document_numbering', 'args' => ['type' => 'order', 'padding' => 5000000],
        ]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_agent_created_user_must_rotate_temp_password(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'users.create', 'users.manage', 'branches.view', 'inventory.all_branches']);
        $branch = Branch::factory()->create();
        $res = app(\App\Actions\Staff\CreateStaffUser::class)(
            $admin,
            ['name' => 'Temp Staff', 'email' => 'tempstaff@example.test', 'branch_id' => $branch->id, 'roles' => []],
            ['channel' => 'ai'],
            generatePassword: true
        );
        $this->assertTrue((bool) $res['user']->fresh()->must_change_password);

        // Flagged user is blocked from admin reads until rotation.
        $token = $this->token($res['user']->fresh());
        $this->withToken($token)->getJson('/api/v1/admin/branches')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');

        // Self rotation via the dedicated endpoint clears the flag.
        $user = $res['user']->fresh();
        $this->withToken($token)->putJson('/api/v1/admin/me/password', [
            'expected_version' => (int) $user->lock_version,
            'current_password' => $res['plain_password'],
            'password' => 'RotatedPass12345',
        ])->assertOk();
        $this->assertFalse((bool) $user->fresh()->must_change_password);
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk();
    }

    public function test_admin_reset_of_other_password_sets_rotation_flag(): void
    {
        $admin = $this->staff(['ai_agent.use', 'users.update', 'users.manage', 'inventory.all_branches']);
        $target = User::factory()->create();
        $this->assertFalse((bool) $target->must_change_password);
        $failure = app(\App\Actions\Staff\UpdateStaffUser::class)(
            $admin, $target, ['password' => \Illuminate\Support\Facades\Hash::make('AdminSet12345')]
        );
        $this->assertNull($failure);
        $this->assertTrue((bool) $target->fresh()->must_change_password);
    }

    public function test_location_tools_require_locations_permissions_not_branches(): void
    {
        // H3: branches.delete must not grant location deletion; locations.*
        // is the only gate, matching the REST routes.
        $branch = Branch::factory()->create();
        $location = $branch->locations()->create(['name' => 'Shelf', 'code' => 'SH-1', 'type' => 'shelf']);
        $user = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.delete']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $registry = app(ToolRegistry::class);

        $this->assertSame('denied', $registry->dispatch($user, $this->conv($user), [
            'name' => 'delete_location', 'args' => ['location_id' => $location->id],
        ])->type);
        $this->assertSame(0, AiPendingAction::count());

        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('locations.delete', 'staff'));
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->assertSame('proposal', $registry->dispatch($user->fresh(), $this->conv($user), [
            'name' => 'delete_location', 'args' => ['location_id' => $location->id],
        ])->type);
    }

    public function test_location_status_audit_records_true_before_value(): void
    {
        // H3: the audit trail must store the real pre-change state.
        $branch = Branch::factory()->create();
        $location = $branch->locations()->create(['name' => 'Bin', 'code' => 'BIN-1', 'type' => 'bin', 'is_active' => true]);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'locations.update', 'inventory.all_branches']);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), [
            'name' => 'set_location_status', 'args' => ['location_id' => $location->id, 'active' => false],
        ]);
        $this->assertSame('proposal', $outcome->type);
        $token = $this->token($admin);
        $this->withToken($token)->postJson(
            "/api/v1/admin/ai/actions/{$outcome->data['pending_action_id']}/confirm", []
        )->assertOk();
        $audit = DB::table('staff_audit_logs')->where('action', 'location.deactivated')->first();
        $this->assertNotNull($audit);
        $meta = json_decode($audit->metadata, true);
        $this->assertTrue($meta['before']['is_active'] ?? null);
        $this->assertFalse($meta['after']['is_active'] ?? null);
    }

    public function test_interrupted_function_call_is_repaired_in_history(): void
    {
        // H4: a model message with an orphan functionCall (process died before
        // the tool reply was stored) must be paired with an interrupted stub,
        // otherwise Gemini rejects every later message with 400.
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $conv->messages()->create(['role' => 'user', 'content' => 'hi', 'provider_parts' => [['text' => 'hi']]]);
        $conv->messages()->create(['role' => 'model', 'content' => null,
            'provider_parts' => [['functionCall' => ['name' => 'list_roles', 'args' => (object) []]]]]);

        $history = $this->agentHistory($conv);
        $last = end($history);
        $this->assertSame('user', $last['role']);
        $response = $last['parts'][0]['functionResponse'] ?? null;
        $this->assertNotNull($response);
        $this->assertSame('list_roles', $response['name']);
        $this->assertTrue((bool) ($response['response']['interrupted'] ?? false));
    }

    public function test_completed_function_call_gets_no_repair_stub(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $conv->messages()->create(['role' => 'user', 'content' => 'hi', 'provider_parts' => [['text' => 'hi']]]);
        $conv->messages()->create(['role' => 'model', 'content' => null,
            'provider_parts' => [['functionCall' => ['name' => 'list_roles', 'args' => (object) []]]]]);
        $conv->messages()->create(['role' => 'tool', 'provider_parts' => [
            ['functionResponse' => ['name' => 'list_roles', 'response' => ['roles' => []]]],
        ]]);

        $history = $this->agentHistory($conv);
        foreach ($history as $turn) {
            foreach ($turn['parts'] as $part) {
                $this->assertFalse((bool) (($part['functionResponse']['response'] ?? [])['interrupted'] ?? false));
            }
        }
    }

    public function test_bulk_child_cannot_be_confirmed_directly(): void
    {
        // M10: sub-actions stay bulk_child until the bulk runs, so one item
        // can never be confirmed alone bypassing group review.
        // M-5: set_branch_status is no longer bulk-eligible — same scenario
        // with a permitted bulk tool.
        $first = $this->staff([]);
        $second = $this->staff([]);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'users.update', 'inventory.all_branches']);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'revoke_user_sessions', 'args' => ['user_id' => $first->id]],
            ['tool' => 'revoke_user_sessions', 'args' => ['user_id' => $second->id]],
        ]]]);
        $this->assertSame('proposal', $outcome->type);
        $child = AiPendingAction::query()->where('status', 'bulk_child')->firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$child->id}/confirm", [])->assertConflict();
        $this->assertSame('bulk_child', $child->fresh()->status);
    }

    public function test_stuck_confirmed_bulk_is_failed_by_pruning(): void
    {
        // M10: a bulk stuck in confirmed (process died mid-execution) is
        // closed by the periodic job instead of hanging forever.
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $action = AiPendingAction::create([
            'conversation_id' => $conv->id, 'user_id' => $admin->id, 'tool' => 'bulk_actions',
            'args' => ['action_ids' => []], 'expected_versions' => [], 'preview' => ['title' => 'stuck'],
            'args_hash' => app(ToolRegistry::class)->hashArgs(['action_ids' => []]),
            'risk' => 'high', 'status' => 'confirmed', 'expires_at' => now()->addMinutes(10),
        ]);
        $action->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        app(\App\Jobs\PruneAiConversations::class)->handle(app(SettingService::class));
        $this->assertSame('failed', $action->fresh()->status);
    }

    public function test_conversation_all_view_requires_central_access(): void
    {
        // M11: ?all=1 exposes every user's conversations, so branch-restricted
        // viewers are rejected even with logs.view.
        $owner = $this->staff(['ai_agent.use']);
        $this->conv($owner);
        $restricted = $this->staff(['ai_agent.use', 'ai_agent.logs.view']);
        $restricted->forceFill(['branch_id' => Branch::factory()->create()->id])->save();
        $this->withToken($this->token($restricted))->getJson('/api/v1/admin/ai/conversations?all=1')->assertForbidden();
        $central = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'inventory.all_branches']);
        $this->withToken($this->token($central))->getJson('/api/v1/admin/ai/conversations?all=1')->assertOk();
    }

    /** @return list<array{role:string,parts:list<array<string,mixed>>}> */
    private function agentHistory(AiConversation $conv): array
    {
        $method = new \ReflectionMethod(\App\Services\Ai\AgentOrchestrator::class, 'history');
        $method->setAccessible(true);

        return $method->invoke(app(\App\Services\Ai\AgentOrchestrator::class), $conv);
    }

    public function test_branch_restricted_admin_cannot_prepare_or_execute_role_tools(): void
    {
        $own = Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'roles.create', 'roles.update', 'roles.delete']);
        $admin->forceFill(['branch_id' => $own->id])->save();
        $registry = app(ToolRegistry::class);

        $this->assertSame('denied', $registry->dispatch($admin, $this->conv($admin), [
            'name' => 'create_role', 'args' => ['name' => 'H2 Probe'],
        ])->type);
        $this->assertSame('denied', $registry->dispatch($admin, $this->conv($admin), [
            'name' => 'delete_role', 'args' => ['role_id' => 1],
        ])->type);
        $this->assertSame(0, AiPendingAction::count());

        // Execute path re-checks central access even for tools without role_id:
        // assertCentralAccess throws 403 out of executeWrite (it is not
        // converted to a failed result), so no role is created.
        $action = new AiPendingAction(['tool' => 'create_role', 'args' => ['name' => 'H2 Probe']]);
        $action->forceFill([
            'conversation_id' => $this->conv($admin)->id, 'user_id' => $admin->id,
            'expected_versions' => [], 'preview' => ['title' => 'probe'],
            'args_hash' => app(ToolRegistry::class)->hashArgs(['name' => 'H2 Probe']),
            'risk' => 'medium', 'status' => 'pending', 'expires_at' => now()->addMinutes(10),
        ])->save();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $registry->executeWrite($admin, $action, ['channel' => 'ai']);
    }
}
