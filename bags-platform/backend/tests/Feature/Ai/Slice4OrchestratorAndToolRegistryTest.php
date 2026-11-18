<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\StaffRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Slice4OrchestratorAndToolRegistryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        app(SettingService::class)->updateBatch([
            'ai_enabled' => true,
            'ai_disabled_tools' => [],
            'ai_customer_tools_enabled' => true,
        ]);
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'test-model']);
    }

    public function test_branch_restricted_actor_cannot_create_staff_in_other_branch_or_central(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $role = Role::create(['guard_name' => 'staff', 'name' => 'Branch Supervisor']);

        $actor = User::factory()->create(['is_active' => true, 'branch_id' => $branchA->id]);
        $actor->assignRole($role);
        $actor->givePermissionTo(['ai_agent.use', 'ai_agent.execute', 'users.create', 'users.manage']);

        $conv = AiConversation::query()->create(['user_id' => $actor->id, 'model' => 'test']);
        $registry = app(ToolRegistry::class);

        // Specifying another branch must be denied at prepare time
        $denied = $registry->dispatch($actor, $conv, [
            'name' => 'create_staff_user',
            'args' => ['name' => 'Ali', 'email' => 'ali@example.com', 'branch_id' => $branchB->id],
        ]);
        $this->assertSame('denied', $denied->type);

        // Omitting branch_id forces actor's restricted branch (never null/central)
        $proposal = $registry->dispatch($actor, $conv, [
            'name' => 'create_staff_user',
            'args' => ['name' => 'Ali', 'email' => 'ali2@example.com'],
        ]);
        $this->assertSame('proposal', $proposal->type, (string) $proposal->message);
        $action = AiPendingAction::query()->findOrFail($proposal->data['pending_action_id']);
        $this->assertSame($branchA->id, $action->args['branch_id']);
    }

    public function test_get_customer_profile_masks_email_in_llm_data(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'branch_id' => null]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        $conv = AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test']);
        $customer = Customer::factory()->create(['email' => 'secret.merchant@example.com']);

        $outcome = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'get_customer_profile',
            'args' => ['customer_id' => $customer->id],
        ]);

        $this->assertSame('data', $outcome->type, (string) $outcome->message);
        $encoded = json_encode($outcome->data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('secret.merchant@example.com', (string) $encoded);
    }

    public function test_history_merges_consecutive_user_turns_after_interrupted_tool_call(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $conv = AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test']);

        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'مرحبا', 'provider_parts' => [['text' => 'مرحبا']]]);
        AiMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'model',
            'content' => '',
            'provider_parts' => [['functionCall' => ['name' => 'list_branches', 'args' => []]]],
        ]);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'event', 'content' => 'تم الإلغاء']);
        AiMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'كمل', 'provider_parts' => [['text' => 'كمل']]]);

        $method = new ReflectionMethod(AgentOrchestrator::class, 'history');
        $history = $method->invoke(app(AgentOrchestrator::class), $conv);

        for ($i = 1; $i < count($history); $i++) {
            $this->assertNotSame($history[$i - 1]['role'], $history[$i]['role'], 'Consecutive turns must not share the same role');
        }
    }

    public function test_catalog_routing_narrows_domains_instead_of_dumping_all_tools(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        $conv = AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test']);

        $capturedTools = [];
        Http::fake([
            'generativelanguage.googleapis.com*' => function ($request) use (&$capturedTools) {
                $payload = $request->data();
                $capturedTools = collect($payload['tools'][0]['functionDeclarations'] ?? [])->pluck('name')->all();

                return Http::response([
                    'candidates' => [[
                        'finishReason' => 'STOP',
                        'content' => ['parts' => [['text' => 'تمام']]],
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                ], 200);
            },
        ]);

        app(AgentOrchestrator::class)->handle($admin, $conv, ['text' => 'كام سعر الشنطة دي؟']);

        $this->assertNotContains('create_staff_user', $capturedTools);
        $this->assertNotContains('delete_role', $capturedTools);
    }

    public function test_tool_outcome_present_uses_mb_strlen_for_arabic_characters(): void
    {
        // 900 Arabic chars = 1800 UTF-8 bytes, well within maxChars = 1024 characters
        $arabic = str_repeat('ب', 900);
        $outcome = ToolOutcome::present(['text' => $arabic], null, null, 1024);

        $this->assertArrayNotHasKey('truncated', $outcome->data);
        $this->assertSame($arabic, $outcome->data['text']);
    }

    public function test_gemini_client_parses_http_date_and_decimal_retry_after(): void
    {
        $method = new ReflectionMethod(GeminiClient::class, 'retryAfterSeconds');
        $client = app(GeminiClient::class);

        $future = gmdate('D, d M Y H:i:s', time() + 3).' GMT';
        $seconds = $method->invoke($client, $future);
        $this->assertGreaterThanOrEqual(2, $seconds);
        $this->assertLessThanOrEqual(5, $seconds);

        $this->assertSame(1, $method->invoke($client, '0.5'));
        $this->assertSame(4, $method->invoke($client, '4'));
        $this->assertSame(5, $method->invoke($client, '120'));
    }

    public function test_ai_conversation_index_returns_total_tokens_with_all_flag(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test', 'total_tokens' => 120]);
        AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test', 'total_tokens' => 80]);

        $token = $admin->createToken('test')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/admin/ai/conversations?all=1');

        $response->assertOk()->assertJsonPath('total_tokens', 200);
    }
}
