<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Jobs\PruneAiConversations;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiAgentTest extends TestCase
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

    private function geminiCall(string $name, array $args): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['functionCall' => ['name' => $name, 'args' => $args], 'thoughtSignature' => 'sig']]]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]];
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_write_tool_creates_pending_action_without_mutating_until_confirm(): void
    {
        $branch = Branch::factory()->create(['is_active' => true, 'code' => 'BR-MAADI']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'branches.manage', 'inventory.all_branches']);
        $token = $this->token($admin);
        $conv = $this->conv($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiCall('set_branch_status', ['branch_id' => $branch->id, 'active' => false]))]);
        $res = $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'وقف المعادي']);
        $res->assertOk();
        $this->assertTrue($branch->fresh()->is_active);
        $action = AiPendingAction::first();
        $this->assertNotNull($action);
        $this->assertSame('pending', $action->status);
        // confirm with typed confirmation (branch code)
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => 'BR-MAADI'])->assertOk()->assertJsonPath('data.ok', true);
        $this->assertFalse($branch->fresh()->is_active);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'branch.deactivated', 'target_type' => 'branch', 'target_id' => $branch->id]);
        $audit = DB::table('staff_audit_logs')->where('action', 'branch.deactivated')->first();
        $meta = json_decode($audit->metadata, true);
        $this->assertSame('ai', $meta['via']['channel'] ?? null);
        // second confirm idempotent (already executed returns ok without re-executing)
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => 'BR-MAADI'])->assertOk();
        $this->assertSame('executed', $action->fresh()->status);
    }

    public function test_user_with_use_only_cannot_execute_write(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $user = $this->staff(['ai_agent.use', 'branches.update']);
        $token = $this->token($user);
        $conv = $this->conv($user);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiCall('set_branch_status', ['branch_id' => $branch->id, 'active' => false]))]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'وقف الفرع'])->assertOk();
        $this->assertTrue($branch->fresh()->is_active);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_stale_version_returns_409_on_confirm(): void
    {
        $branch = Branch::factory()->create(['is_active' => true, 'code' => 'BR-X1']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $token = $this->token($admin);
        $conv = $this->conv($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiCall('set_branch_status', ['branch_id' => $branch->id, 'active' => false]))]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'وقف'])->assertOk();
        $action = AiPendingAction::first();
        DB::table('branches')->where('id', $branch->id)->update(['name' => 'Changed', 'updated_at' => now()->subMinute()]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => 'BR-X1'])->assertStatus(409);
        $this->assertSame('failed', $action->fresh()->status);
    }

    public function test_expired_action_is_rejected(): void
    {
        $branch = Branch::factory()->create(['is_active' => true, 'code' => 'BR-EXP']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $token = $this->token($admin);
        $conv = $this->conv($admin);
        $action = AiPendingAction::create(['conversation_id' => $conv->id, 'user_id' => $admin->id, 'tool' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false], 'expected_versions' => [], 'preview' => ['title' => 'x'], 'args_hash' => app(ToolRegistry::class)->hashArgs(['branch_id' => $branch->id, 'active' => false]), 'risk' => 'low', 'status' => 'pending', 'expires_at' => now()->subMinute()]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", [])->assertStatus(409);
        $this->assertSame('expired', $action->fresh()->status);
    }

    public function test_confirmation_rejects_a_tool_disabled_after_proposal(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-DISABLED']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $this->assertSame('proposal', $outcome->type);
        DB::table('settings')->where('key', 'ai_disabled_tools')->update(['value' => json_encode(['set_branch_status'])]);
        app(SettingService::class)->clearCache();
        $action = AiPendingAction::firstOrFail();
        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => $branch->code])->assertForbidden();
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_undo_rejects_changes_made_after_execution(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-UNDO']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => $branch->code])->assertOk();
        $branch->fresh()->update(['name' => 'Changed after execution']);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertConflict();
        $this->assertFalse($branch->fresh()->is_active);
        $this->assertSame('executed', $action->fresh()->status);
    }

    public function test_entity_search_cannot_bypass_entity_permissions(): void
    {
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'find_entities', 'args' => ['type' => 'user', 'query' => '']]);
        $this->assertSame('denied', $outcome->type);
        $this->withToken($this->token($admin))->getJson('/api/v1/admin/ai/mentions')->assertOk()->assertJsonMissingPath('data.users')->assertJsonMissingPath('data.roles');
    }

    public function test_ai_cannot_remove_super_admin_even_when_another_exists(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'users.update', 'inventory.all_branches']);
        $target = $this->staff([]);
        $role = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $target->assignRole($role);
        $other = $this->staff([]);
        $other->assignRole($role);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'assign_roles', 'args' => ['user_id' => $target->id, 'remove' => [StaffRole::SuperAdmin->value]]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_read_tool_responses_survive_into_the_next_turn(): void
    {
        Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        $textResponse = ['candidates' => [['content' => ['parts' => [['text' => 'دي الفروع.']]], 'finishReason' => 'STOP']]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push($this->geminiCall('list_branches', []))->push($textResponse)->push($textResponse)]);
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'الفروع'])->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'كمل'])->assertOk();
        $requests = Http::recorded();
        $history = $requests[2][0]['contents'];
        $this->assertNotEmpty(collect($history)->flatMap(fn ($turn) => $turn['parts'])->first(fn ($part) => isset($part['functionResponse'])));
    }

    public function test_ai_disabled_returns_403(): void
    {
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $token = $this->token($admin);
        $conv = $this->conv($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiCall('list_branches', []))]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'hi'])->assertStatus(403);
    }

    public function test_tampered_arguments_and_missing_typed_confirmation_are_rejected(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-TAMPER']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm")->assertUnprocessable();
        $action->forceFill(['args' => ['branch_id' => $branch->id, 'active' => true]])->save();
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => $branch->code])->assertConflict();
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_permissions_are_rechecked_after_proposal(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-REVOKED']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $admin->revokePermissionTo('branches.update');
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => $branch->code])->assertForbidden();
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_restricted_staff_cannot_select_or_modify_another_branch(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.view', 'branches.update']);
        $admin->forceFill(['branch_id' => $own->id])->save();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        $this->withToken($token)->getJson('/api/v1/admin/ai/mentions?entity=branch')->assertOk()->assertJsonCount(1, 'data.branches')->assertJsonPath('data.branches.0.id', $own->id);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['selection' => ['entity' => 'branch', 'id' => $other->id, 'label' => 'اسم مزور']])->assertUnprocessable();
        $outcome = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'set_branch_status', 'args' => ['branch_id' => $own->id, 'active' => false]]);
        $this->assertSame('denied', $outcome->type);
        Http::assertNothingSent();
    }

    public function test_cancel_persists_event_and_prevents_execution(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-CANCEL']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => $branch->code])->assertConflict();
        $this->assertTrue($branch->fresh()->is_active);
        $this->assertDatabaseHas('ai_messages', ['conversation_id' => $conv->id, 'role' => 'event']);
    }

    public function test_undo_restores_original_state_once(): void
    {
        $branch = Branch::factory()->create(['code' => 'BR-RESTORE']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $registry->dispatch($admin, $this->conv($admin), ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $action = AiPendingAction::firstOrFail();
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", ['typed_confirmation' => $branch->code])->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertOk()->assertJsonPath('data.ok', true);
        $this->assertTrue($branch->fresh()->is_active);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo")->assertConflict();
    }

    public function test_retention_physically_prunes_old_conversations_including_empty_and_deleted(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $old = $this->conv($admin);
        $old->forceFill(['created_at' => now()->subDays(181)])->save();
        $old->delete();
        $recent = $this->conv($admin);
        app(PruneAiConversations::class)->handle(app(SettingService::class));
        $this->assertDatabaseMissing('ai_conversations', ['id' => $old->id]);
        $this->assertDatabaseHas('ai_conversations', ['id' => $recent->id]);
    }

    public function test_invalid_model_arguments_cannot_create_staff(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'users.create', 'inventory.all_branches']);
        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($admin, $this->conv($admin), ['name' => 'create_staff_user', 'args' => ['name' => 'Invalid', 'email' => 'invalid', 'roles' => []]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_bulk_confirmation_keeps_successes_when_another_item_is_stale(): void
    {
        $first = Branch::factory()->create(['code' => 'BR-BULK-1']);
        $second = Branch::factory()->create(['code' => 'BR-BULK-2']);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'set_branch_status', 'args' => ['branch_id' => $first->id, 'active' => false]],
            ['tool' => 'set_branch_status', 'args' => ['branch_id' => $second->id, 'active' => false]],
        ]]]);
        $this->assertSame('proposal', $outcome->type);
        $this->assertSame('bulk_plan', $outcome->uiBlock['type']);
        $first->update(['name' => 'Changed after proposal']);
        $id = $outcome->data['pending_action_id'];
        $token = $this->token($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$id}/confirm", ['typed_confirmation' => 'تنفيذ'])->assertOk()->assertJsonPath('data.result.items.0.ok', false)->assertJsonPath('data.result.items.1.ok', true);
        $this->assertTrue($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$id}/confirm", ['typed_confirmation' => 'تنفيذ'])->assertOk();
        $this->assertSame(1, DB::table('staff_audit_logs')->where('action', 'branch.deactivated')->count());
    }

    public function test_ai_settings_require_dedicated_permission_and_preserve_array_values(): void
    {
        $admin = $this->staff(['ai_agent.use', 'settings.manage', 'inventory.all_branches']);
        $token = $this->token($admin);
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', ['ai_disabled_tools' => ['delete_role'], 'expected' => ['ai_disabled_tools' => []]])->assertForbidden();
        $admin->givePermissionTo(Permission::findOrCreate('ai_agent.settings', 'staff'));
        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', ['ai_disabled_tools' => ['delete_role'], 'expected' => ['ai_disabled_tools' => []]])->assertOk()->assertJsonPath('data.ai_disabled_tools', ['delete_role']);
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', ['ai_disabled_tools' => [], 'expected' => ['ai_disabled_tools' => []]])->assertConflict();
    }

    public function test_conversations_hide_provider_parts_and_prevent_cross_user_mutations(): void
    {
        $owner = $this->staff(['ai_agent.use']);
        $auditor = $this->staff(['ai_agent.use', 'ai_agent.logs.view']);
        $conv = $this->conv($owner);
        $conv->messages()->create(['role' => 'model', 'content' => 'رد', 'provider_parts' => [['text' => 'رد', 'thoughtSignature' => 'private-signature']]]);
        $token = $this->token($auditor);
        // M-10: مدقق بلا وصول مركزي يُمنع من محادثات الفروع الأخرى.
        $this->withToken($token)->getJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertForbidden();
        $auditor->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        Auth::forgetGuards();
        $token = $this->token($auditor);
        $this->withToken($token)->getJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertOk()->assertJsonMissingPath('data.messages.0.provider_parts');
        $this->withToken($token)->patchJson("/api/v1/admin/ai/conversations/{$conv->id}/pin", ['is_pinned' => true])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'اختبار'])->assertForbidden();
        $this->withToken($this->token($owner))->patchJson("/api/v1/admin/ai/conversations/{$conv->id}/pin", ['is_pinned' => true])->assertOk()->assertJsonPath('data.is_pinned', true);
    }

    public function test_create_staff_user_password_not_in_messages(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'users.create', 'users.manage', 'inventory.all_branches']);
        $token = $this->token($admin);
        $conv = $this->conv($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiCall('create_staff_user', ['name' => 'New Staff', 'email' => 'newstaff@example.test', 'roles' => []]))]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'اعمل موظف'])->assertOk();
        $action = AiPendingAction::first();
        $this->assertNotNull($action);
        $res = $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", []);
        $res->assertOk();
        $plain = $res->json('data.result.generated_password');
        $this->assertNotEmpty($plain);
        $allMessages = DB::table('ai_messages')->pluck('content')->implode(' ').DB::table('ai_messages')->pluck('ui_blocks')->implode(' ');
        $this->assertStringNotContainsString($plain, $allMessages);
        $this->assertStringNotContainsString($plain, json_encode($action->fresh()->result));
    }

    public function test_shared_user_update_keeps_password_when_rest_sends_null(): void
    {
        $admin = $this->staff(['ai_agent.use', 'users.update', 'inventory.all_branches']);
        $target = $this->staff([]);
        $passwordHash = $target->password;
        $this->withToken($this->token($admin))->patchJson('/api/v1/admin/users/'.$target->id, ['name' => 'New name', 'password' => null, 'expected_version' => (int) $target->lock_version])->assertOk();
        $this->assertSame($passwordHash, $target->fresh()->password);
    }

    public function test_evaluation_follows_read_tools_before_expected_call_without_executing_writes(): void
    {
        $admin = $this->staff(['ai_agent.use', 'users.view', 'inventory.all_branches']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->geminiCall('find_entities', ['type' => 'user', 'query' => 'سارة']))
            ->push($this->geminiCall('explain_user_access', ['user_id' => 12]))]);
        $this->artisan('ai:evaluate', ['--actor' => $admin->id, '--case' => ['egy-7-1'], '--min-accuracy' => 1, '--rpm' => 600])->assertExitCode(0);
        Http::assertSentCount(2);
        $this->assertSame(0, AiPendingAction::count());
        $this->assertSame(0, AiConversation::count());
    }

    public function test_evaluation_rejects_additional_permissions_in_a_write_call(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'roles.create', 'inventory.all_branches', 'inventory.view', 'inventory.adjust']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiCall('create_role', ['name' => 'مراجع مخزون', 'permissions' => ['inventory.view', 'inventory.adjust']]))]);
        $this->artisan('ai:evaluate', ['--actor' => $admin->id, '--case' => ['egy-11-1'], '--min-accuracy' => 1, '--rpm' => 600])->assertExitCode(1);
        $this->assertSame(0, AiPendingAction::count());
        $this->assertDatabaseMissing('roles', ['name' => 'مراجع مخزون']);
    }

    public function test_failed_model_usage_is_recorded_and_counts_toward_budget(): void
    {
        // H-4: توكنز الطلب الفاشل (MAX_TOKENS) تُسجَّل على رسالة الـ event
        // نفسها فيُحصيها guard() — لا تكلفة بلا سقف.
        $admin = $this->staff(['ai_agent.use']);
        $token = $this->token($admin);
        $conv = $this->conv($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'partial']]]]],
            'usageMetadata' => ['promptTokenCount' => 60, 'candidatesTokenCount' => 40, 'totalTokenCount' => 100],
        ])]);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'نص طويل'])->assertStatus(502);
        $this->assertDatabaseHas('ai_messages', ['conversation_id' => $conv->id, 'role' => 'event', 'input_tokens' => 60, 'output_tokens' => 40]);
        DB::table('settings')->where('key', 'ai_daily_token_budget_per_user')->update(['value' => '100']);
        app(SettingService::class)->clearCache();
        $conv2 = $this->conv($admin);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv2->id}/messages", ['text' => 'طلب آخر'])->assertStatus(429);
    }

    public function test_deleting_a_conversation_does_not_reset_daily_token_usage(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $old = $this->conv($admin);
        $old->messages()->create(['role' => 'model', 'content' => 'Previous answer', 'input_tokens' => 200000, 'output_tokens' => 0]);
        $old->delete();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        $this->withToken($token)->postJson('/api/v1/admin/ai/conversations/'.$conv->id.'/messages', ['text' => 'طلب آخر'])->assertStatus(429);
        $this->withToken($token)->getJson('/api/v1/admin/ai/usage')->assertOk()->assertJsonPath('data.0.input_tokens', 200000);
        Http::assertNothingSent();
    }

    public function test_creation_tools_reuse_normalization_and_unique_validation(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'roles.create', 'branches.create', 'inventory.all_branches']);
        Role::findOrCreate('مشرف مخزن', 'staff');
        Branch::factory()->create(['code' => 'BR-DUP']);
        $registry = app(ToolRegistry::class);
        $conv = $this->conv($admin);
        $role = $registry->dispatch($admin, $conv, ['name' => 'create_role', 'args' => ['name' => ' مشرف مخزن ']]);
        $branch = $registry->dispatch($admin, $conv, ['name' => 'create_branch', 'args' => ['name' => 'Duplicate', 'code' => ' br-dup ', 'type' => 'branch']]);
        $this->assertSame('denied', $role->type);
        $this->assertSame('denied', $branch->type);
        $this->assertSame(0, AiPendingAction::count());
    }
}
