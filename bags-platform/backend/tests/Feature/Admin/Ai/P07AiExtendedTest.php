<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Ai;

use App\Jobs\PruneAiConversations;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\GeminiException;
use App\Services\Ai\ToolRegistry;
use App\Services\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt items 20, 21, 22/24/32/33/34/35 gaps plus section-3 AI edges.
 */
class P07AiExtendedTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled', 'ai_catalog_vision_enabled'])
            ->update(['value' => '1']);
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

    private function aiStaff(array $extra = []): User
    {
        return $this->staff(array_merge(['ai_agent.use', 'ai_agent.execute'], $extra));
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

    private function upload(User $u, AiConversation $c): AiAttachment
    {
        $token = $this->token($u);
        $response = $this->withToken($token)->post("/api/v1/admin/ai/conversations/{$c->id}/attachments",
            ['files' => [UploadedFile::fake()->image('photo.png', 1200, 900)], 'conversation_id' => $c->id]);
        $response->assertCreated();

        return AiAttachment::findOrFail($response->json('data.0.id'));
    }

    public function test_attachment_upload_accepts_csv_and_rejects_disguised_php(): void
    {
        $user = $this->aiStaff(['price_lists.update']);
        $conversation = $this->conv($user);
        $url = "/api/v1/admin/ai/conversations/{$conversation->id}/attachments";
        Storage::fake('ai_private');

        $response = $this->withToken($this->token($user))->post($url, [
            'files' => [UploadedFile::fake()->createWithContent('prices.csv', "sku,price\nABC,10\n")],
        ])->assertCreated();
        $attachment = AiAttachment::findOrFail($response->json('data.0.id'));
        $this->assertContains($attachment->mime, ['text/csv', 'text/plain']);

        $this->withToken($this->token($user))->post($url, [
            'files' => [UploadedFile::fake()->createWithContent('prices.csv', '<?php echo "test";')],
        ])->assertUnprocessable();
    }

    private function visionStaff(): User
    {
        return $this->aiStaff(['products.view', 'products.create', 'ai_agent.catalog.vision']);
    }

    private function visionSuccess(array $usage = ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15]): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'تحليل: حقيبة جلد بني']]]]],
            'usageMetadata' => $usage,
        ])]);
    }

    // ── item 20: B cannot analyze A's attachment ──
    public function test_cross_user_attachment_analysis_is_denied(): void
    {
        $a = $this->visionStaff();
        $b = $this->visionStaff();
        $convA = $this->conv($a);
        $convB = $this->conv($b);
        $attachment = $this->upload($a, $convA);
        $this->visionSuccess();

        $outcome = app(ToolRegistry::class)->dispatch($b, $convB, [
            'name' => 'analyze_product_photos', 'args' => ['attachment_ids' => [$attachment->id]]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertNull($attachment->fresh()->consumed_at);
    }

    // ── item 20: non-ULID attachment ids are rejected at the boundary ──
    public function test_non_ulid_attachment_ids_are_rejected(): void
    {
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages",
            ['text' => 'حلل الصورة', 'attachments' => ['not-a-ulid']])->assertStatus(422);
    }

    // ── item 21: successful analysis consumes the images ──
    public function test_successful_analysis_marks_attachments_consumed(): void
    {
        $u = $this->visionStaff();
        $conv = $this->conv($u);
        $a1 = $this->upload($u, $conv);
        $a2 = $this->upload($u, $conv);
        $this->visionSuccess();

        $outcome = app(ToolRegistry::class)->dispatch($u, $conv, [
            'name' => 'analyze_product_photos', 'args' => ['attachment_ids' => [$a1->id, $a2->id]]]);
        $this->assertSame('data', $outcome->type);
        $this->assertNotNull($a1->fresh()->consumed_at);
        $this->assertNotNull($a2->fresh()->consumed_at);
    }

    // ── item 21: quota counts pending images; the 41st blocks with 429 ──
    public function test_pending_quota_blocks_at_the_cap(): void
    {
        config(['services.gemini.max_pending_attachments_per_user' => 2]);
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        $this->upload($u, $conv);
        $this->upload($u, $conv);

        $this->withToken($this->token($u))->post("/api/v1/admin/ai/conversations/{$conv->id}/attachments",
            ['files' => [UploadedFile::fake()->image('third.png', 800, 600)], 'conversation_id' => $conv->id])
            ->assertStatus(429);
    }

    // ── item 21: prune keeps consumed images, deletes stale pending ones ──
    public function test_prune_keeps_consumed_images_and_deletes_stale_pending(): void
    {
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        $used = $this->upload($u, $conv);
        $used->forceFill(['created_at' => now()->subHours(25), 'consumed_at' => now()->subHours(25)])->save();
        $stale = $this->upload($u, $conv);
        $stale->forceFill(['created_at' => now()->subHours(25)])->save();
        $fresh = $this->upload($u, $conv);
        Storage::disk('ai_private')->assertExists($stale->path);
        Storage::disk('ai_private')->assertExists($used->path);

        // The default 1-day sweep deletes stale pending images (and their
        // files), keeps consumed ones, and leaves fresh pending ones alone.
        app(PruneAiConversations::class)->handle(app(SettingService::class));
        $this->assertNull(AiAttachment::find($stale->id));
        Storage::disk('ai_private')->assertMissing($stale->path);
        $this->assertNotNull(AiAttachment::find($used->id));
        Storage::disk('ai_private')->assertExists($used->path);
        $this->assertNotNull(AiAttachment::find($fresh->id));
    }

    // ── item 22: deleting a conversation does not reset the daily budget ──
    public function test_deleted_conversation_does_not_reset_daily_budget(): void
    {
        DB::table('settings')->where('key', 'ai_daily_token_budget_per_user')->update(['value' => '5000']);
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'model', 'content' => 'x',
            'input_tokens' => 4000, 'output_tokens' => 2000]);
        $conv->delete();

        try {
            app(AgentOrchestrator::class)->guard($u);
            $this->fail('expected budget rejection after conversation delete');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
    }

    // ── item 22: MAX_TOKENS failure still records an event with the tokens ──
    public function test_incomplete_finish_records_usage_event(): void
    {
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'نص ناقص']]]]],
            'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 20, 'totalTokenCount' => 50],
        ])]);

        try {
            app(AgentOrchestrator::class)->handle($u, $conv, ['text' => 'لخص التقرير']);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertNotNull($e->usage());
        }
        $event = AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'event')->firstOrFail();
        $this->assertSame(30, (int) $event->input_tokens);
        $this->assertSame(20, (int) $event->output_tokens);
        $this->assertStringContainsString('50', (string) $event->content);
        $this->assertSame(50, (int) $conv->fresh()->total_tokens);
    }

    // ── item 24: confirmed cards age out with their exact message; prune is idempotent ──
    public function test_prune_confirmed_cards_and_idempotency(): void
    {
        $u = $this->aiStaff(['products.update', 'products.view']);
        $conv = $this->conv($u);

        $mk = function (string $tool, string $status, int $minutesAgo, array $extra = []) use ($u, $conv): AiPendingAction {
            return AiPendingAction::forceCreate(array_merge([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'conversation_id' => $conv->id, 'user_id' => $u->id, 'tool' => $tool,
                'args' => ['x' => 1], 'args_hash' => 'h', 'expected_versions' => [],
                'preview' => [], 'risk' => 'medium', 'status' => $status,
                'expires_at' => now()->addHour(),
                'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
            ], $extra));
        };

        // A bulk confirmed 6 minutes ago fails with the exact card message.
        $bulk = $mk('bulk_actions', 'confirmed', 6, ['args' => ['action_ids' => []]]);
        // A normal confirmed card updated 11 minutes ago fails with the (misleading for non-image tools) message.
        $normal = $mk('update_variant', 'confirmed', 11);
        // A freshly confirmed card is untouched.
        $fresh = $mk('update_variant', 'confirmed', 1);
        // A pending card past its expiry lapses to expired.
        $pending = $mk('update_variant', 'pending', 20, ['expires_at' => now()->subMinute()]);

        app(PruneAiConversations::class)->handle(app(SettingService::class));

        $this->assertSame('failed', $bulk->fresh()->status);
        $this->assertSame('العملية الجماعية انتهت من غير تنفيذ كامل. اطلب اقتراح جديد.', $bulk->fresh()->result['message']);
        $this->assertSame('failed', $normal->fresh()->status);
        $this->assertSame('الحالة غير مؤكدة؛ راجع صور المنتج', $normal->fresh()->result['message']);
        $this->assertSame('confirmed', $fresh->fresh()->status);
        $this->assertSame('expired', $pending->fresh()->status);

        // Second run changes nothing.
        app(PruneAiConversations::class)->handle(app(SettingService::class));
        $this->assertSame('failed', $normal->fresh()->status);
        $this->assertSame('expired', $pending->fresh()->status);
    }

    // ── item 32: conversation access matrix ──
    public function test_conversation_access_matrix(): void
    {
        $a = $this->aiStaff();
        $b = $this->aiStaff();
        $convA = $this->conv($a);

        $this->withToken($this->token($b))->patchJson("/api/v1/admin/ai/conversations/{$convA->id}/pin", ['pinned' => true])->assertForbidden();
        $this->withToken($this->token($b))->postJson("/api/v1/admin/ai/conversations/{$convA->id}/messages", ['text' => 'hi'])->assertForbidden();
        $this->withToken($this->token($b))->postJson("/api/v1/admin/ai/conversations/{$convA->id}/stream", ['text' => 'hi'])->assertForbidden();
        $this->withToken($this->token($b))->getJson("/api/v1/admin/ai/conversations/{$convA->id}")->assertForbidden();
        // Owner access works.
        $this->withToken($this->token($a))->getJson("/api/v1/admin/ai/conversations/{$convA->id}")->assertOk();
        // Malformed ids resolve to 404, never 500.
        $this->withToken($this->token($a))->getJson('/api/v1/admin/ai/conversations/abc')->assertNotFound();
    }

    // ── item 33: tool catalog follows permissions and flags; hidden tools refuse dispatch ──
    public function test_tool_declarations_follow_permissions_and_flags(): void
    {
        $registry = app(ToolRegistry::class);

        // No execute permission at all: read tools visible, write tools hidden.
        $noExec = $this->staff(['ai_agent.use', 'products.view']);
        $readOnly = collect($registry->declarationsFor($noExec))->map(fn ($d) => $d['name'])->all();
        $this->assertContains('find_entities', $readOnly);
        $this->assertNotContains('bulk_update_prices', $readOnly);
        $this->assertNotContains('update_variant', $readOnly);

        // No products.update: bulk_update_prices hidden despite execute.
        $noCatalog = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $names = collect($registry->declarationsFor($noCatalog))->map(fn ($d) => $d['name'])->all();
        $this->assertNotContains('bulk_update_prices', $names);

        // Disabling hides the tool everywhere.
        DB::table('settings')->where('key', 'ai_disabled_tools')->update(['value' => json_encode(['update_variant'])]);
        app(SettingService::class)->clearCache();
        $exec = $this->aiStaff(['products.view', 'products.update']);
        $disabled = collect($registry->declarationsFor($exec))->map(fn ($d) => $d['name'])->all();
        $this->assertNotContains('update_variant', $disabled);

        // Direct dispatch of a hidden tool is denied and writes no audit row.
        DB::table('staff_audit_logs')->delete();
        $conv = $this->conv($exec);
        $denied = $registry->dispatch($exec, $conv, ['name' => 'update_variant', 'args' => ['variant_id' => 1]]);
        $this->assertSame('denied', $denied->type);
        $this->assertSame(0, DB::table('staff_audit_logs')->count());
    }

    // ── item 34: admin settings update guards ──
    public function test_admin_settings_update_guards(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.settings', 'inventory.all_branches']);
        $token = $this->token($admin);
        $url = '/api/v1/admin/ai/settings';

        // Missing expected block → 422.
        $this->withToken($token)->putJson($url, ['ai_action_ttl_minutes' => 30])->assertStatus(422);
        // Stale expected values → 409.
        $this->withToken($token)->putJson($url, [
            'expected' => ['ai_action_ttl_minutes' => '9999'],
            'ai_action_ttl_minutes' => 30,
        ])->assertStatus(409);
        // Non-ai keys are rejected, never stored.
        $this->withToken($token)->putJson($url, [
            'expected' => ['company_name' => 'x'], 'company_name' => 'y',
        ])->assertStatus(422);

        // Fresh expected values apply; repeating them immediately goes stale → 409.
        $current = (string) app(SettingService::class)->get('ai_action_ttl_minutes', '60');
        $payload = ['expected' => ['ai_action_ttl_minutes' => $current], 'ai_action_ttl_minutes' => 45];
        $this->withToken($token)->putJson($url, $payload)->assertOk();
        $this->assertSame('45', (string) app(SettingService::class)->get('ai_action_ttl_minutes'));
        $this->withToken($token)->putJson($url, $payload)->assertStatus(409);

        // Branch-restricted admins are refused at the gate.
        $branch = \App\Models\Branch::factory()->create();
        $restricted = $this->staff(['ai_agent.use', 'ai_agent.settings'], $branch->id);
        $this->withToken($this->token($restricted))->putJson($url, [
            'expected' => ['ai_action_ttl_minutes' => '45'], 'ai_action_ttl_minutes' => 50,
        ])->assertForbidden();
    }

    private function sseEvents(string $body): array
    {
        $events = [];
        foreach (explode("\n\n", str_replace("\r\n", "\n", $body)) as $frame) {
            if (preg_match('/^event: (\w+)$/m', $frame, $m) && preg_match('/^data: (.*)$/m', $frame, $d)) {
                $events[] = [$m[1], json_decode($d[1], true)];
            }
        }

        return $events;
    }

    // ── item 35 backend: SSE error mapping for 403 / 422 / 502 (real paths).
    // Frames carry data.status (the store JSON endpoint uses its own shape).
    // The stream may emit progress 'status' frames first: select the error frame.
    public function test_stream_error_mapping(): void
    {
        $tokenFor = fn (User $user): string => $this->token($user);
        $errorOf = function (string $body): array {
            foreach ($this->sseEvents($body) as [$event, $data]) {
                if ($event === 'error') {
                    return $data;
                }
            }
            $this->fail('expected an error frame in: '.$body);

            return [];
        };

        // 403: selection outside the user's read permissions (thrown inside handle).
        $noCatalog = $this->aiStaff();
        $convNoCatalog = $this->conv($noCatalog);
        $variant = \App\Models\ProductVariant::factory()->create();
        $error = $errorOf($this->withToken($tokenFor($noCatalog))->post(
            "/api/v1/admin/ai/conversations/{$convNoCatalog->id}/stream",
            ['selection' => ['entity' => 'variant', 'id' => $variant->id]])->streamedContent());
        $this->assertSame(403, $error['status']);

        // 422: empty turn reaches handleLocked, which requires message text.
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        $error = $errorOf($this->withToken($tokenFor($u))->post(
            "/api/v1/admin/ai/conversations/{$conv->id}/stream", [])->streamedContent());
        $this->assertSame(422, $error['status']);

        // 502 with provider failure: GeminiException maps to the generic stream message.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'backend exploded']], 500)]);
        $body = $this->withToken($tokenFor($u))->post(
            "/api/v1/admin/ai/conversations/{$conv->id}/stream", ['text' => 'hi'])->streamedContent();
        $error = $errorOf($body);
        $this->assertSame(502, $error['status']);
        $this->assertSame('تعذر إتمام طلب المساعد. حاول مرة تانية.', $error['message']);
        $this->assertStringNotContainsString('backend exploded', $body);

        // 502 with a generic failure: no internals leak into the stream.
        config(['services.gemini.api_key' => '']);
        $body = $this->withToken($tokenFor($u))->post(
            "/api/v1/admin/ai/conversations/{$conv->id}/stream", ['text' => 'hi'])->streamedContent();
        $error = $errorOf($body);
        $this->assertSame(502, $error['status']);
        $this->assertSame('تعذر إتمام طلب المساعد. حاول مرة تانية.', $error['message']);

        // The store JSON endpoint surfaces the provider message with a 502 code.
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'backend exploded']], 502)]);
        $this->withToken($tokenFor($u))->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'hi'])
            ->assertStatus(502)->assertJsonFragment(['message' => 'تعذّر تنفيذ طلب Gemini.']);
    }

    // ── item 39: the evaluation fixture covers ≥220 cases and validates ──
    public function test_evaluate_fixture_covers_minimum_cases(): void
    {
        $this->artisan('ai:evaluate', ['--check' => true])->assertSuccessful();
    }

    // ── section 3: oversized inputs are rejected at the message boundary ──
    public function test_oversized_message_inputs_are_rejected(): void
    {
        $u = $this->aiStaff(['products.view']);
        $conv = $this->conv($u);
        $token = $this->token($u);
        $url = "/api/v1/admin/ai/conversations/{$conv->id}/messages";

        $this->withToken($token)->postJson($url, ['text' => str_repeat('أ', 8001)])->assertStatus(422);
        $mentions = [];
        for ($i = 0; $i < 26; $i++) {
            $mentions[] = ['entity' => 'product', 'id' => $i + 1];
        }
        $this->withToken($token)->postJson($url, ['text' => 'hi', 'mentions' => $mentions])->assertStatus(422);
        $attachments = [];
        for ($i = 0; $i < 5; $i++) {
            $attachments[] = (string) \Illuminate\Support\Str::ulid();
        }
        $this->withToken($token)->postJson($url, ['text' => 'hi', 'attachments' => $attachments])->assertStatus(422);
    }

    // ── section 3: usage stats carry failed_409 counts and needs_attention ──
    public function test_usage_stats_and_needs_attention(): void
    {
        $u = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'inventory.all_branches']);
        $conv = $this->conv($u);
        $mk = function (string $status, ?bool $conflict = null) use ($u, $conv): void {
            $result = $conflict === null ? null : ['conflict_409' => $conflict];
            AiPendingAction::forceCreate(['id' => (string) \Illuminate\Support\Str::ulid(),
                'conversation_id' => $conv->id, 'user_id' => $u->id, 'tool' => 'update_variant',
                'args' => ['x' => 1], 'args_hash' => 'h', 'expected_versions' => [],
                'preview' => [], 'risk' => 'low', 'status' => $status, 'result' => $result,
                'expires_at' => now()->addHour()]);
        };
        $mk('executed');
        $mk('cancelled');
        $mk('cancelled');
        $mk('failed', true);
        $mk('failed', false);

        $stats = $this->withToken($this->token($u))->getJson('/api/v1/admin/ai/usage?all=1')->assertOk()->json('tool_stats');
        $this->assertSame(5, $stats['total_proposals']);
        $this->assertSame(1, $stats['failed_409_count']);
        $tool = collect($stats['tools'])->firstWhere('tool', 'update_variant');
        $this->assertTrue($tool['needs_attention']);
        $this->assertSame(66.7, $tool['cancel_rate']);
    }

    // ── section 3: generated passwords appear once, never in stored state ──
    public function test_generated_password_never_persisted(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'users.create', 'users.manage', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'create_staff_user',
            'args' => ['name' => 'م م', 'email' => 'mm'.uniqid().'@example.com']]);
        $this->assertSame('proposal', $prop->type);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);

        $res = $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm");
        $res->assertOk();
        $password = $res->json('data.result.generated_password');
        $this->assertIsString($password);
        $this->assertNotSame('', $password);

        $stored = json_encode($action->fresh()->result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($password, (string) $stored);
        $storedInverse = json_encode($action->fresh()->inverse, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($password, (string) $storedInverse);
    }
}
