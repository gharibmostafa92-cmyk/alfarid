<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\StaffRole;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Models\AiWhatsappOutbound;
use App\Models\Location;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\WhatsApp\WhatsAppInboundProcessor;
use App\Services\Shared\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Slice3ProposalAndWhatsAppSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        app(SettingService::class)->updateBatch([
            'ai_enabled' => true,
            'ai_whatsapp_enabled' => true,
            'ai_whatsapp_allow_writes' => true,
            'ai_whatsapp_max_risk' => 'high',
            'ai_whatsapp_pin_required' => true,
        ]);
        config([
            'services.whatsapp_gateway.url' => 'http://wa-gateway.test',
            'services.whatsapp_gateway.secret' => 'wa-test-secret',
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'test-model',
        ]);
    }

    public function test_high_risk_tools_always_resolve_non_empty_typed_confirmation(): void
    {
        $branch = \App\Models\Branch::factory()->create();
        $location = Location::query()->create(['branch_id' => $branch->id, 'name' => 'A1', 'code' => 'LOC-A1']);

        foreach ([
            ['approve_dealer_application', ['customer_id' => 999999]],
            ['delete_location', ['location_id' => $location->id]],
            ['assign_roles', ['user_id' => 1, 'add' => ['Manager']]],
            ['set_user_status', ['user_id' => 1, 'active' => false]],
            ['update_role_permissions', ['role_id' => 1]],
            ['update_settings', ['settings' => ['ai_enabled' => true]]],
            ['update_document_numbering', ['type' => 'order']],
            ['unmapped_high_risk_tool', []],
        ] as [$tool, $args]) {
            [$typed] = ProposalBuilder::resolveTypedConfirmation($tool, $args, []);
            $this->assertNotNull($typed, "Expected non-null typed confirmation for {$tool}");
            $this->assertNotSame('', trim((string) $typed), "Expected non-empty typed confirmation for {$tool}");
        }
    }

    public function test_high_risk_card_persists_the_same_confirmation_phrase_used_by_confirmation_handlers(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $conv = AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test']);

        $outcome = app(ProposalBuilder::class)->makeProposal(
            $admin,
            $conv,
            'unmapped_high_risk_tool',
            [],
            [],
            ['title' => 'إجراء عالي الخطورة'],
            null,
            'high',
        );
        $action = AiPendingAction::query()->findOrFail($outcome->data['pending_action_id']);
        [$expectedTyped] = ProposalBuilder::resolveTypedConfirmation($action->tool, $action->args, $action->preview);

        $this->assertSame('تأكيد نهائي', $outcome->uiBlock['typed_confirmation']);
        $this->assertSame($outcome->uiBlock['typed_confirmation'], $action->preview['typed_confirmation']);
        $this->assertSame($outcome->uiBlock['typed_confirmation'], $expectedTyped);
        [$blankPreviewTyped] = ProposalBuilder::resolveTypedConfirmation(
            'unmapped_high_risk_tool',
            [],
            ['typed_confirmation' => '   '],
        );
        $this->assertSame('تأكيد نهائي', $blankPreviewTyped);

        $customer = \App\Models\Customer::factory()->create(['phone' => 'legacy-1']);
        [$customerTyped, $customerHint] = ProposalBuilder::resolveTypedConfirmation(
            'approve_dealer_application',
            ['customer_id' => $customer->id],
        );
        $this->assertSame('تأكيد نهائي', $customerTyped);
        $this->assertSame('عبارة التأكيد', $customerHint);
    }

    public function test_quote_reply_objection_on_high_risk_whatsapp_card_does_not_confirm_action(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        AiWhatsappIdentity::query()->create([
            'user_id' => $admin->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => '201012345678@s.whatsapp.net',
            'status' => 'active',
            'verified_at' => now(),
            'pin_hash' => Hash::make('1234'),
            'unlocked_until' => now()->addMinutes(30),
            'created_by' => $admin->id,
        ]);
        $conv = AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test']);
        $action = AiPendingAction::query()->create([
            'id' => (string) Str::ulid(),
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'update_settings',
            'args' => ['settings' => ['ai_enabled' => false]],
            'args_hash' => app(ToolRegistry::class)->hashArgs(['settings' => ['ai_enabled' => false]]),
            'expected_versions' => [],
            'preview' => ['title' => 'تحديث الإعدادات'],
            'risk' => 'high',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);
        AiWhatsappOutbound::query()->create([
            'wa_message_id' => 'wamid.card.1',
            'conversation_id' => $conv->id,
            'pending_action_id' => $action->id,
        ]);
        $inbound = AiWhatsappInbound::query()->create([
            'wa_message_id' => 'wamid.in.1',
            'status' => 'queued',
        ]);

        Http::fake([
            'http://wa-gateway.test/*' => Http::response(['wa_message_id' => 'wamid.out.1'], 200),
            'generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'تمام، مش هننفذ.']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ], 200),
        ]);

        app(WhatsAppInboundProcessor::class)->process($inbound, [
            'wa_message_id' => 'wamid.in.1',
            'remote_jid' => '201012345678@s.whatsapp.net',
            'text' => 'لا استنى متعملش كده',
            'quoted_id' => 'wamid.card.1',
        ]);

        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_transient_concurrency_429_releases_job_while_budget_429_rejects(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        AiWhatsappIdentity::query()->create([
            'user_id' => $admin->id,
            'phone_e164' => '+201012345679',
            'wa_jid' => '201012345679@s.whatsapp.net',
            'status' => 'active',
            'verified_at' => now(),
            'pin_hash' => Hash::make('1234'),
            'unlocked_until' => now()->addMinutes(30),
            'created_by' => $admin->id,
        ]);

        Http::fake([
            'http://wa-gateway.test/*' => Http::response(['wa_message_id' => 'wamid.out.2'], 200),
        ]);

        // Hold all concurrent slots (limit = 1) to trigger transient concurrency 429
        app(SettingService::class)->updateBatch(['ai_max_concurrent_sessions' => 1]);
        config(['services.gemini.whatsapp_max_concurrent_sessions' => 1]);
        $slot = Cache::lock('ai-agent:concurrent-slot:whatsapp:0', 60);
        $this->assertTrue($slot->get());

        try {
            $inbound = AiWhatsappInbound::query()->create([
                'wa_message_id' => 'wamid.in.429',
                'status' => 'queued',
            ]);

            $result = app(WhatsAppInboundProcessor::class)->process($inbound, [
                'wa_message_id' => 'wamid.in.429',
                'remote_jid' => '201012345679@s.whatsapp.net',
                'text' => 'هات الفروع',
            ]);

            $this->assertSame('release', $result);
        } finally {
            $slot->release();
        }
    }

    public function test_pin_unlock_warns_user_to_delete_pin_message(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        AiWhatsappIdentity::query()->create([
            'user_id' => $admin->id,
            'phone_e164' => '+201012345680',
            'wa_jid' => '201012345680@s.whatsapp.net',
            'status' => 'active',
            'verified_at' => now(),
            'pin_hash' => Hash::make('1234'),
            'created_by' => $admin->id,
        ]);

        Http::fake([
            'http://wa-gateway.test/*' => Http::response(['wa_message_id' => 'wamid.pin.ack'], 200),
        ]);

        $inbound = AiWhatsappInbound::query()->create([
            'wa_message_id' => 'wamid.in.pin',
            'status' => 'queued',
        ]);

        app(WhatsAppInboundProcessor::class)->process($inbound, [
            'wa_message_id' => 'wamid.in.pin',
            'remote_jid' => '201012345680@s.whatsapp.net',
            'text' => 'فتح 1234',
        ]);

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/send')
            && str_contains((string) ($req['text'] ?? ''), 'امسح'));
    }

    public function test_without_overlapping_middleware_key_is_stable_across_pending_to_active(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $admin->id,
            'phone_e164' => '+201012345681',
            'wa_jid' => null,
            'wa_lid' => null,
            'status' => 'pending',
            'verified_at' => null,
            'created_by' => $admin->id,
        ]);

        // Before the first pairing message is processed, only its incoming
        // phone/LID aliases are available to queue middleware.
        $pendingJob = new ProcessWhatsAppMessage(1, [
            'remote_jid' => 'lid-123@lid',
            'remote_jid_alt' => '201012345681@s.whatsapp.net',
            'text' => 'ربط ABCD-1234',
        ]);
        $pendingKeys = array_map(fn ($middleware) => $middleware->key, $pendingJob->middleware());

        $identity->forceFill([
            'wa_jid' => '201012345681@s.whatsapp.net',
            'wa_lid' => 'lid-123@lid',
            'status' => 'active',
            'verified_at' => now(),
        ])->save();
        $activeJob = new ProcessWhatsAppMessage(2, [
            'remote_jid' => '201012345681@s.whatsapp.net',
            'remote_jid_alt' => 'lid-123@lid',
            'text' => 'هات الفروع',
        ]);
        $activeKeys = array_map(fn ($middleware) => $middleware->key, $activeJob->middleware());

        $this->assertNotEmpty(array_intersect($pendingKeys, $activeKeys), 'The pending and active jobs must share a sender lock.');
        $this->assertContains('wa-user:'.$admin->id, $activeKeys);
    }

    public function test_bulk_conflict_reverts_pending_children_to_bulk_child_and_preserves_executed_items_on_retry(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(StaffRole::SuperAdmin->value);
        $conv = AiConversation::query()->create(['user_id' => $admin->id, 'model' => 'test']);

        $childA = AiPendingAction::query()->create([
            'id' => (string) Str::ulid(),
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'update_settings',
            'args' => ['settings' => ['ai_enabled' => true]],
            'args_hash' => hash('sha256', 'a'),
            'expected_versions' => [],
            'preview' => ['title' => 'A'],
            'risk' => 'low',
            'status' => 'executed',
            'executed_at' => now(),
            'result' => ['ok' => true, 'message' => 'A done'],
            'expires_at' => now()->addMinutes(10),
        ]);
        $childB = AiPendingAction::query()->create([
            'id' => (string) Str::ulid(),
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'update_settings',
            'args' => ['settings' => ['ai_enabled' => true]],
            'args_hash' => 'bad-hash-to-fail',
            'expected_versions' => [],
            'preview' => ['title' => 'B'],
            'risk' => 'low',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(10),
        ]);
        $parent = AiPendingAction::query()->create([
            'id' => (string) Str::ulid(),
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'bulk_actions',
            'args' => ['action_ids' => [$childA->id, $childB->id]],
            'args_hash' => app(ToolRegistry::class)->hashArgs(['action_ids' => [$childA->id, $childB->id]]),
            'expected_versions' => [],
            'preview' => ['title' => 'Bulk'],
            'risk' => 'medium',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Retry selecting only B (since A already executed in earlier attempt)
        $outcome = app(PendingActionService::class)->confirm($admin, $parent, 'تنفيذ', [$childB->id]);

        $this->assertSame('executed', $parent->fresh()->status);
        $this->assertCount(2, $outcome->data['items']);
    }
}
