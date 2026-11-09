<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
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
 * task.txt §2 tasks 16-17: consumption limits (daily budget, per-cycle cap,
 * per-step call cap, cycle time budget, vision tokens) and concurrency
 * guards (per-user lock 423, global session cap 429, lock release).
 */
class TaskTxtLimitsLocksTest extends TestCase
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
        ])->update(['value' => '1']);
        app(\App\Services\Shared\SettingService::class)->clearCache();
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

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function geminiText(string $text = 'تمام.', int $total = 7): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => $total]];
    }

    private function seedUsage(AiConversation $conv, int $tokens, ?\DateTimeInterface $at = null): void
    {
        $msg = AiMessage::create([
            'conversation_id' => $conv->id, 'role' => 'model', 'content' => 'قديم',
            'input_tokens' => $tokens - 2, 'output_tokens' => 2,
        ]);
        if ($at) {
            $msg->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    // ── task 16a: daily budget 429 + Cairo-midnight reset ──
    public function test_task16a_daily_budget_blocks_and_resets_after_cairo_midnight(): void
    {
        DB::table('settings')->where('key', 'ai_daily_token_budget_per_user')->update(['value' => '100']);
        app(\App\Services\Shared\SettingService::class)->clearCache();

        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        // 95 used → request passes (adds ~7).
        $this->seedUsage($conv, 95);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'أهلا'])
            ->assertOk();

        // Now ≥100 used → 429.
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'تاني'])
            ->assertStatus(429);

        // After Cairo midnight the budget reopens.
        $this->travelTo(now(config('app.business_timezone', 'Africa/Cairo'))->addDay()->startOfDay()->addMinute());
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'يوم جديد'])
            ->assertOk();
    }

    // ── task 16b: Cairo-day grouping incl. DST boundary ──
    public function test_task16b_usage_grouped_by_cairo_day_across_dst(): void
    {
        DB::table('settings')->where('key', 'ai_daily_token_budget_per_user')->update(['value' => '100']);
        app(\App\Services\Shared\SettingService::class)->clearCache();

        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        // Winter (UTC+2): 22:30 UTC Jan 15 = 00:30 Jan 16 Cairo (next day).
        $winter = new \DateTimeImmutable('2026-01-15 22:30:00', new \DateTimeZone('UTC'));
        $this->seedUsage($conv, 95, $winter);
        // Travel to Jan 16 daytime Cairo: yesterday's 95 must not count → OK.
        $this->travelTo(\Carbon\Carbon::create(2026, 1, 16, 12, 0, 0, 'Africa/Cairo'));
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'شتا'])
            ->assertOk();

        // Summer DST (UTC+3): 21:30 UTC Jul 15 = 00:30 Jul 16 Cairo (next day).
        $this->travelBack();
        $summer = new \DateTimeImmutable('2026-07-15 21:30:00', new \DateTimeZone('UTC'));
        $this->seedUsage($conv, 95, $summer);
        $this->travelTo(\Carbon\Carbon::create(2026, 7, 16, 12, 0, 0, 'Africa/Cairo'));
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'صيف'])
            ->assertOk();

        // Same Cairo day still blocked: seed 95 on Jul 15 Cairo day, then
        // request inside that day → 95+7 used ≥100.
        $this->travelBack();
        $this->seedUsage($conv, 100, new \DateTimeImmutable('2026-07-15 10:00:00', new \DateTimeZone('UTC')));
        $this->travelTo(\Carbon\Carbon::create(2026, 7, 15, 12, 0, 0, 'Africa/Cairo'));
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'نفس اليوم'])
            ->assertStatus(429);
    }

    // ── task 16c: per-cycle cap → cycleCapReply ──
    public function test_task16c_cycle_cap_returns_cap_reply(): void
    {
        DB::table('settings')->where('key', 'ai_max_tokens_per_cycle')->update(['value' => '1000']);
        app(\App\Services\Shared\SettingService::class)->clearCache();

        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('رد طويل.', 5000))]);

        $res = $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'احكيلي قصة'])
            ->assertOk();
        $this->assertStringContainsString('سقف التوكنز للدورة', $res->json('data.text'));
    }

    // ── task 16d: 10 calls in one step → first 8 run, rest denied ──
    public function test_task16d_only_first_8_step_calls_execute(): void
    {
        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $calls = array_map(
            fn () => ['functionCall' => ['name' => 'list_branches', 'args' => []], 'thoughtSignature' => 'sig'],
            range(1, 10)
        );
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => $calls]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]])
            ->push($this->geminiText())]);

        $this->withToken($this->token($admin))
            ->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'الفروع عشر مرات'])
            ->assertOk();

        $toolMsg = AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'tool')->latest('id')->firstOrFail();
        $this->assertCount(10, $toolMsg->provider_parts);
        $denied = array_filter($toolMsg->provider_parts, fn ($p) => (bool) (($p['functionResponse']['response'] ?? [])['denied'] ?? false));
        $this->assertCount(2, $denied);
    }

    // ── task 16e: 240s budget constants ──
    public function test_task16e_cycle_time_budget_constants(): void
    {
        $ref = new \ReflectionClass(AgentOrchestrator::class);
        $this->assertSame(240, $ref->getConstant('MAX_CYCLE_SECONDS'));
        $this->assertSame(45, $ref->getConstant('TOOL_CALL_RESERVE_SECONDS'));
        $this->assertSame(8, $ref->getConstant('MAX_CALLS_PER_STEP'));
        // Wall-clock elapsed time uses microtime(true), which cannot be
        // time-traveled in tests; the break/reserve wiring above is what
        // enforces "stop with a message" once the budget is spent.
    }

    // ── task 16f: mid-cycle (vision) tokens counted via DB sum ──
    public function test_task16f_vision_event_tokens_count_toward_cycle_cap(): void
    {
        DB::table('settings')->where('key', 'ai_max_tokens_per_cycle')->update(['value' => '1000']);
        app(\App\Services\Shared\SettingService::class)->clearCache();

        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $step0 = ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
            ['functionCall' => ['name' => 'list_branches', 'args' => []], 'thoughtSignature' => 'sig'],
        ]]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 5, 'totalTokenCount' => 10]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push($step0)->push($this->geminiText())]);

        $orchestrator = app(AgentOrchestrator::class);
        $injected = false;
        $reply = $orchestrator->handle($admin, $conv, ['text' => 'الفروع'], function () use ($conv, &$injected): void {
            // Simulates AnalyzeProductPhotosTool writing its vision-token
            // event mid-cycle; the next step-start check must see it.
            if (! $injected) {
                $injected = true;
                AiMessage::create(['conversation_id' => $conv->id, 'role' => 'event',
                    'content' => 'تحليل صور منتج بـ Gemini Vision',
                    'input_tokens' => 1500, 'output_tokens' => 500]);
            }
        });

        $this->assertStringContainsString('سقف التوكنز للدورة', $reply->text);
        Http::assertSentCount(1); // step 2 never reached Gemini
    }

    // ── task 17a: second request for same user → 423 ──
    public function test_task17a_concurrent_request_same_user_locked_423(): void
    {
        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        $lock = Cache::lock('ai-agent:user:'.$admin->id, 600);
        $this->assertTrue($lock->acquire());

        try {
            // A running request holds this lock → second one is refused.
            $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'تاني'])
                ->assertStatus(423);
        } finally {
            $lock->release();
        }

        // Lock freed → request passes.
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'أهلا'])
            ->assertOk();
    }

    // ── task 17b: all session slots taken → 429, then freed ──
    public function test_task17b_global_session_cap_429_and_release(): void
    {
        DB::table('settings')->where('key', 'ai_max_concurrent_sessions')->update(['value' => '10']);
        app(\App\Services\Shared\SettingService::class)->clearCache();

        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText())]);

        $slots = [];
        for ($i = 0; $i < 10; $i++) {
            $slot = Cache::lock('ai-agent:concurrent-slot:'.$i, 300);
            $this->assertTrue($slot->acquire());
            $slots[] = $slot;
        }

        try {
            // 11th session finds no free slot → 429.
            $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'زحمة'])
                ->assertStatus(429);
        } finally {
            foreach ($slots as $slot) {
                $slot->release();
            }
        }

        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'أهلا'])
            ->assertOk();

        // After a finished request every slot is acquirable again (no leak).
        $probe = [];
        for ($i = 0; $i < 10; $i++) {
            $slot = Cache::lock('ai-agent:concurrent-slot:'.$i, 300);
            $this->assertTrue($slot->acquire(), "slot {$i} leaked");
            $probe[] = $slot;
        }
        foreach ($probe as $slot) {
            $slot->release();
        }
    }

    // ── task 17c: slot released after an exception too ──
    public function test_task17c_session_slot_released_after_failure(): void
    {
        $admin = $this->agentUser();
        $conv = $this->conv($admin);
        $token = $this->token($admin);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(null, 500)->push(null, 500)
            ->push($this->geminiText())]);

        // Gemini fails twice → 502, but the slot must be released.
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'هيفشل'])
            ->assertStatus(502);
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'بعده'])
            ->assertOk();
    }
}
