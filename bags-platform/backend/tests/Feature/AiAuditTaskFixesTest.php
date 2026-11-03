<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Regression tests for the task.txt audit batch (H1-H3, M1-M10, L1-L6).
 */
class AiAuditTaskFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_catalog_tools_enabled',
            'ai_catalog_vision_enabled',
            'ai_catalog_copywriting_enabled',
            'ai_customer_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    // ── H1: bulk_child is a legal status value ──
    public function test_h1_bulk_child_status_is_accepted_by_the_check_constraint(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $conv = $this->conv($admin);
        $action = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'set_location_status',
            'args' => ['location_id' => 1, 'active' => false],
            'args_hash' => 'x',
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'medium',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $action->forceFill(['status' => 'bulk_child'])->save();

        $this->assertSame('bulk_child', $action->fresh()->status);
        $this->assertDatabaseHas('ai_pending_actions', ['id' => $action->id, 'status' => 'bulk_child']);
    }

    // ── H2: lock contention inside a bulk confirm is retryable, never failed ──
    public function test_h2_bulk_child_lock_conflict_resets_parent_and_stays_retryable(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'locations.update']);
        $conv = $this->conv($admin);

        $registry = new ToolRegistry(
            app(EntityResolver::class),
            app(BranchAccess::class),
            app(SettingService::class),
            [new ThrowingBulkChildProvider]
        );
        $service = new PendingActionService($registry, app(SettingService::class));

        $childArgs = ['location_id' => 7, 'active' => false];
        $child = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'set_location_status',
            'args' => $childArgs,
            'args_hash' => $registry->hashArgs($childArgs),
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'low',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(15),
        ]);
        $parentArgs = ['action_ids' => [$child->id]];
        $parent = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'bulk_actions',
            'args' => $parentArgs,
            'args_hash' => $registry->hashArgs($parentArgs),
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'high',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            $service->confirm($admin, $parent, 'تنفيذ');
            $this->fail('Expected a retryable 409 conflict.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // Nothing was marked failed: the parent is claimable again and the
        // child is still pending.
        $this->assertSame('pending', $parent->fresh()->status);
        $this->assertSame('pending', $child->fresh()->status);
    }

    // ── M1: a SQL error in one bulk item must not poison the whole prepare ──
    public function test_m1_sql_error_in_one_bulk_item_denies_cleanly_and_cancels_siblings(): void
    {
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => false]);
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.all_branches', 'locations.update', 'customers.approve']);
        $conv = $this->conv($admin);

        $registry = new ToolRegistry(
            app(EntityResolver::class),
            app(BranchAccess::class),
            app(SettingService::class),
            [new ThrowingPrepareProvider]
        );

        $outcome = $registry->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'set_location_status', 'args' => ['location_id' => $location->id, 'active' => true]],
            ['tool' => 'set_customer_group', 'args' => ['customer_id' => 1, 'customer_group_id' => 1]],
        ]]]);

        // Clean denial — no 25P02/502 leaking out of the poisoned transaction.
        $this->assertSame('denied', $outcome->type);
        $this->assertSame(
            'تعذّر تجهيز أحد عناصر العملية الجماعية بسبب خطأ في قاعدة البيانات؛ راجع العناصر وحاول مجددًا.',
            $outcome->message
        );
        // The healthy sibling proposal was rolled back to cancelled.
        $this->assertSame(0, AiPendingAction::where('tool', 'set_location_status')->where('status', 'pending')->count());
        $this->assertSame(1, AiPendingAction::where('tool', 'set_location_status')->where('status', 'cancelled')->count());
        // The outer transaction stayed usable.
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    // ── H3: uploads land on the shared private disk, not local ──
    public function test_h3_attachment_upload_uses_shared_private_disk(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $token = $this->token($admin);

        $res = $this->withToken($token)->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/attachments",
            ['files' => [\Illuminate\Http\UploadedFile::fake()->image('bag.jpg', 600, 600)]]
        )->assertCreated();

        $attachmentId = $res->json('data.0.id');
        $att = AiAttachment::findOrFail($attachmentId);
        $this->assertTrue(
            \Illuminate\Support\Facades\Storage::disk('ai_private')->exists($att->path),
            'Attachment file must live on the shared private disk.'
        );
    }

    // ── L5: conversation creation is throttled ──
    public function test_l5_conversation_store_is_rate_limited(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $token = $this->token($admin);

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->postJson('/api/v1/admin/ai/conversations', [])->assertCreated();
        }
        $this->withToken($token)->postJson('/api/v1/admin/ai/conversations', [])->assertTooManyRequests();
    }

    // ── L1: a DB failure after the file write leaves no orphan file ──
    public function test_l1_failed_attachment_row_deletes_the_written_file(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $token = $this->token($admin);

        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: '.AiAttachment::class,
            function (): void {
                throw new \RuntimeException('simulated row failure');
            }
        );
        try {
            $this->withToken($token)->postJson(
                "/api/v1/admin/ai/conversations/{$conv->id}/attachments",
                ['files' => [\Illuminate\Http\UploadedFile::fake()->image('orphan.jpg', 100, 100)]]
            )->assertServerError();
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: '.AiAttachment::class);
        }

        $this->assertDatabaseCount('ai_attachments', 0);
        $this->assertSame(
            [],
            \Illuminate\Support\Facades\Storage::disk('ai_private')->allFiles("ai-attachments/{$conv->id}")
        );
    }

    // ── L1: quota + ai_enabled guard on uploads ──
    public function test_l1_attachment_upload_quota_and_disabled_guard(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        $url = "/api/v1/admin/ai/conversations/{$conv->id}/attachments";
        $file = fn () => ['files' => [\Illuminate\Http\UploadedFile::fake()->image('bag.jpg', 100, 100)]];

        // Fill the unconsumed quota.
        $quota = (int) config('services.gemini.max_pending_attachments_per_user', 40);
        for ($i = 0; $i < $quota; $i++) {
            AiAttachment::create([
                'conversation_id' => $conv->id,
                'user_id' => $admin->id,
                'path' => "ai-attachments/{$conv->id}/quota-{$i}.jpg",
                'mime' => 'image/jpeg',
                'size' => 10,
                'sha256' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
            ]);
        }
        $this->withToken($token)->postJson($url, $file())->assertStatus(429);

        // Consuming one frees a slot.
        AiAttachment::query()->where('user_id', $admin->id)->limit(1)->update(['consumed_at' => now()]);
        $this->withToken($token)->postJson($url, $file())->assertCreated();

        // With the assistant disabled, uploads are refused even under quota.
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        AiAttachment::query()->where('user_id', $admin->id)->update(['consumed_at' => now()]);
        $this->withToken($token)->postJson($url, $file())->assertForbidden();
    }

    // ── M4: conversation forceDelete removes attachment files ──
    public function test_m4_prune_conversation_deletes_attachment_files(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        $disk = \Illuminate\Support\Facades\Storage::disk('ai_private');
        $disk->put("ai-attachments/{$conv->id}/old.jpg", 'bytes');
        AiAttachment::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'path' => "ai-attachments/{$conv->id}/old.jpg",
            'mime' => 'image/jpeg',
            'size' => 5,
            'sha256' => str_repeat('a', 64),
            'consumed_at' => now()->subHours(2),
        ]);

        $conv->forceFill(['last_message_at' => now()->subDays(400), 'created_at' => now()->subDays(400)])->save();
        DB::table('ai_conversations')->where('id', $conv->id)->update([
            'created_at' => now()->subDays(400), 'last_message_at' => now()->subDays(400),
        ]);

        app(\App\Jobs\PruneAiConversations::class)->handle(app(SettingService::class));

        $this->assertDatabaseMissing('ai_conversations', ['id' => $conv->id]);
        $this->assertDatabaseMissing('ai_attachments', ['conversation_id' => $conv->id]);
        $this->assertFalse($disk->exists("ai-attachments/{$conv->id}/old.jpg"));
        $this->assertSame([], $disk->allFiles("ai-attachments/{$conv->id}"));
    }

    // ── L3: execute() only reads the action owner's attachments ──
    public function test_l3_attach_execute_ignores_other_users_attachments(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $owner = $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.update']);
        $stranger = $this->staff(['ai_agent.use']);
        $conv = $this->conv($owner);
        $cat = \App\Models\Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'code' => 'L30001']);
        // The file exists on every disk: without the user_id scope the old
        // code would happily upload the stranger's file.
        $fakeImage = \Illuminate\Http\UploadedFile::fake()->image('x.jpg', 100, 100);
        $bytes = (string) $fakeImage->getContent();
        \Illuminate\Support\Facades\Storage::disk('ai_private')->put("ai-attachments/{$conv->id}/x.jpg", $bytes);
        \Illuminate\Support\Facades\Storage::disk('local')->put("ai-attachments/{$conv->id}/x.jpg", $bytes);
        $foreign = AiAttachment::create([
            'conversation_id' => $this->conv($stranger)->id,
            'user_id' => $stranger->id,
            'path' => "ai-attachments/{$conv->id}/x.jpg",
            'mime' => 'image/jpeg',
            'size' => 5,
            'sha256' => str_repeat('b', 64),
        ]);

        $action = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $owner->id,
            'tool' => 'attach_product_images',
            'args' => ['product_id' => $product->id, 'variant_id' => null, 'attachment_ids' => [$foreign->id]],
            'args_hash' => 'x',
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'low',
            'status' => 'confirmed',
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            app(\App\Services\Ai\Catalog\Tools\AttachProductImagesTool::class)->execute($owner, $action);
            $this->fail('Expected a BusinessException for a foreign attachment.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame('أحد ملفات الصور المرفقة لم يعد موجودًا؛ ارفع الصور من جديد.', $e->getMessage());
        }

        // Nothing was uploaded and the foreign row was left untouched.
        $this->assertDatabaseCount('media', 0);
        $this->assertNull($foreign->fresh()->consumed_at);
    }

    // ── M3: analyze requires a conversation (no silent unbilled tokens) ──
    public function test_m3_analyze_requires_conversation(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.catalog.vision', 'products.create']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Ai\Catalog\Tools\AnalyzeProductPhotosTool::class)
            ->handle($admin, ['attachment_ids' => ['01J0000000000000000000000']]);
    }

    // ── M3: vision tokens count toward the per-cycle cap ──
    public function test_m3_vision_tokens_count_toward_cycle_cap(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.catalog.vision', 'products.create']);
        $conv = $this->conv($admin);
        $token = $this->token($admin);

        // Upload one image into the conversation.
        $upload = $this->withToken($token)->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/attachments",
            ['files' => [\Illuminate\Http\UploadedFile::fake()->image('bag.jpg', 200, 200)]]
        )->assertCreated();
        $attachmentId = $upload->json('data.0.id');

        // A tight cycle cap: the small converse calls fit, the vision call does not.
        DB::table('settings')->where('key', 'ai_max_tokens_per_cycle')->update(['value' => '1000']);
        app(SettingService::class)->clearCache();

        $call = ['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [[
                'functionCall' => ['name' => 'analyze_product_photos', 'args' => ['attachment_ids' => [$attachmentId]]],
                'thoughtSignature' => 'sig',
            ]]],
        ]], 'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 10, 'totalTokenCount' => 50]];
        $vision = ['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [['text' => '{"product_type":"bag","suggested_name_ar":"شنطة","suggested_name_en":"Bag","colors":[],"material_guess":"","features_ar":[],"features_en":[],"description_ar":"","description_en":"","suggested_category_keywords":[],"confidence":0.9,"quality_issues":[]}']]],
        ]], 'usageMetadata' => ['promptTokenCount' => 4000, 'candidatesTokenCount' => 1000, 'totalTokenCount' => 5000]];
        $textReply = ['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [['text' => 'تمام']]],
        ]], 'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 10, 'totalTokenCount' => 50]];
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::sequence()
                ->push($call)->push($vision)->push($textReply),
        ]);

        $res = $this->withToken($token)->postJson(
            "/api/v1/admin/ai/conversations/{$conv->id}/messages",
            ['text' => 'حلّل الصورة', 'attachments' => [$attachmentId]]
        )->assertOk();

        // The 5000 vision tokens trip the 1000-token cycle cap at step two —
        // without DB-based accounting the loop would have sailed through.
        $this->assertStringContainsString('سقف التوكنز', (string) $res->json('data.text'));
        \Illuminate\Support\Facades\Http::assertSentCount(2);
        $this->assertSame(
            5050,
            (int) \App\Models\AiMessage::query()
                ->where('conversation_id', $conv->id)
                ->where('role', '!=', 'user')
                ->sum(DB::raw('input_tokens + output_tokens'))
        );
    }

    // ── M5: variant updates require expected_version (price-loss guard) ──
    public function test_m5_variant_update_requires_expected_version_and_logs_transition(): void
    {
        $admin = $this->staff(['products.update']);
        $token = $this->token($admin);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100]);
        $url = "/api/v1/admin/products/{$product->id}/variants/{$variant->id}";

        $handler = new \Monolog\Handler\TestHandler();
        \Illuminate\Support\Facades\Log::getLogger()->pushHandler($handler);

        // No version: rejected, and the transition warning is logged.
        $this->withToken($token)->putJson($url, ['retail_price' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertSame(100.0, (float) $variant->fresh()->retail_price);
        $this->assertTrue($handler->hasWarningRecords());
        $logged = collect($handler->getRecords())->firstWhere('message', 'variant.update_without_expected_version');
        $this->assertNotNull($logged);
        $this->assertSame($variant->id, $logged['context']['variant_id'] ?? null);

        // Fresh version: accepted.
        $this->withToken($token)->putJson($url, ['retail_price' => 50, 'expected_version' => $variant->fresh()->lock_version])
            ->assertOk();
        $this->assertSame(50.0, (float) $variant->fresh()->retail_price);

        // Stale version still conflicts.
        $this->withToken($token)->putJson($url, ['retail_price' => 70, 'expected_version' => 0])
            ->assertStatus(409);
    }

    // ── L4: a bulk with zero successes is failed, not executed ──
    public function test_l4_bulk_with_zero_successes_is_marked_failed(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'locations.update']);
        $conv = $this->conv($admin);

        $registry = new ToolRegistry(
            app(EntityResolver::class),
            app(BranchAccess::class),
            app(SettingService::class),
            [new FailingBulkChildProvider]
        );
        $service = new PendingActionService($registry, app(SettingService::class));

        $childArgs = ['location_id' => 7, 'active' => false];
        $child = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'set_location_status',
            'args' => $childArgs,
            'args_hash' => $registry->hashArgs($childArgs),
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'low',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(15),
        ]);
        $parentArgs = ['action_ids' => [$child->id]];
        $parent = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'bulk_actions',
            'args' => $parentArgs,
            'args_hash' => $registry->hashArgs($parentArgs),
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'high',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $service->confirm($admin, $parent, 'تنفيذ');

        $this->assertSame('failed', $parent->fresh()->status);
        $this->assertSame('failed', $child->fresh()->status);
        $this->assertFalse((bool) ($parent->fresh()->result['ok'] ?? true));
    }

    // ── M8: the concurrency slot is held during the cycle and released after ──
    public function test_m8_slot_held_during_cycle_and_released_after(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'أهلًا']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ]),
        ]);

        $heldDuring = null;
        app(AgentOrchestrator::class)->handle($admin, $conv, ['text' => 'ping'], function () use (&$heldDuring): void {
            if ($heldDuring === null) {
                // The in-cycle lock is held: even the owner cannot re-acquire it.
                $heldDuring = ! (bool) \Illuminate\Support\Facades\Cache::lock('ai-agent:concurrent-slot:0', 10)->get();
            }
        });

        $this->assertTrue($heldDuring, 'The slot must be held while the cycle runs.');
        $this->assertTrue(
            (bool) \Illuminate\Support\Facades\Cache::lock('ai-agent:concurrent-slot:0', 10)->get(),
            'The slot must be released after the cycle.'
        );
    }

    // ── M9: reorder refuses foreign media at prepare time ──
    public function test_m9_reorder_rejects_foreign_media_at_prepare(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.update']);
        $conv = $this->conv($admin);
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $fake = fn () => \Illuminate\Http\UploadedFile::fake()->image('m.jpg', 100, 100);

        $foreign = $productB->addMedia($fake())->toMediaCollection('images');
        $registry = app(ToolRegistry::class);

        $deniedCover = $registry->dispatch($admin, $conv, [
            'name' => 'reorder_product_media',
            'args' => ['product_id' => $productA->id, 'cover_media_id' => $foreign->id],
        ]);
        $this->assertSame('denied', $deniedCover->type);
        $this->assertSame('الصورة لا تتبع هذا المنتج.', $deniedCover->message);

        $deniedIds = $registry->dispatch($admin, $conv, [
            'name' => 'reorder_product_media',
            'args' => ['product_id' => $productA->id, 'ids' => [$foreign->id]],
        ]);
        $this->assertSame('denied', $deniedIds->type);

        // Own media still builds a review card.
        $own = $productA->addMedia($fake())->toMediaCollection('images');
        $prop = $registry->dispatch($admin, $conv, [
            'name' => 'reorder_product_media',
            'args' => ['product_id' => $productA->id, 'cover_media_id' => $own->id],
        ]);
        $this->assertSame('proposal', $prop->type);
    }

    // ── L2: the daily budget covers the whole DST fallback day ──
    public function test_l2_daily_budget_counts_entire_dst_fallback_day(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);

        // 2026-10-29 is a 25-hour day in Cairo (clocks fall back at midnight).
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-29 23:45:00', 'Africa/Cairo'));
        try {
            $msg = AiMessage::create([
                'conversation_id' => $conv->id,
                'role' => 'model',
                'content' => 'x',
                'input_tokens' => 60,
                'output_tokens' => 40,
            ]);
            // 23:30 Cairo (+02) = 21:30 UTC — inside the Cairo day, but past
            // a naive +24h UTC window. (Eloquent drops the offset on save,
            // so the true instant is written in UTC directly.)
            $msg->forceFill(['created_at' => \Carbon\Carbon::parse('2026-10-29 21:30:00', 'UTC')])->save();

            DB::table('settings')->where('key', 'ai_daily_token_budget_per_user')->update(['value' => '100']);
            app(SettingService::class)->clearCache();

            try {
                app(AgentOrchestrator::class)->guard($admin);
                $this->fail('Expected a 429 over the daily budget.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                $this->assertSame(429, $e->getStatusCode());
            }
        } finally {
            \Carbon\Carbon::setTestNow(null);
        }
    }

    // ── M7: catalog confirms do not take the global advisory lock ──
    public function test_m7_catalog_confirm_succeeds_while_global_lock_is_held(): void
    {
        config(['database.connections.pgsql_held' => config('database.connections.pgsql')]);
        try {
            DB::connection('pgsql_held')->select('SELECT pg_advisory_lock(79301, 1)');

            $admin = $this->staff([
                'ai_agent.use', 'ai_agent.execute',
                'categories.view', 'categories.create',
            ]);
            $conv = $this->conv($admin);
            $registry = app(ToolRegistry::class);

            $prop = $registry->dispatch($admin, $conv, [
                'name' => 'create_category',
                'args' => ['name_ar' => 'شنط M7', 'name_en' => 'M7 Bags', 'slug' => 'm7-bags'],
            ]);
            $this->assertSame('proposal', $prop->type, 'setup failed: '.($prop->message ?? ''));
            $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);

            $result = app(PendingActionService::class)->confirm($admin, $action->fresh());

            $this->assertSame('result', $result->type);
            $this->assertSame('executed', $action->fresh()->status);
            $this->assertDatabaseHas('categories', ['slug' => 'm7-bags']);
        } finally {
            DB::disconnect('pgsql_held');
        }
    }
}

