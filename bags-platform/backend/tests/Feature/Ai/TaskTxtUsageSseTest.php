<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 27-28: usage stats (Cairo-day grouping, true 409
 * counting) and SSE streaming (frames, error mapping, 423, parseable
 * contract for the missing frontend parser).
 */
class TaskTxtUsageSseTest extends TestCase
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
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function agentUser(): User
    {
        return $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function geminiText(string $text = 'تمام.'): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]];
    }

    private function messageAt(AiConversation $conv, \DateTimeInterface $at, int $tokens = 10): void
    {
        $msg = AiMessage::create(['conversation_id' => $conv->id, 'role' => 'model', 'content' => 'x',
            'input_tokens' => $tokens - 2, 'output_tokens' => 2]);
        $msg->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    // ── task 27a: usage grouped by Cairo day (normal + DST days) ──
    public function test_task27a_usage_grouped_by_cairo_day(): void
    {
        $admin = $this->agentUser();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);

        // A message at 22:30 UTC lands on the NEXT Cairo day (UTC+2/+3):
        // the bucket must be the Cairo date, not the UTC date.
        $yesterdayUtc = now('UTC')->subDay()->setTime(22, 30);
        $this->messageAt($conv, $yesterdayUtc);
        $expectedDay = $yesterdayUtc->setTimezone('Africa/Cairo')->toDateString();
        $this->assertNotSame($yesterdayUtc->setTimezone('UTC')->toDateString(), $expectedDay);

        $res = $this->withToken($this->token($admin))->getJson('/api/v1/admin/ai/usage')->assertOk();
        $days = collect($res->json('data'))->pluck('day')->all();
        $this->assertContains($expectedDay, $days);

        // The guard uses the same Cairo day: a 22:30 UTC message counts for
        // "today Cairo" budget checks (covered in TaskTxtLimitsLocksTest).
    }

    private static int $seq = 0;

    private function conflictAndValidationFailures(): array
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.update', 'products.delete']);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        $mk = function () use ($cat) {
            $n = 75000 + (++self::$seq);
            $p = Product::factory()->create(['category_id' => $cat->id, 'code' => 'P'.$n,
                'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both]);
            $v = ProductVariant::factory()->create(['product_id' => $p->id, 'sku' => 'P'.$n.'-BLK',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 50, 'is_active' => true]);

            return [$p, $v];
        };

        // Genuine 409: external edit between propose and confirm.
        [$p1, $v1] = $mk();
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_variant', 'args' => ['variant_id' => $v1->id, 'retail_price' => 450]]);
        $action409 = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        app(\App\Services\Catalog\VariantService::class)->update($v1->fresh(), [
            'retail_price' => 411, 'expected_version' => $v1->lock_version]);
        try {
            app(PendingActionService::class)->confirm($admin, $action409->fresh(), 'تنفيذ');
            $this->fail('expected 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // Non-409 failure: target hard-deleted between propose and confirm
        // (ModelNotFound at execute → failed + ok:false, no 409 marker).
        [$p2, $v2] = $mk();
        $prop2 = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_variant', 'args' => ['variant_id' => $v2->id, 'retail_price' => 460]]);
        $action422 = AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        $v2->forceDelete();
        $out = app(PendingActionService::class)->confirm($admin, $action422->fresh());
        $this->assertSame('failed', $out->type);

        return [$admin, $action409->fresh(), $action422->fresh()];
    }

    // ── task 27b: failed_409_count counts 409s only ──
    public function test_task27b_failed_409_counts_conflicts_only(): void
    {
        [$admin, $action409, $action422] = $this->conflictAndValidationFailures();

        $this->assertSame('failed', $action409->status);
        $this->assertTrue((bool) ($action409->result['conflict_409'] ?? false));
        $this->assertSame('failed', $action422->status);
        $this->assertFalse((bool) ($action422->result['conflict_409'] ?? false));

        $res = $this->withToken($this->token($admin))->getJson('/api/v1/admin/ai/usage')->assertOk();
        $stats = $res->json('tool_stats');
        $this->assertSame(1, $stats['failed_409_count']);
        $this->assertEqualsWithDelta(1 / $stats['total_proposals'], $stats['conflict_409_rate'] / 100, 0.001);
    }

    private function streamBody(string $token, int $convId, array $payload): string
    {
        $res = $this->withToken($token)->post("/api/v1/admin/ai/conversations/{$convId}/stream", $payload);
        $res->assertOk();

        return method_exists($res, 'streamedContent') ? (string) $res->streamedContent() : (string) $res->getContent();
    }

    // ── task 28a: two-step cycle streams status events then a reply ──
    public function test_task28a_sse_streams_status_then_reply(): void
    {
        $admin = $this->agentUser();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                ['functionCall' => ['name' => 'list_branches', 'args' => []], 'thoughtSignature' => 'sig']]]]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]])
            ->push($this->geminiText('الفروع عندك.'))]);

        $body = $this->streamBody($this->token($admin), $conv->id, ['text' => 'فين الفروع؟']);

        $this->assertStringContainsString('event: status', $body);
        $this->assertStringContainsString('event: reply', $body);
        $this->assertStringContainsString('"message_id"', $body);
        // Every frame is parseable: "event: <name>\ndata: <json>" blocks.
        preg_match_all('/event: (\w+)\ndata: (\{.*?\})(?=\n\n|\n\S|\z)/s', $body, $frames, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(3, count($frames));
        foreach ($frames as $frame) {
            $this->assertNotNull(json_decode($frame[2], true), "unparseable {$frame[1]} frame");
        }
    }

    // ── task 28b: provider failure → generic error event with 502 ──
    public function test_task28b_sse_error_event_on_provider_failure(): void
    {
        $admin = $this->agentUser();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(null, 500)->push(null, 500)]);

        $body = $this->streamBody($this->token($admin), $conv->id, ['text' => 'هيفشل']);

        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('"status":502', $body);
        $this->assertStringContainsString('تعذر إتمام طلب المساعد', $body);
    }

    // ── task 28c: user-level contention → error event with 423 ──
    public function test_task28c_sse_error_event_on_locked_user(): void
    {
        $admin = $this->agentUser();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $lock = Cache::lock('ai-agent:user:'.$admin->id, 600);
        $this->assertTrue($lock->acquire());
        try {
            $body = $this->streamBody($this->token($admin), $conv->id, ['text' => 'مشغول']);
        } finally {
            $lock->release();
        }
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('"status":423', $body);
    }
}
