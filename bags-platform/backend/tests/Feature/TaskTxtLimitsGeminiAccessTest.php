<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use App\Services\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** task.txt §2 tasks 16, 17, 18, 22 (limits, locks, Gemini failures, conversation visibility). */
class TaskTxtLimitsGeminiAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'secret-test-key', 'services.gemini.model' => 'gemini-test']);
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

        return $u->createToken('t')->plainTextToken;
    }

    private function setting(string $key, string $value): void
    {
        DB::table('settings')->where('key', $key)->update(['value' => $value]);
        app(SettingService::class)->clearCache();
    }

    private function geminiText(string $text, int $total = 7): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => $total - 5, 'totalTokenCount' => $total]];
    }

    private function send(User $u, AiConversation $c, string $text = 'مرحبا')
    {
        return $this->withToken($this->token($u))
            ->postJson("/api/v1/admin/ai/conversations/{$c->id}/messages", ['text' => $text]);
    }

    // ── 16: budget resets at Cairo midnight ──
    public function test_task16_daily_budget_429_then_reopens_after_cairo_midnight(): void
    {
        $u = $this->staff(['ai_agent.use']);
        $c = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
        $this->setting('ai_daily_token_budget_per_user', '1000');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('تمام'))]);

        $this->travelTo(now('Africa/Cairo')->setTime(23, 59)->setTimezone('UTC'));
        AiMessage::create(['conversation_id' => $c->id, 'role' => 'model', 'content' => 'x', 'input_tokens' => 600, 'output_tokens' => 500]);
        $this->send($u, $c)->assertStatus(429);

        $this->travel(2)->minutes(); // 00:01 Cairo, new business day
        $this->send($u, $c)->assertOk();
    }

    // ── 16: per-cycle token cap ──
    public function test_task16_cycle_cap_returns_cap_reply(): void
    {
        $u = $this->staff(['ai_agent.use']);
        $c = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('كبير', 150000))]);

        $res = $this->send($u, $c)->assertOk();
        $this->assertStringContainsString('استنزف سقف التوكنز', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
    }

    // ── 16: only 8 calls per step are executed ──
    public function test_task16_only_first_8_calls_per_step_execute(): void
    {
        $u = $this->staff(['ai_agent.use', 'products.view']);
        $c = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
        $parts = [];
        for ($i = 0; $i < 10; $i++) {
            $parts[] = ['functionCall' => ['name' => 'get_product', 'args' => ['product_id' => 999000 + $i]]];
        }
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => $parts]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]])
            ->push($this->geminiText('خلصت')),
        ]);

        $this->send($u, $c)->assertOk();
        $tool = AiMessage::where('conversation_id', $c->id)->where('role', 'tool')->firstOrFail();
        $responses = collect($tool->provider_parts)->pluck('functionResponse');
        $this->assertCount(10, $responses);
        $excess = $responses->slice(8)->map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE));
        foreach ($excess as $json) {
            $this->assertStringContainsString('تجاوز الحد', $json);
        }
    }

    // ── 17: user lock (423) and global slots (429), released afterwards ──
    public function test_task17_user_lock_423_and_slot_cap_429_and_release(): void
    {
        $u = $this->staff(['ai_agent.use']);
        $c = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('تمام'))]);

        $held = Cache::lock('ai-agent:user:'.$u->id, 60);
        $this->assertTrue($held->get());
        $this->send($u, $c)->assertStatus(423);
        $held->release();
        $this->send($u, $c)->assertOk();

        $this->setting('ai_max_concurrent_sessions', '2');
        $slots = [Cache::lock('ai-agent:concurrent-slot:0', 60), Cache::lock('ai-agent:concurrent-slot:1', 60)];
        foreach ($slots as $s) {
            $this->assertTrue($s->get());
        }
        $this->send($u, $c)->assertStatus(429);
        foreach ($slots as $s) {
            $s->release();
        }
        $this->send($u, $c)->assertOk();
    }

    // ── 17: locks are released after an exception ──
    public function test_task17_locks_released_after_gemini_exception(): void
    {
        $u = $this->staff(['ai_agent.use']);
        $c = AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push([], 400)->push($this->geminiText('تمام'))]);
        $this->send($u, $c)->assertStatus(502);
        $this->send($u, $c)->assertOk();
    }

    // ── 18: retry policy + key hygiene ──
    public function test_task18_retries_and_key_safety(): void
    {
        $client = app(GeminiClient::class);
        $call = fn () => $client->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]], [], 'sys', null);

        // 500 twice → exactly 2 requests then GeminiException with status 500.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push([], 500)->push([], 500)->push([], 500)]);
        try {
            $call();
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(500, $e->providerStatus);
            $this->assertStringNotContainsString('secret-test-key', $e->getMessage());
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', 'secret-test-key') && ! str_contains($r->url(), 'secret-test-key'));

        // 400 → no retry.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 400)]);
        try {
            $call();
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(400, $e->providerStatus);
        }
        Http::assertSentCount(1);

        // finishReason != STOP keeps the usage; empty body fails.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'جزء']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 90, 'totalTokenCount' => 100]])]);
        try {
            $call();
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(100, $e->usage()['total_tokens'] ?? null);
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([])]);
        $this->expectException(GeminiException::class);
        $call();
    }

    public function test_task18_empty_key_is_rejected(): void
    {
        config(['services.gemini.api_key' => '']);
        Http::fake();
        $this->expectException(\LogicException::class);
        app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]], [], 'sys', null);
    }

    // ── 22: who can see other people's conversations ──
    public function test_task22_conversation_visibility_matrix(): void
    {
        $owner = $this->staff(['ai_agent.use']);
        $conv = AiConversation::create(['user_id' => $owner->id, 'model' => 'gemini-test', 'title' => 'سري']);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'model', 'content' => 'رد', 'provider_parts' => [['text' => 'x']]]);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'tool', 'provider_parts' => [['functionResponse' => []]]]);

        $a = $this->staff(['ai_agent.use']);
        $b = $this->staff(['ai_agent.use', 'ai_agent.logs.view']);
        $d = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'customers.view', 'inventory.all_branches']);
        $branch = \App\Models\Branch::factory()->create(['is_active' => true]);
        $c = $this->staff(['ai_agent.use', 'ai_agent.logs.view', 'customers.view']);
        $c->forceFill(['branch_id' => $branch->id])->save();

        $this->withToken($this->token($c))->getJson('/api/v1/admin/ai/conversations?all=1')->assertStatus(403);
        $this->withToken($this->token($c))->getJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertStatus(403);
        foreach ([$a, $b] as $u) {
            $list = $this->withToken($this->token($u))->getJson('/api/v1/admin/ai/conversations?all=1')->assertOk();
            $this->assertNotContains($conv->id, collect($list->json('data'))->pluck('id')->all());
            $this->withToken($this->token($u))->getJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertStatus(403);
        }

        $list = $this->withToken($this->token($d))->getJson('/api/v1/admin/ai/conversations?all=1')->assertOk();
        $this->assertContains($conv->id, collect($list->json('data'))->pluck('id')->all());
        $show = $this->withToken($this->token($d))->getJson("/api/v1/admin/ai/conversations/{$conv->id}")->assertOk();
        $this->assertNotContains('tool', collect($show->json('data.messages'))->pluck('role')->all());
        foreach ($show->json('data.messages') as $m) {
            $this->assertArrayNotHasKey('provider_parts', $m);
        }
    }
}