/** M1 fixture: a provider whose prepare() blows up with a SQL error. */
final class ThrowingPrepareProvider implements ToolProvider
{
    public function tools(): array
    {
        return ['set_customer_group' => new ThrowingPrepareTool];
    }

    public function domain(): string
    {
        return 'test';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        return null;
    }

    public function systemPromptSection(User $actor): ?string
    {
        return null;
    }
}

final class ThrowingPrepareTool implements WriteTool
{
    public function name(): string
    {
        return 'set_customer_group';
    }

    public function declaration(): array
    {
        return ['name' => 'set_customer_group', 'description' => 'test double', 'parameters' => ['type' => 'OBJECT']];
    }

    public function permissions(): array
    {
        return ['customers.approve'];
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        // A genuine SQL failure: on PostgreSQL it aborts the enclosing
        // transaction, so every later query in it fails with 25P02 until
        // rollback — exactly the M1 production scenario.
        \Illuminate\Support\Facades\DB::statement('SELECT * FROM ai_missing_table_boom');

        return ToolOutcome::denied('unreachable', auditable: false);
    }

    public function execute(User $actor, \App\Models\AiPendingAction $action): ToolOutcome
    {
        return ToolOutcome::denied('not used in this test', auditable: false);
    }
}

/** L4 fixture: a provider whose child execute() fails non-retryably. */
final class FailingBulkChildProvider implements ToolProvider
{
    public function tools(): array
    {
        return ['set_location_status' => new FailingBulkChildTool];
    }

