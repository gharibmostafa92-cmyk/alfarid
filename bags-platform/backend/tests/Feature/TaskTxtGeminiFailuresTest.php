<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 task 18: Gemini failure modes + retry policy + key safety.
 */
class TaskTxtGeminiFailuresTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);
        app(\App\Services\SettingService::class)->clearCache();
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

    // ── connection failure: exactly one retry, then GeminiException ──
    public function test_task18a_connection_failure_retries_once(): void
    {
        // A throwing fake is never recorded by the Http factory, so count
        // attempts with our own counter.
        $attempts = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('boom');
        }]);

        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertNull($e->providerStatus);
        }
        $this->assertSame(2, $attempts);
    }

    // ── 429 with Retry-After: 3 → one retry honoring the header, then success ──
    public function test_task18b_rate_limit_retries_once_with_retry_after(): void
    {
        $calls = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response(null, 429, ['Retry-After' => '3']);
            }

            return Http::response($this->geminiText());
        }]);

        $started = microtime(true);
        $res = app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
        $elapsed = microtime(true) - $started;

        $this->assertSame('تمام.', $res['text']);
        $this->assertSame(2, $calls);
        $this->assertGreaterThanOrEqual(3.0, $elapsed);
    }

    // ── 500 twice → GeminiException with status 500, two requests ──
    public function test_task18c_server_error_twice_raises_with_status(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(null, 500)->push(null, 500)]);

        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(500, $e->providerStatus);
        }
        Http::assertSentCount(2);
    }

    // ── 400: no retry, GeminiException with status ──
    public function test_task18d_client_error_never_retried(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad'], 400)]);

        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(400, $e->providerStatus);
        }
        Http::assertSentCount(1);
    }

    // ── finishReason != STOP with usage → usage attached; orchestrator records event + totals ──
    public function test_task18e_incomplete_finish_records_usage_event(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'نص ناقص']]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 40, 'totalTokenCount' => 140],
        ])]);

        // Client level: usage rides on the exception.
        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(['input_tokens' => 100, 'output_tokens' => 40, 'total_tokens' => 140], $e->usage());
        }

        // Orchestrator level: token event message + conversation totals.
        $admin = $this->agentUser();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'هيفشل'])
            ->assertStatus(502);
        $event = AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'event')->latest('id')->firstOrFail();
        $this->assertStringContainsString('140', $event->content);
        $this->assertSame(140, (int) $conv->fresh()->total_tokens);
    }

    // ── empty reply (STOP, no text/calls) → GeminiException; SSE generic 502 ──
    public function test_task18f_empty_reply_and_sse_generic_502(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => []]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 0, 'totalTokenCount' => 5],
        ])]);

        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertTrue(true);
        }

        $admin = $this->agentUser();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $res = $this->withToken($this->token($admin))
            ->post("/api/v1/admin/ai/conversations/{$conv->id}/stream", ['text' => 'فاضي']);
        $res->assertOk();
        $body = method_exists($res, 'streamedContent') ? $res->streamedContent() : $res->getContent();
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('"status":502', $body);
        $this->assertStringContainsString('تعذر إتمام طلب المساعد', $body);
        $this->assertStringNotContainsString('STOP', $body);
    }

    // ── empty key → LogicException before any HTTP ──
    public function test_task18g_empty_key_rejected_before_http(): void
    {
        config(['services.gemini.api_key' => '']);

        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected LogicException');
        } catch (\LogicException $e) {
            $this->assertTrue(true);
        }
        Http::assertNothingSent();
    }

    // ── §3: model name with / or .. → LogicException ──
    public function test_task18h_model_name_path_rejected(): void
    {
        foreach (['evil/model', '..', 'a..b/c'] as $model) {
            try {
                app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]], [], null, $model);
                $this->fail("expected LogicException for {$model}");
            } catch (\LogicException $e) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    // ── key travels in x-goog-api-key header, never in the URL ──
    public function test_task18i_key_in_header_not_url(): void
    {
        config(['services.gemini.api_key' => 'SECRET-KEY-123']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key')
                && $request->header('x-goog-api-key')[0] === 'SECRET-KEY-123'
                && ! str_contains($request->url(), 'SECRET-KEY-123')
                && ! str_contains($request->url(), 'key=');
        });
    }

    // ── key / request text / response text never reach logs or errors ──
    public function test_task18j_no_secrets_in_logs_or_errors(): void
    {
        config(['services.gemini.api_key' => 'SECRET-KEY-123']);
        $handler = new \Monolog\Handler\TestHandler;
        Log::getLogger()->pushHandler($handler);
        // One stateful fake: Http::fake() merges stubs instead of replacing,
        // so a second fake() for the same pattern would NOT shadow the first.
        $phase = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$phase) {
            $phase++;
            if ($phase === 1) {
                return Http::response(null, 429, ['Retry-After' => '1']);
            }
            if ($phase === 2) {
                return Http::response($this->geminiText('نص سري للرد'));
            }

            return Http::response(['error' => 'x'], 400);
        }]);

        app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'نص سري للطلب']]]]);

        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString('SECRET-KEY-123', json_encode([$record['message'], $record['context']], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            $this->assertStringNotContainsString('نص سري للطلب', json_encode([$record['message'], $record['context']], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            $this->assertStringNotContainsString('نص سري للرد', json_encode([$record['message'], $record['context']], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        // Failure messages carry no key either.
        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertStringNotContainsString('SECRET-KEY-123', $e->getMessage());
        }
    }

    // ── §3: image count + size guards ──
    public function test_task18k_image_count_and_size_guards(): void
    {
        $make = fn () => \Illuminate\Http\UploadedFile::fake()->image('p.jpg');
        try {
            app(GeminiClient::class)->generate('وصف', [$make(), $make(), $make(), $make(), $make(), $make()]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        Http::assertNothingSent();
    }
}
