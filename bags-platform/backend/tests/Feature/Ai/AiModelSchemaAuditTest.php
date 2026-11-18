<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\AiWhatsappOutbound;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AiModelSchemaAuditTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ai_message_persists_model_and_ai_conversation_persists_is_pinned_under_strict_mode(): void
    {
        $user = User::factory()->create();

        $wasPreventing = Model::preventsSilentlyDiscardingAttributes();
        Model::preventSilentlyDiscardingAttributes(true);

        try {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'title' => 'Strict mode check',
                'model' => 'gemini-2.5-flash',
                'is_pinned' => true,
            ]);

            $this->assertTrue($conversation->fresh()->is_pinned);

            $message = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'event',
                'model' => 'gemini-2.5-flash',
                'content' => 'vision parse',
            ]);

            $this->assertSame('gemini-2.5-flash', $message->fresh()->model);
        } finally {
            Model::preventSilentlyDiscardingAttributes($wasPreventing);
        }
    }

    public function test_ai_pending_action_accepts_bulk_child_status(): void
    {
        $user = User::factory()->create();
        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => 'Bulk status check',
            'model' => 'gemini-2.5-flash',
        ]);

        $action = AiPendingAction::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'tool' => 'update_product',
            'args' => ['product_id' => 1],
            'args_hash' => hash('sha256', '{}'),
            'preview' => ['title' => 'Test bulk child'],
            'expected_versions' => [],
            'risk' => 'low',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame('bulk_child', $action->fresh()->status);
    }

    public function test_ai_whatsapp_outbound_supports_durable_fields_and_nullable_wa_message_id(): void
    {
        $user = User::factory()->create();
        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'title' => 'WA outbound check',
            'model' => 'gemini-2.5-flash',
        ]);

        $outbound = AiWhatsappOutbound::create([
            'conversation_id' => $conversation->id,
            'sequence' => 1,
            'wa_message_id' => null,
            'delivery_status' => 'pending',
            'recipient_jid' => '201000000000@s.whatsapp.net',
            'body' => 'Hello',
        ]);

        $fresh = $outbound->fresh();
        $this->assertNull($fresh->wa_message_id);
        $this->assertSame('pending', $fresh->delivery_status);
        $this->assertSame('Hello', $fresh->body);
    }
}