    public function domain(): string
    {
        return 'test';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        return null;
    }

    public function systemPromptSection(User $actor): ?string
    {
        return null;
    }
}

final class FailingBulkChildTool implements WriteTool
{
    public function name(): string
    {
        return 'set_location_status';
    }

    public function declaration(): array
    {
        return ['name' => 'set_location_status', 'description' => 'test double', 'parameters' => ['type' => 'OBJECT']];
    }

    public function permissions(): array
    {
        return ['locations.update'];
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        return ToolOutcome::denied('not used in this test', auditable: false);
    }

    public function execute(User $actor, \App\Models\AiPendingAction $action): ToolOutcome
    {
        throw new \App\Exceptions\BusinessException('تعذّر التنفيذ.');
    }
}

/** H2 fixture: a provider whose child execute() hits a lock conflict. */
final class ThrowingBulkChildProvider implements ToolProvider
{
    public function tools(): array
    {
        return ['set_location_status' => new ThrowingBulkChildTool];
    }

    public function domain(): string
    {
        return 'test';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        return null;
    }

    public function systemPromptSection(User $actor): ?string
    {
        return null;
    }
}

final class ThrowingBulkChildTool implements WriteTool
{
    public function name(): string
    {
        return 'set_location_status';
    }

    public function declaration(): array
    {
        return ['name' => 'set_location_status', 'description' => 'test double', 'parameters' => ['type' => 'OBJECT']];
    }

    public function permissions(): array
    {
        return ['locations.update'];
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        return ToolOutcome::denied('not used in this test', auditable: false);
    }

    public function execute(User $actor, \App\Models\AiPendingAction $action): ToolOutcome
    {
        $previous = new \PDOException('canceling statement due to lock timeout');
        $previous->errorInfo = ['55P03', 7, 'canceling statement due to lock timeout'];

        throw new \App\Exceptions\RetryableConflict('السجل مشغول بتعديل تاني؛ مفيش حاجة اتنفذت. جرّب التأكيد تاني بعد لحظة.', $previous);
    }
}
