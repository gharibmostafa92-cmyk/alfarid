<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Ai;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Models\AiWhatsappLinkToken;
use App\Models\AiWhatsappOutbound;
use App\Models\Banner;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\AgentReply;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\WhatsApp\WhatsAppActorContext;
use App\Services\Ai\WhatsApp\WhatsAppInboundProcessor;
use App\Services\Shared\SettingService;
use App\Support\BannerImage;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppAssistantTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        config(['services.whatsapp_gateway.secret' => 'wa-secret', 'services.whatsapp_gateway.url' => 'http://gateway.test']);
        Http::preventStrayRequests();
    }

    public function test_webhook_rejects_a_bad_or_stale_signature_and_deduplicates(): void
    {
        Bus::fake();
        $body = json_encode(['wa_message_id' => 'm1', 'remote_jid' => '2010@s.whatsapp.net', 'text' => 'أهلا'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/webhooks/whatsapp/inbound', [], [], [], $this->headers('1', 'dead'), $body)->assertUnauthorized();

        $stale = (string) (time() - 600);
        $this->call('POST', '/api/v1/webhooks/whatsapp/inbound', [], [], [], $this->headers($stale, hash_hmac('sha256', $stale.'.'.$body, 'wa-secret')), $body)
            ->assertUnauthorized();
        $this->assertSame(0, AiWhatsappInbound::query()->count());

        $now = (string) time();
        $signature = hash_hmac('sha256', $now.'.'.$body, 'wa-secret');
        $this->call('POST', '/api/v1/webhooks/whatsapp/inbound', [], [], [], $this->headers($now, $signature), $body)->assertOk();
        $this->call('POST', '/api/v1/webhooks/whatsapp/inbound', [], [], [], $this->headers($now, $signature), $body)->assertOk();
        $this->assertSame(1, AiWhatsappInbound::query()->count());
        Bus::assertDispatchedTimes(ProcessWhatsAppMessage::class, 1);
    }

    public function test_unknown_sender_is_ignored_without_calling_the_model(): void
    {
        $inbound = $this->inbound('unknown-1');
        $result = app(WhatsAppInboundProcessor::class)->process($inbound, $this->message('unknown-1', '201099999999@s.whatsapp.net', 'مرحبا'));
        $this->assertSame('done', $result);
        $this->assertSame('ignored', $inbound->refresh()->status);
        $this->assertSame('unknown_sender', $inbound->ignore_reason);
        Http::assertNothingSent();
    }

    public function test_webhook_stores_one_encrypted_job_with_the_inbound_row(): void
    {
        $text = 'فتح 728194';
        $body = json_encode(['wa_message_id' => 'real-queue', 'remote_jid' => '201012345678@s.whatsapp.net', 'text' => $text], JSON_THROW_ON_ERROR);
        $now = (string) time();
        $headers = $this->headers($now, hash_hmac('sha256', $now.'.'.$body, 'wa-secret'));

        $this->call('POST', '/api/v1/webhooks/whatsapp/inbound', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/webhooks/whatsapp/inbound', [], [], [], $headers, $body)->assertOk();

        $jobs = DB::table('jobs')->where('queue', 'whatsapp-ai')->get();
        $this->assertCount(1, $jobs);
        $this->assertStringNotContainsString('728194', $jobs->first()->payload);
        $this->assertDatabaseHas('ai_whatsapp_inbound', ['wa_message_id' => 'real-queue', 'status' => 'queued']);
    }

    public function test_revoked_identity_cannot_be_reactivated_with_an_unused_link(): void
    {
        $user = $this->staff();
        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'status' => 'revoked',
            'created_by' => $user->id,
        ]);
        $token = AiWhatsappLinkToken::query()->create([
            'identity_id' => $identity->id,
            'token_hash' => hash('sha256', 'OLDK-2345'),
            'expires_at' => now()->addMinutes(5),
        ]);
        $inbound = $this->inbound('revoked-link');

        app(WhatsAppInboundProcessor::class)->process($inbound, $this->message('revoked-link', '12345@lid', 'ربط OLDK-2345'));

        $this->assertSame('revoked', $identity->refresh()->status);
        $this->assertNull($token->refresh()->consumed_at);
        $this->assertSame('invalid_link', $inbound->refresh()->ignore_reason);
        Http::assertNothingSent();
    }

    public function test_link_token_rejects_expired_used_and_wrong_phone(): void
    {
        $user = $this->staff();
        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);
        $token = 'ABCD-2345';
        AiWhatsappLinkToken::query()->create([
            'identity_id' => $identity->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);
        $inbound = $this->inbound('link-expired');
        app(WhatsAppInboundProcessor::class)->process($inbound, $this->message('link-expired', '201012345678@s.whatsapp.net', 'ربط '.$token));
        $this->assertSame('pending', $identity->refresh()->status);
        $this->assertSame('invalid_link', $inbound->refresh()->ignore_reason);

        $fresh = 'WXYZ-9876';
        $row = AiWhatsappLinkToken::query()->create([
            'identity_id' => $identity->id,
            'token_hash' => hash('sha256', $fresh),
            'expires_at' => now()->addMinutes(5),
        ]);
        $wrong = $this->inbound('link-wrong');
        app(WhatsAppInboundProcessor::class)->process($wrong, $this->message('link-wrong', '201199999999@s.whatsapp.net', 'ربط '.$fresh));
        $this->assertNull($row->refresh()->consumed_at);
        $this->assertSame('phone_mismatch', $wrong->refresh()->ignore_reason);

        $row->forceFill(['consumed_at' => now()])->save();
        $used = $this->inbound('link-used');
        app(WhatsAppInboundProcessor::class)->process($used, $this->message('link-used', '201012345678@s.whatsapp.net', 'ربط '.$fresh));
        $this->assertSame('invalid_link', $used->refresh()->ignore_reason);
    }

    public function test_link_from_lid_requires_matching_phone_number(): void
    {
        $user = $this->staff();
        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);
        $token = 'LIDK-2345';
        AiWhatsappLinkToken::query()->create([
            'identity_id' => $identity->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(5),
        ]);
        Http::fake(['http://gateway.test/*' => Http::response(['wa_message_id' => 'out-1'])]);
        $inbound = $this->inbound('link-lid');
        $message = [
            'wa_message_id' => 'link-lid',
            'remote_jid' => '12345@lid',
            'remote_jid_alt' => null,
            'text' => 'ربط '.$token,
            'quoted_id' => null,
            'media' => null,
        ];
        $processor = app(WhatsAppInboundProcessor::class);
        $processor->process($inbound, $message);
        $this->assertSame('pending', $identity->refresh()->status);
        $message['wa_message_id'] = 'link-lid-verified';
        $message['remote_jid_alt'] = '201012345678@s.whatsapp.net';
        $processor->process($this->inbound('link-lid-verified'), $message);
        $identity->refresh();
        $this->assertSame('active', $identity->status);
        $this->assertSame('12345@lid', $identity->wa_lid);
        $this->assertNotNull($identity->verified_at);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'تم ربط رقمك'));
    }

    public function test_shipping_company_can_be_confirmed_from_whatsapp_at_medium_risk(): void
    {
        DB::table('settings')->whereIn('key', [
            'ai_enabled', 'ai_whatsapp_enabled', 'ai_whatsapp_allow_writes', 'ai_shipping_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $user = $this->staff();
        $user->givePermissionTo(Permission::findOrCreate('shipping.create', 'staff'));
        $user->givePermissionTo(Permission::findOrCreate('shipping.update', 'staff'));
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $jid = '201012345678@s.whatsapp.net';
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $registry = app(ToolRegistry::class);
        $this->assertContains('upsert_shipping_company', array_column($registry->declarationsFor($user, channel: 'whatsapp'), 'name'));
        $proposal = $registry->dispatch($user, $conversation, [
            'name' => 'upsert_shipping_company',
            'args' => ['name' => 'Test Carrier', 'code' => 'test-carrier', 'is_active' => false],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $this->assertDatabaseMissing('shipping_companies', ['code' => 'test-carrier']);
        AiWhatsappOutbound::query()->create([
            'wa_message_id' => 'carrier-card',
            'conversation_id' => $conversation->id,
            'pending_action_id' => $proposal->data['pending_action_id'],
        ]);
        Http::fake(['http://gateway.test/*' => Http::response(['wa_message_id' => 'carrier-result'])]);

        app(WhatsAppInboundProcessor::class)->process(
            $this->inbound('carrier-confirm'),
            $this->message('carrier-confirm', $jid, 'تأكيد', 'carrier-card'),
        );

        $this->assertDatabaseHas('shipping_companies', ['code' => 'test-carrier', 'is_active' => false]);
        $this->assertDatabaseHas('ai_pending_actions', ['id' => $proposal->data['pending_action_id'], 'status' => 'executed']);
    }

    public function test_whatsapp_image_creates_a_banner_only_after_confirmation(): void
    {
        DB::table('settings')->whereIn('key', [
            'ai_enabled', 'ai_whatsapp_enabled', 'ai_whatsapp_allow_writes', 'ai_storefront_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'test-model']);
        $user = $this->staff();
        $user->givePermissionTo(Permission::findOrCreate('store.manage', 'staff'));
        $jid = '201012345678@s.whatsapp.net';
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $image = UploadedFile::fake()->image('banner.jpg', 600, 300);
        Http::fake([
            'http://gateway.test/media/banner-image' => Http::response($image->getContent()),
            'http://gateway.test/presence' => Http::response(),
            'http://gateway.test/send' => Http::sequence()
                ->push(['wa_message_id' => 'banner-card'])
                ->push(['wa_message_id' => 'banner-result']),
            'generativelanguage.googleapis.com/*' => function ($request) use ($user) {
                $attachment = AiAttachment::query()->where('user_id', $user->id)->sole();
                $this->assertStringContainsString($attachment->id, $request->body());

                return Http::response([
                    'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [[
                        'functionCall' => ['name' => 'create_banner', 'args' => [
                            'title' => 'WhatsApp banner',
                            'position' => 'main_slider',
                            'link_type' => 'none',
                            'attachment_id' => $attachment->id,
                        ]],
                    ]]]]],
                ]);
            },
        ]);
        $processor = app(WhatsAppInboundProcessor::class);
        $message = $this->message('banner-request', $jid, 'اعمل بانر من الصورة دي');
        $message['media'] = ['id' => 'banner-image', 'type' => 'image'];
        $processor->process($this->inbound('banner-request'), $message);
        $this->assertDatabaseMissing('banners', ['title' => 'WhatsApp banner']);
        $card = AiWhatsappOutbound::query()->where('wa_message_id', 'banner-card')->firstOrFail();
        $this->assertNotNull($card->pending_action_id);

        $processor->process($this->inbound('banner-confirm'), $this->message('banner-confirm', $jid, 'تأكيد', 'banner-card'));

        $banner = Banner::query()->where('title', 'WhatsApp banner')->sole();
        Storage::disk(BannerImage::disk())->assertExists($banner->image_path);
    }

    public function test_high_risk_confirm_requires_typed_text_and_quote_finds_the_card(): void
    {
        DB::table('settings')->where('key', 'ai_whatsapp_max_risk')->update(['value' => 'high']);
        DB::table('settings')->where('key', 'ai_whatsapp_allow_writes')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $user = $this->staff();
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $args = ['branch_id' => 1, 'active' => false];
        $action = AiPendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'tool' => 'set_branch_status',
            'args' => $args,
            'expected_versions' => [],
            'preview' => ['title' => 'تعطيل فرع', 'typed_confirmation' => 'BR01'],
            'args_hash' => app(ToolRegistry::class)->hashArgs($args),
            'risk' => 'high',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);
        AiWhatsappOutbound::query()->create([
            'wa_message_id' => 'card-1',
            'conversation_id' => $conversation->id,
            'pending_action_id' => $action->id,
        ]);
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => '201012345678@s.whatsapp.net',
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        DB::table('settings')->where('key', 'ai_whatsapp_enabled')->update(['value' => '1']);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        Http::fake(['http://gateway.test/*' => Http::response(['wa_message_id' => 'out-2'])]);
        $user->givePermissionTo(Permission::findOrCreate('branches.update', 'staff'));
        $inbound = $this->inbound('confirm-1');
        app(WhatsAppInboundProcessor::class)->process($inbound, $this->message('confirm-1', '201012345678@s.whatsapp.net', 'تأكيد', 'card-1'));
        $this->assertSame('pending', $action->refresh()->status);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'نص التأكيد'));
    }

    public function test_tool_disabled_on_whatsapp_is_rejected_at_confirm_even_if_the_card_was_made_on_the_web(): void
    {
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        DB::table('settings')->where('key', 'ai_whatsapp_enabled')->update(['value' => '1']);
        DB::table('settings')->where('key', 'ai_whatsapp_allow_writes')->update(['value' => '1']);
        DB::table('settings')->where('key', 'ai_whatsapp_max_risk')->update(['value' => 'high']);
        app(SettingService::class)->clearCache();
        $user = $this->staff();
        $user->givePermissionTo(Permission::findOrCreate('settings.manage', 'staff'));
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'web', 'model' => 'test']);
        $args = ['changes' => ['company_name' => 'VARYA']];
        $action = AiPendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'tool' => 'update_settings',
            'args' => $args,
            'expected_versions' => [],
            'preview' => ['title' => 'تعديل'],
            'args_hash' => app(ToolRegistry::class)->hashArgs($args),
            'risk' => 'high',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('الأداة دي مش متاحة من واتساب.');
        app(WhatsAppActorContext::class)->run('confirm', fn () => app(PendingActionService::class)->confirm($user, $action, null));
    }

    public function test_job_releases_when_the_assistant_is_busy(): void
    {
        $user = $this->staff();
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => '201012345678@s.whatsapp.net',
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        DB::table('settings')->where('key', 'ai_whatsapp_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        Http::fake(['http://gateway.test/*' => Http::response(['wa_message_id' => 'out-3'])]);
        Cache::lock('ai-agent:user:'.$user->id, 600)->get();
        $inbound = $this->inbound('busy-1');
        $job = new class($inbound->id, $this->message('busy-1', '201012345678@s.whatsapp.net', 'أهلا')) extends ProcessWhatsAppMessage
        {
            public int $releasedWith = -1;

            public function release($delay = 0)
            {
                $this->releasedWith = (int) $delay;

                return $delay;
            }
        };
        $job->handle(app(WhatsAppInboundProcessor::class));
        $this->assertGreaterThanOrEqual(15, $job->releasedWith);
        $this->assertLessThanOrEqual(30, $job->releasedWith);
        $this->assertSame('queued', $inbound->refresh()->status);
    }

    public function test_partial_multimessage_delivery_is_durable_and_never_replays_sent_messages(): void
    {
        $user = $this->staff();
        $jid = '201012345678@s.whatsapp.net';
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $inbound = $this->inbound('partial-delivery');
        $sendCount = 0;
        Http::fake([
            'http://gateway.test/send' => function () use (&$sendCount, $inbound) {
                $rows = AiWhatsappOutbound::query()->where('inbound_id', $inbound->id)->orderBy('sequence')->get();
                $this->assertCount(3, $rows, 'The complete reply must be durable before the first send.');
                if ($sendCount === 0) {
                    $this->assertSame(['sending', 'pending', 'pending'], $rows->pluck('delivery_status')->all());
                }
                $sendCount++;

                return $sendCount === 1
                    ? Http::response(['wa_message_id' => 'outbound-first'])
                    : Http::response(['error' => 'delivery outcome unknown'], 503);
            },
            'http://gateway.test/*' => Http::response([], 200),
        ]);

        $reply = new AgentReply('', [
            ['type' => 'notice', 'message' => 'First response'],
            ['type' => 'notice', 'message' => 'Second response'],
            ['type' => 'notice', 'message' => 'Third response'],
        ]);
        $deliver = new \ReflectionMethod(WhatsAppInboundProcessor::class, 'deliver');
        $deliver->setAccessible(true);
        try {
            $deliver->invoke(app(WhatsAppInboundProcessor::class), $inbound, $conversation, $jid, $reply);
            $this->fail('A gateway failure should escape after recording delivery as uncertain.');
        } catch (\App\Services\Ai\WhatsApp\WhatsAppGatewayException) {
            // The job handler records the failed inbound and does not retry its turn.
        }

        $outbound = AiWhatsappOutbound::query()->where('inbound_id', $inbound->id)->orderBy('sequence')->get();
        $this->assertSame(['sent', 'unknown', 'blocked'], $outbound->pluck('delivery_status')->all());
        $this->assertSame('outbound-first', $outbound[0]->wa_message_id);
        $this->assertNull($outbound[0]->body);
        $this->assertNull($outbound[1]->body);
        $this->assertNull($outbound[2]->body);
        $this->assertSame('failed', $inbound->refresh()->status);
        $this->assertSame('outbound_delivery_uncertain', $inbound->ignore_reason);

        // A duplicate job sees a terminal inbound and cannot replay delivery.
        $job = new ProcessWhatsAppMessage($inbound->id, $this->message('partial-delivery', $jid, 'هات التقرير'));
        $job->handle(app(WhatsAppInboundProcessor::class));
        $this->assertSame('failed', $inbound->refresh()->status);
        $job->handle(app(WhatsAppInboundProcessor::class));
        Http::assertSentCount(2);
    }

    public function test_stale_sending_outbox_record_becomes_unknown_without_resending(): void
    {
        $user = $this->staff();
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $jid = '201012345678@s.whatsapp.net';
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $inbound = $this->inbound('stale-sending');
        $outbound = AiWhatsappOutbound::query()->create([
            'inbound_id' => $inbound->id,
            'sequence' => 0,
            'conversation_id' => $conversation->id,
            'delivery_status' => 'sending',
            'recipient_jid' => $jid,
            'body' => 'May already have reached WhatsApp',
        ]);
        Http::fake(['http://gateway.test/*' => Http::response([], 200)]);

        (new ProcessWhatsAppMessage($inbound->id, $this->message('stale-sending', $jid, 'retry')))
            ->handle(app(WhatsAppInboundProcessor::class));

        $this->assertSame('unknown', $outbound->refresh()->delivery_status);
        $this->assertNull($outbound->body);
        $this->assertSame('failed', $inbound->refresh()->status);
        $this->assertSame('outbound_delivery_uncertain', $inbound->ignore_reason);
        Http::assertNothingSent();
    }

    public function test_sent_outbox_is_finalized_before_a_queued_inbound_can_repeat_the_ai_turn(): void
    {
        app(SettingService::class)->updateBatch(['ai_enabled' => true, 'ai_whatsapp_enabled' => true]);
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'test-model']);
        $user = $this->staff();
        $jid = '201012345678@s.whatsapp.net';
        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $inbound = $this->inbound('sent-before-finish');
        $inbound->forceFill(['identity_id' => $identity->id])->save();
        $outbound = AiWhatsappOutbound::query()->create([
            'inbound_id' => $inbound->id,
            'sequence' => 0,
            'wa_message_id' => 'already-sent',
            'delivery_status' => 'sent',
            'conversation_id' => $conversation->id,
        ]);
        Http::fake(['http://gateway.test/*' => Http::response([], 200), 'generativelanguage.googleapis.com/*' => Http::response([])]);

        (new ProcessWhatsAppMessage($inbound->id, $this->message('sent-before-finish', $jid, 'اعرض التقرير')))
            ->handle(app(WhatsAppInboundProcessor::class));

        $this->assertSame('sent', $outbound->refresh()->delivery_status);
        $this->assertSame('processed', $inbound->refresh()->status);
        $this->assertSame($identity->id, $inbound->identity_id);
        $this->assertSame($conversation->id, $inbound->conversation_id);
        Http::assertNothingSent();
    }

    public function test_terminal_job_failure_closes_outbox_payloads_and_preserves_sent_message_ids(): void
    {
        $user = $this->staff();
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $inbound = $this->inbound('terminal-outbox-failure');
        AiWhatsappOutbound::query()->create([
            'inbound_id' => $inbound->id,
            'sequence' => 0,
            'wa_message_id' => 'already-delivered',
            'delivery_status' => 'sent',
            'recipient_jid' => '201012345678@s.whatsapp.net',
            'body' => 'Previously sent text',
            'conversation_id' => $conversation->id,
        ]);
        AiWhatsappOutbound::query()->create([
            'inbound_id' => $inbound->id,
            'sequence' => 1,
            'delivery_status' => 'sending',
            'recipient_jid' => '201012345678@s.whatsapp.net',
            'body' => 'May already have reached WhatsApp',
            'conversation_id' => $conversation->id,
        ]);
        AiWhatsappOutbound::query()->create([
            'inbound_id' => $inbound->id,
            'sequence' => 2,
            'delivery_status' => 'pending',
            'recipient_jid' => '201012345678@s.whatsapp.net',
            'body' => 'Not attempted',
            'conversation_id' => $conversation->id,
        ]);

        (new ProcessWhatsAppMessage($inbound->id, []))->failed(new \RuntimeException('terminal worker failure'));

        $outbound = AiWhatsappOutbound::query()->where('inbound_id', $inbound->id)->orderBy('sequence')->get();
        $this->assertSame(['sent', 'unknown', 'blocked'], $outbound->pluck('delivery_status')->all());
        $this->assertSame('already-delivered', $outbound[0]->wa_message_id);
        $this->assertNull($outbound[0]->body);
        $this->assertNull($outbound[0]->recipient_jid);
        $this->assertNull($outbound[1]->body);
        $this->assertNull($outbound[1]->recipient_jid);
        $this->assertNull($outbound[2]->body);
        $this->assertNull($outbound[2]->recipient_jid);
        $this->assertSame('failed', $inbound->refresh()->status);
    }

    public function test_interrupted_assistant_turn_without_outbox_is_not_replayed(): void
    {
        $user = $this->staff();
        $jid = '201012345678@s.whatsapp.net';
        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $inbound = $this->inbound('interrupted-before-outbox');
        $inbound->forceFill(['identity_id' => $identity->id, 'ignore_reason' => 'assistant_turn_started'])->save();
        Http::fake(['http://gateway.test/*' => Http::response([], 200), 'generativelanguage.googleapis.com/*' => Http::response([])]);

        (new ProcessWhatsAppMessage($inbound->id, $this->message('interrupted-before-outbox', $jid, 'هات التقرير')))
            ->handle(app(WhatsAppInboundProcessor::class));

        $this->assertSame('failed', $inbound->refresh()->status);
        $this->assertSame('assistant_turn_outcome_unknown', $inbound->ignore_reason);
        Http::assertNothingSent();
    }

    public function test_unknown_confirmation_card_cannot_be_executed_by_generic_confirmation(): void
    {
        app(SettingService::class)->updateBatch(['ai_enabled' => true, 'ai_whatsapp_enabled' => true]);
        $user = $this->staff();
        $user->givePermissionTo(Permission::findOrCreate('branches.update', 'staff'));
        $branch = \App\Models\Branch::factory()->create(['is_active' => true, 'code' => 'WA-OUTBOX']);
        $jid = '201012345678@s.whatsapp.net';
        AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345678',
            'wa_jid' => $jid,
            'status' => 'active',
            'verified_at' => now(),
            'created_by' => $user->id,
        ]);
        $conversation = AiConversation::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'model' => 'test']);
        $args = ['branch_id' => $branch->id, 'active' => false];
        $action = AiPendingAction::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'tool' => 'set_branch_status',
            'args' => $args,
            'expected_versions' => [],
            'preview' => ['title' => 'Deactivate branch'],
            'args_hash' => app(ToolRegistry::class)->hashArgs($args),
            'risk' => 'medium',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);
        $firstInbound = $this->inbound('unknown-action-card');
        $outbound = AiWhatsappOutbound::query()->create([
            'inbound_id' => $firstInbound->id,
            'sequence' => 0,
            'conversation_id' => $conversation->id,
            'pending_action_id' => $action->id,
            'delivery_status' => 'sending',
            'recipient_jid' => $jid,
            'body' => 'Confirmation card',
        ]);
        Http::fake(['http://gateway.test/*' => Http::response(['wa_message_id' => 'generic-ack'])]);

        (new ProcessWhatsAppMessage($firstInbound->id, $this->message('unknown-action-card', $jid, 'request')))
            ->handle(app(WhatsAppInboundProcessor::class));

        $this->assertSame('unknown', $outbound->refresh()->delivery_status);
        $this->assertSame('failed', $action->refresh()->status);

        $confirmation = $this->inbound('generic-confirm-after-unknown-card');
        app(WhatsAppInboundProcessor::class)->process(
            $confirmation,
            $this->message('generic-confirm-after-unknown-card', $jid, 'تأكيد'),
        );

        $this->assertSame('failed', $action->refresh()->status);
        $this->assertTrue($branch->fresh()->is_active);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'مفيش إجراء مستني التأكيد'));
    }

    private function staff(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('ai_agent.use', 'staff'));
        $user->givePermissionTo(Permission::findOrCreate('ai_agent.execute', 'staff'));
        $user->givePermissionTo(Permission::findOrCreate('ai_agent.whatsapp', 'staff'));

        return $user;
    }

    private function inbound(string $id): AiWhatsappInbound
    {
        return AiWhatsappInbound::query()->create(['wa_message_id' => $id, 'status' => 'queued']);
    }

    /** @return array<string, mixed> */
    private function message(string $id, string $jid, string $text, ?string $quoted = null): array
    {
        return [
            'wa_message_id' => $id,
            'remote_jid' => $jid,
            'remote_jid_alt' => null,
            'text' => $text,
            'quoted_id' => $quoted,
            'media' => null,
        ];
    }

    /** @return array<string, string> */
    private function headers(string $timestamp, string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WA_TIMESTAMP' => $timestamp,
            'HTTP_X_WA_SIGNATURE' => $signature,
        ];
    }
}
