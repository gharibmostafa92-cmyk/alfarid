<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\ToolRegistry;
use App\Services\PosService;
use App\Services\SettingService;
use App\Services\TreasuryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AiAgentAuditFixesTest extends TestCase
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

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function geminiText(string $text = 'تمام'): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]];
    }

    private function confirmAction(User $admin, int|string $actionId, array $payload = []): TestResponse
    {
        return $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$actionId}/confirm", $payload);
    }

    public function test_h1_interleaved_event_does_not_break_function_call_pairing(): void
    {
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        // model(functionCall) id=N، ثم event من تأكيد قديم في تبويب آخر، ثم tool.
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'هات الفروع', 'provider_parts' => [['text' => 'هات الفروع']]]);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'model', 'content' => null, 'provider_parts' => [['functionCall' => ['name' => 'list_branches', 'args' => []]]]]);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'event', 'content' => 'تم إلغاء الإجراء 1']);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'tool', 'provider_parts' => [['functionResponse' => ['name' => 'list_branches', 'response' => ['branches' => []]]]]]);

        $captured = null;
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use (&$captured) {
            $captured = $request->data()['contents'];

            return Http::response($this->geminiText(), 200);
        }]);
        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'كمل'])->assertOk();

        $this->assertNotNull($captured);
        $flat = [];
        foreach ($captured as $turn) {
            foreach ($turn['parts'] as $part) {
                $flat[] = $part;
            }
        }
        // لا رد interrupted صناعي ولا functionResponse يتيم.
        foreach ($flat as $part) {
            $resp = (array) ($part['functionResponse']['response'] ?? []);
            $this->assertFalse((bool) ($resp['interrupted'] ?? false));
        }
        $callIdx = null;
        $responseIdx = null;
        foreach ($flat as $i => $part) {
            if (isset($part['functionCall']['name']) && $part['functionCall']['name'] === 'list_branches') {
                $callIdx = $i;
            }
            if (isset($part['functionResponse']['name']) && $part['functionResponse']['name'] === 'list_branches') {
                $responseIdx = $i;
            }
        }
        $this->assertNotNull($callIdx);
        $this->assertNotNull($responseIdx);
        // الرد يأتي مباشرة بعد الاستدعاء (الـ event تأجلت لبعده).
        $this->assertSame($callIdx + 1, $responseIdx);
    }

    public function test_m2_step_call_cap_rejects_excess_without_executing(): void
    {
        $admin = $this->staff(['ai_agent.use', 'branches.view', 'inventory.all_branches']);
        $conv = $this->conv($admin);
        $calls = array_map(fn () => ['functionCall' => ['name' => 'list_branches', 'args' => []], 'thoughtSignature' => 'sig'], range(1, 10));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => $calls]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]])
            ->push($this->geminiText())]);
        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => 'هات الفروع عشر مرات'])->assertOk();

        $toolMsg = AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'tool')->latest('id')->firstOrFail();
        $parts = $toolMsg->provider_parts;
        $this->assertCount(10, $parts);
        $denied = array_filter($parts, fn ($p) => (bool) (($p['functionResponse']['response'] ?? [])['denied'] ?? false));
        $this->assertCount(2, $denied);
        // لا إجراءات معلقة من الزيادة (أدوات قراءة أصلًا لا تنشئها،
        // لكن الرفض نفسه يجب ألا يُسجَّل كتدقيق).
        $this->assertSame(0, AiPendingAction::query()->where('conversation_id', $conv->id)->count());
    }

    public function test_m3_missing_branch_marks_action_failed_not_500(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'locations.create', 'locations.manage', 'inventory.all_branches']);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'create_location', 'args' => ['branch_id' => $branch->id, 'name' => 'رف', 'code' => 'SH-1', 'type' => 'shelf']]);
        $this->assertSame('proposal', $outcome->type);
        $branch->delete();

        $response = $this->confirmAction($admin, $outcome->data['pending_action_id']);
        $response->assertOk();
        $this->assertFalse($response->json('data.ok'));
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertStringContainsString('اتحذف', $response->json('data.message'));
        $this->assertSame('failed', AiPendingAction::query()->find($outcome->data['pending_action_id'])->status);
    }

    public function test_m3_taken_role_name_marks_action_failed_not_500(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'roles.create', 'roles.manage', 'inventory.all_branches']);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'create_role', 'args' => ['name' => 'TestRole', 'permissions' => []]]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::query()->findOrFail($outcome->data['pending_action_id']);
        Role::create(['name' => $action->args['name'], 'guard_name' => 'staff']);

        $response = $this->confirmAction($admin, $outcome->data['pending_action_id']);
        $response->assertOk();
        $this->assertFalse($response->json('data.ok'));
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertStringContainsString('اتحجز', $response->json('data.message'));
    }

    public function test_m3_revoked_central_access_marks_action_failed_not_500(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'branches.manage', 'inventory.all_branches']);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]]);
        $this->assertSame('proposal', $outcome->type);
        $admin->revokePermissionTo('inventory.all_branches');

        $response = $this->confirmAction($admin, $outcome->data['pending_action_id'], ['typed_confirmation' => $branch->code]);
        $response->assertOk();
        $this->assertFalse($response->json('data.ok'));
        $this->assertSame('failed', $response->json('data.status'));
    }

    public function test_m4_prefix_padding_change_rejecting_reissued_number(): void
    {
        DB::table('settings')->where('key', 'order_prefix')->update(['value' => 'ORD-']);
        DB::table('settings')->where('key', 'order_padding')->update(['value' => '6']);
        DB::table('settings')->where('key', 'order_next_number')->update(['value' => '5000']);
        app(SettingService::class)->clearCache();
        Order::factory()->create(['order_number' => 'ORD-005000']);

        try {
            app(SettingService::class)->updateBatch(['order_prefix' => 'ORD-00', 'order_padding' => 4]);
            $this->fail('Expected ValidationException for the reissued number.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ORD-005000', collect($e->errors())->flatten()->implode(' '));
        }

        // تغيير غير متصادم ينجح.
        app(SettingService::class)->updateBatch(['order_prefix' => 'ORDX-']);
        $this->assertSame('ORDX-', DB::table('settings')->where('key', 'order_prefix')->value('value'));
    }

    public function test_m5_branch_status_rejected_inside_bulk(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'branches.update', 'locations.update', 'inventory.all_branches']);
        $defs = app(ToolRegistry::class)->definitions();
        $bulkEnum = $defs['bulk_actions']['declaration']['parameters']['properties']->items['items']['properties']->tool['enum'];
        $this->assertNotContains('set_branch_status', $bulkEnum);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false]],
            ['tool' => 'set_location_status', 'args' => ['location_id' => 1, 'active' => false]],
        ]]]);
        $this->assertSame('denied', $outcome->type);
    }

    public function test_m6_open_shift_refused_on_inactive_branch(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $admin = $this->staff(['inventory.all_branches']);
        $drawer = Treasury::create(['name' => 'درج', 'code' => 'DR-1', 'branch_id' => $branch->id, 'type' => TreasuryType::PosDrawer, 'current_balance' => 0, 'is_active' => true]);

        try {
            app(PosService::class)->openShift($admin, $branch->id, $drawer->id, 0.0);
            $this->fail('Expected 422 for an inactive branch.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_m6_treasury_movement_refused_on_inactive_branch_but_allowed_for_central(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $admin = $this->staff(['inventory.all_branches']);
        $branchSafe = Treasury::create(['name' => 'خزنة فرع', 'code' => 'BR-SAFE', 'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe, 'current_balance' => 100, 'is_active' => true]);
        $central = Treasury::create(['name' => 'بنك مركزي', 'code' => 'BANK-1', 'branch_id' => null, 'type' => TreasuryType::BankAccount, 'current_balance' => 0, 'is_active' => true]);

        try {
            app(TreasuryService::class)->record(['treasury_id' => $branchSafe->id, 'type' => TreasuryTransactionType::CustomerReceipt, 'amount' => 50, 'created_by' => $admin->id]);
            $this->fail('Expected 422 for an inactive branch treasury.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $tx = app(TreasuryService::class)->record(['treasury_id' => $central->id, 'type' => TreasuryTransactionType::CustomerReceipt, 'amount' => 50, 'created_by' => $admin->id]);
        $this->assertEquals(50.0, (float) $tx->balance_after);
    }

    public function test_l1_password_rotation_via_users_self_update_is_exempt(): void
    {
        $user = User::factory()->create(['password' => 'Old-password12', 'must_change_password' => true]);
        // مستخدم مركزي حتى لا يحجبه فحص الفرع داخل UpdateStaffUser
        // (خارج نطاق L-1) — الاختبار يثبت إصلاح شرط الـ middleware فقط.
        $user->givePermissionTo(Permission::findOrCreate('users.update', 'staff'));
        $user->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));

        $response = $this->withToken($this->token($user))->putJson("/api/v1/admin/users/{$user->id}", [
            'current_password' => 'Old-password12',
            'password' => 'New-password12',
            'expected_version' => 0,
        ]);
        $response->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_l2_ai_settings_rechecked_at_execution(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.settings', 'settings.manage', 'inventory.all_branches']);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'update_settings', 'args' => ['changes' => ['ai_enabled' => false]]]);
        $this->assertSame('proposal', $outcome->type);
        $admin->revokePermissionTo('ai_agent.settings');

        $response = $this->confirmAction($admin, $outcome->data['pending_action_id']);
        $response->assertOk();
        $this->assertFalse($response->json('data.ok'));
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertSame('1', DB::table('settings')->where('key', 'ai_enabled')->value('value'));
    }

    public function test_l9_stream_maps_authorization_to_403_not_502(): void
    {
        $admin = $this->staff(['ai_agent.use']);
        $conv = $this->conv($admin);
        // AgentOrchestrator نهائي فلا يُحاكى؛ لكن GeminiClient غير نهائي
        // ويُحقن فيه، وconverse() يلتقط GeminiException فقط — فـ
        // AuthorizationException منه تصل فعلًا إلى catch الـ stream.
        $this->app->bind(GeminiClient::class, fn () => new class extends GeminiClient
        {
            public function converse(array $contents, array $functionDeclarations = [], ?string $system = null, ?string $model = null): array
            {
                throw new AuthorizationException('ممنوع');
            }
        });

        $response = $this->withToken($this->token($admin))->post("/api/v1/admin/ai/conversations/{$conv->id}/stream", ['text' => 'hi']);
        $content = method_exists($response, 'streamedContent') ? $response->streamedContent() : $response->getContent();
        $this->assertStringContainsString('"status":403', $content);
        $this->assertStringNotContainsString('"status":502', $content);
    }
}
