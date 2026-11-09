<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Jobs\PruneAiConversations;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Catalog\ProductMediaService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 19-20: assistant image attachments (ownership, quota,
 * size, consumption) + outside-transaction attach (failure, prune recovery,
 * idempotent retry).
 */
class TaskTxtAttachmentsTest extends TestCase
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
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Storage::fake('ai_private');
        Storage::fake('r2_public');
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function agentUser(array $extra = []): User
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

    private function img(string $name = 'p.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function upload(User $u, AiConversation $conv, array $files): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token($u))
            ->post("/api/v1/admin/ai/conversations/{$conv->id}/attachments", ['files' => $files]);
    }

    // ── task 19a: upload to someone else's conversation → 403 ──
    public function test_task19a_upload_to_foreign_conversation_forbidden(): void
    {
        $owner = $this->agentUser();
        $intruder = $this->agentUser();
        $conv = $this->conv($owner);

        $this->upload($intruder, $conv, [$this->img()])->assertStatus(403);
        $this->assertSame(0, AiAttachment::count());
    }

    // ── task 19b: 5 files / disguised php → 422 ──
    public function test_task19b_file_count_and_type_guards(): void
    {
        $u = $this->agentUser();
        $conv = $this->conv($u);

        $this->upload($u, $conv, [$this->img(), $this->img(), $this->img(), $this->img(), $this->img()])
            ->assertStatus(422);

        $this->upload($u, $conv, [UploadedFile::fake()->image('evil.php')])
            ->assertStatus(422);

        $this->assertSame(0, AiAttachment::count());
    }

    // ── task 19c: pending quota → 429 ──
    public function test_task19c_pending_quota_returns_429(): void
    {
        config(['services.gemini.max_pending_attachments_per_user' => 2]);
        $u = $this->agentUser();
        $conv = $this->conv($u);

        $this->upload($u, $conv, [$this->img()])->assertCreated();
        $this->upload($u, $conv, [$this->img()])->assertCreated();
        $this->upload($u, $conv, [$this->img()])->assertStatus(429);
        $this->assertSame(2, AiAttachment::where('user_id', $u->id)->whereNull('consumed_at')->count());
    }

    // ── task 19d: analyze with attachment from another conversation → rejected ──
    public function test_task19d_foreign_conversation_attachment_rejected_for_analyze(): void
    {
        $u = $this->agentUser(['ai_agent.catalog.vision', 'products.create', 'products.view']);
        $convA = $this->conv($u);
        $convB = $this->conv($u);

        $res = $this->upload($u, $convA, [$this->img()])->assertCreated();
        $foreignId = $res->json('data.0.id');

        $out = app(ToolRegistry::class)->dispatch($u, $convB, [
            'name' => 'analyze_product_photos',
            'args' => ['attachment_ids' => [$foreignId]],
        ]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('لا تخص هذه المحادثة', $out->message);
    }

    // ── task 19e: analyze without vision permission → denied ──
    public function test_task19e_analyze_requires_vision_permission(): void
    {
        $u = $this->agentUser(['products.create']);
        $conv = $this->conv($u);
        $res = $this->upload($u, $conv, [$this->img()])->assertCreated();

        $out = app(ToolRegistry::class)->dispatch($u, $conv, [
            'name' => 'analyze_product_photos',
            'args' => ['attachment_ids' => [$res->json('data.0.id')]],
        ]);
        $this->assertSame('denied', $out->type);
    }

    // ── task 19f: 4.5MB image — upload OK (5120KB), analyze reports size clearly ──
    public function test_task19f_oversize_image_reports_clear_size_error(): void
    {
        $u = $this->agentUser(['ai_agent.catalog.vision', 'products.create', 'products.view']);
        $conv = $this->conv($u);

        // 4600KB passes the 5120KB upload cap…
        $big = UploadedFile::fake()->create('big.jpg', 4600, 'image/jpeg');
        $res = $this->upload($u, $conv, [$big])->assertCreated();

        // …but Gemini accepts max 4MB. The tool must fail loudly with the
        // size reason (decision recorded: clear message instead of generic
        // 502 — the uploader cap stays 5120KB).
        $out = app(ToolRegistry::class)->dispatch($u, $conv, [
            'name' => 'analyze_product_photos',
            'args' => ['attachment_ids' => [$res->json('data.0.id')]],
        ]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('4MB', $out->message);
    }

    private function productFixture(): Product
    {
        $cat = Category::factory()->create(['is_active' => true]);
        static $n = 99000;
        $n++;

        return Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);
    }

    private function attachProposal(User $u, AiConversation $conv, Product $product, array $attIds): AiPendingAction
    {
        $prop = app(ToolRegistry::class)->dispatch($u, $conv, [
            'name' => 'attach_product_images',
            'args' => ['product_id' => $product->id, 'attachment_ids' => $attIds],
        ]);
        $this->assertSame('proposal', $prop->type);

        return AiPendingAction::findOrFail($prop->data['pending_action_id']);
    }

    // ── task 19g + 20c: attach → consumed_at set, retry makes no duplicates ──
    public function test_task19g_attach_consumes_and_retry_is_idempotent(): void
    {
        $u = $this->agentUser(['products.view', 'products.update']);
        $conv = $this->conv($u);
        $product = $this->productFixture();

        $res = $this->upload($u, $conv, [$this->img('a.jpg'), $this->img('b.jpg')])->assertCreated();
        $ids = [$res->json('data.0.id'), $res->json('data.1.id')];

        $action = $this->attachProposal($u, $conv, $product, $ids);
        app(PendingActionService::class)->confirm($u, $action->fresh());
        $this->assertSame('executed', $action->fresh()->status);
        $this->assertSame(2, AiAttachment::whereIn('id', $ids)->whereNotNull('consumed_at')->count());
        $mediaCount = \Spatie\MediaLibrary\MediaCollections\Models\Media::where('model_type', Product::class)
            ->where('model_id', $product->id)->where('collection_name', 'images')->count();
        $this->assertSame(2, $mediaCount);

        // Same attachments proposed + confirmed again → winner replayed, no dupes.
        $retry = $this->attachProposal($u, $conv, $product, $ids);
        app(PendingActionService::class)->confirm($u, $retry->fresh());
        $this->assertSame('executed', $retry->fresh()->status);
        $this->assertSame(2, \Spatie\MediaLibrary\MediaCollections\Models\Media::where('model_type', Product::class)
            ->where('model_id', $product->id)->where('collection_name', 'images')->count());
    }

    // ── task 20a: upload throws mid-execute → stuck confirmed, attachments untouched ──
    public function test_task20a_execute_failure_leaves_confirmed_and_unconsumed(): void
    {
        $u = $this->agentUser(['products.view', 'products.update']);
        $conv = $this->conv($u);
        $product = $this->productFixture();

        $res = $this->upload($u, $conv, [$this->img()])->assertCreated();
        $ids = [$res->json('data.0.id')];

        // Mock BEFORE proposing: the registry memoizes tool instances, so a
        // mock registered after dispatch would never reach execute().
        $this->mock(ProductMediaService::class, function ($mock): void {
            $mock->shouldReceive('upload')->once()->andThrow(
                new \App\Exceptions\BusinessException('تعذّر الرفع لـ R2.', [], 500)
            );
        });
        $action = $this->attachProposal($u, $conv, $product, $ids);

        $out = app(PendingActionService::class)->confirm($u, $action->fresh());
        // task.txt §2-20: on failure the card is failed with a message and
        // attachments stay unconsumed (only the stuck-confirmed path waits
        // for prune — see 20b).
        $this->assertSame('failed', $action->fresh()->status);
        $this->assertNotNull($action->fresh()->result['message'] ?? null);
        $this->assertNull(AiAttachment::find($ids[0])->consumed_at);
    }

    // ── task 20b: prune after 11 min fails the stuck confirmed card ──
    public function test_task20b_prune_fails_stuck_confirmed_after_10_minutes(): void
    {
        $u = $this->agentUser(['products.view', 'products.update']);
        $conv = $this->conv($u);
        $product = $this->productFixture();

        $res = $this->upload($u, $conv, [$this->img()])->assertCreated();
        $action = $this->attachProposal($u, $conv, $product, [$res->json('data.0.id')]);
        $action->forceFill(['status' => 'confirmed', 'updated_at' => now()->subMinutes(11)])->save();

        (new PruneAiConversations)->handle(app(SettingService::class));

        $this->assertSame('failed', $action->fresh()->status);
    }
}
