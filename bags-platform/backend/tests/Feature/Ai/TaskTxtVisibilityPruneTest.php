<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Jobs\PruneAiConversations;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 22+24: conversation/usage visibility matrix and
 * PruneAiConversations retention + stuck-card handling.
 */
class TaskTxtVisibilityPruneTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Storage::fake('ai_private');
    }

    private function staff(array $perms, ?int $branchId = null): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        if ($branchId !== null) {
            $u->forceFill(['branch_id' => $branchId])->save();
        }

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function conv(User $u, bool $withToolMessage = false): AiConversation
    {
        $conv = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test', 'title' => 't']);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'hi',
            'provider_parts' => [['text' => 'hi', 'thoughtSignature' => 'private-signature']]]);
        if ($withToolMessage) {
            AiMessage::create(['conversation_id' => $conv->id, 'role' => 'tool', 'content' => null,
                'provider_parts' => [['functionResponse' => ['name' => 'list_branches', 'response' => []]]]]);
        }

        return $conv;
    }

    // ── task 22a/b: use-only and logs-view-without-customers see own only ──
    public function test_task22ab_limited_staff_see_own_conversations_only(): void
    {
        $owner = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'inventory.all_branches']);
        $plain = $this->staff(['ai_agent.use']);
        $logsOnly = $this->staff(['ai_agent.use', 'ai_agent.logs.view']);
        $ownerConv = $this->conv($owner, true);
        $plainConv = $this->conv($plain);
        $logsConv = $this->conv($logsOnly);
        $ownConv = [$plain->id => $plainConv->id, $logsOnly->id => $logsConv->id];

        foreach ([$plain, $logsOnly] as $viewer) {
            $token = $this->token($viewer);
            // all=1 still lists own only.
            $idx = $this->withToken($token)->getJson('/api/v1/admin/ai/conversations?all=1')->assertOk();
            $ids = collect($idx->json('data'))->pluck('id')->all();
            $this->assertContains($ownConv[$viewer->id], $ids);
            $this->assertNotContains($ownerConv->id, $ids);
            // Opening someone else's conversation → 403.
            $this->withToken($token)->getJson("/api/v1/admin/ai/conversations/{$ownerConv->id}")->assertStatus(403);
            // usage?all=1: (a) degrades to own usage (no logs.view); (b) is
            // refused 403 — usage demands central access (L-4), unlike the
            // conversations index which degrades to own.
            $this->withToken($token)->getJson('/api/v1/admin/ai/usage?all=1')
                ->assertStatus($viewer->id === $plain->id ? 200 : 403);
        }
    }

    // ── task 22c: branch-restricted with both perms → 403 on all=1 ──
    public function test_task22c_branch_restricted_denied_global_view(): void
    {
        $branch = Branch::factory()->create();
        $owner = $this->staff(['ai_agent.use', 'inventory.all_branches']);
        $this->conv($owner);
        $restricted = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'customers.view'], $branch->id);
        $token = $this->token($restricted);

        $this->withToken($token)->getJson('/api/v1/admin/ai/conversations?all=1')->assertStatus(403);
        $this->withToken($token)->getJson("/api/v1/admin/ai/conversations/{$this->conv($owner)->id}")->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/ai/usage?all=1')->assertStatus(403);
    }

    // ── task 22d: central with both perms sees all; tool msgs + provider_parts hidden ──
    public function test_task22d_central_views_all_without_tool_or_provider_parts(): void
    {
        $owner = $this->staff(['ai_agent.use', 'inventory.all_branches']);
        $ownerConv = $this->conv($owner, true);
        $central = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'customers.view', 'inventory.all_branches']);
        $token = $this->token($central);

        $idx = $this->withToken($token)->getJson('/api/v1/admin/ai/conversations?all=1')->assertOk();
        $this->assertContains($ownerConv->id, collect($idx->json('data'))->pluck('id')->all());

        $show = $this->withToken($token)->getJson("/api/v1/admin/ai/conversations/{$ownerConv->id}")->assertOk();
        $roles = collect($show->json('data.messages'))->pluck('role')->all();
        $this->assertNotContains('tool', $roles);
        $show->assertJsonMissingPath('data.messages.0.provider_parts');

        $this->withToken($token)->getJson('/api/v1/admin/ai/usage?all=1')->assertOk();
    }

    private function oldConv(User $u, int $daysAgo, bool $trashed = false): AiConversation
    {
        $conv = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
        $at = now()->subDays($daysAgo);
        $conv->forceFill(['created_at' => $at, 'updated_at' => $at, 'last_message_at' => $at])->save();
        if ($trashed) {
            $conv->delete();
            $conv->forceFill(['updated_at' => $at])->save();
        }

        return $conv;
    }

    private function oldAttachment(User $u, AiConversation $conv, int $hoursAgo): AiAttachment
    {
        Storage::disk('ai_private')->put("ai-attachments/{$conv->id}/old.jpg", 'x');
        $att = AiAttachment::create([
            'conversation_id' => $conv->id, 'user_id' => $u->id,
            'path' => "ai-attachments/{$conv->id}/old.jpg", 'mime' => 'image/jpeg', 'size' => 1, 'sha256' => 'x',
        ]);
        $at = now()->subHours($hoursAgo);
        $att->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $att;
    }

    private function card(User $u, AiConversation $conv, string $status, string $tool, int $minutesAgo, array $args = []): AiPendingAction
    {
        $args = $args !== [] ? $args : ['action_ids' => []];
        $card = AiPendingAction::create([
            'conversation_id' => $conv->id, 'user_id' => $u->id, 'tool' => $tool,
            'args' => $args, 'args_hash' => app(ToolRegistry::class)->hashArgs($args),
            'expected_versions' => [], 'preview' => [], 'risk' => 'low', 'status' => $status,
            'expires_at' => now()->addMinutes(15),
        ]);
        $at = now()->subMinutes($minutesAgo);
        $card->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        if ($status === 'pending' || $status === 'bulk_child') {
            $card->forceFill(['expires_at' => now()->subMinute(), 'updated_at' => $at])->save();
        }

        return $card;
    }

    // ── task 24: retention + stuck cards + storage failure tolerance ──
    public function test_task24_prune_retention_and_stuck_cards(): void
    {
        $u = $this->staff(['ai_agent.use', 'inventory.all_branches']);

        $old = $this->oldConv($u, 181);
        Storage::disk('ai_private')->put("ai-attachments/{$old->id}/keep.jpg", 'x');
        $recent = $this->oldConv($u, 179);
        $oldTrashed = $this->oldConv($u, 200, true);

        $conv = $this->conv($u);
        $oldAtt = $this->oldAttachment($u, $conv, 25);
        $freshAtt = $this->oldAttachment($u, $conv, 23);

        $expiredPending = $this->card($u, $conv, 'pending', 'update_variant', 30);
        $expiredChild = $this->card($u, $conv, 'bulk_child', 'update_variant', 30);
        $stuckBulk = $this->card($u, $conv, 'confirmed', 'bulk_actions', 6, ['action_ids' => []]);
        $stuckSingle = $this->card($u, $conv, 'confirmed', 'attach_product_images', 11);

        (new PruneAiConversations)->handle(app(SettingService::class));

        // Old conversations gone with their folders; recent kept.
        $this->assertNull(AiConversation::withTrashed()->find($old->id));
        $this->assertFalse(Storage::disk('ai_private')->exists("ai-attachments/{$old->id}"));
        $this->assertNull(AiConversation::withTrashed()->find($oldTrashed->id));
        $this->assertNotNull(AiConversation::find($recent->id));

        // Old unspent attachment file + row gone; fresh kept.
        $this->assertNull(AiAttachment::find($oldAtt->id));
        $this->assertFalse(Storage::disk('ai_private')->exists($oldAtt->path));
        $this->assertNotNull(AiAttachment::find($freshAtt->id));

        // Cards reach the states in code.
        $this->assertSame('expired', $expiredPending->fresh()->status);
        $this->assertSame('expired', $expiredChild->fresh()->status);
        $this->assertSame('failed', $stuckBulk->fresh()->status);
        $this->assertSame('failed', $stuckSingle->fresh()->status);
    }

    // ── task 24b: storage failure does not stop the job ──
    public function test_task24b_storage_failure_does_not_stop_prune(): void
    {
        $u = $this->staff(['ai_agent.use', 'inventory.all_branches']);
        $old = $this->oldConv($u, 181);
        $conv = $this->conv($u);
        $stuckSingle = $this->card($u, $conv, 'confirmed', 'attach_product_images', 11);

        $failingDisk = \Mockery::mock();
        $failingDisk->shouldReceive('deleteDirectory')->andThrow(new \RuntimeException('disk down'));
        $failingDisk->shouldReceive('delete')->andReturnTrue();
        Storage::shouldReceive('disk')->with('ai_private')->andReturn($failingDisk);

        (new PruneAiConversations)->handle(app(SettingService::class));

        // Conversation row still pruned and stuck card still failed.
        $this->assertNull(AiConversation::withTrashed()->find($old->id));
        $this->assertSame('failed', $stuckSingle->fresh()->status);
    }
}
