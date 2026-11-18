<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\OrderStatus;
use App\Jobs\PruneAiConversations;
use App\Models\AiConversation;
use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Models\AiWhatsappLinkToken;
use App\Models\AiWhatsappOutbound;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\WhatsApp\E164Phone;
use App\Services\Shared\PaymentWebhookService;
use App\Services\Shared\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Slice5And6HardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_payment_webhook_rejects_nested_array_fields_and_non_numeric_inf_amounts(): void
    {
        config([
            'webhooks.payment.testgw' => [
                'driver' => 'hmac',
                'secret' => 'whsec_test',
                'signature_header' => 'X-Webhook-Signature',
                'currency' => 'EGP',
                'require_amount' => true,
            ],
        ]);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'grand_total' => 1.00,
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Pending,
        ]);

        // Array status / amount must NOT throw Array to string conversion or match (float)['100'] === 1.0
        $payload = [
            'id' => 'evt_arr_1',
            'order_number' => $order->order_number,
            'status' => ['paid'],
            'currency' => 'EGP',
            'amount' => ['100'],
        ];
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', (string) $raw, 'whsec_test');

        $result = app(PaymentWebhookService::class)->handle(
            'testgw',
            $payload,
            (string) $raw,
            ['x-webhook-signature' => $sig],
            [],
            '127.0.0.1',
        );

        $this->assertSame(200, $result['http']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        // '1e309' (INF) must be rejected cleanly
        $infPayload = [
            'id' => 'evt_inf_1',
            'order_number' => $order->order_number,
            'status' => 'paid',
            'currency' => 'EGP',
            'amount' => '1e309',
        ];
        $infRaw = json_encode($infPayload);
        $infSig = hash_hmac('sha256', (string) $infRaw, 'whsec_test');
        app(PaymentWebhookService::class)->handle(
            'testgw',
            $infPayload,
            (string) $infRaw,
            ['x-webhook-signature' => $infSig],
            [],
            '127.0.0.1',
        );
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_prune_ai_conversations_preserves_live_pinned_and_prunes_operational_tables(): void
    {
        app(SettingService::class)->updateBatch(['ai_retention_days' => 30]);
        $user = User::factory()->create();

        $pinned = AiConversation::query()->create([
            'user_id' => $user->id,
            'model' => 'test',
            'last_message_at' => now()->subDays(60),
        ]);
        $pinned->forceFill(['is_pinned' => true])->save();
        $unpinned = AiConversation::query()->create([
            'user_id' => $user->id,
            'model' => 'test',
            'is_pinned' => false,
            'last_message_at' => now()->subDays(60),
        ]);

        $identity = AiWhatsappIdentity::query()->create([
            'user_id' => $user->id,
            'phone_e164' => '+201012345688',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);
        AiWhatsappLinkToken::query()->create([
            'identity_id' => $identity->id,
            'token_hash' => hash('sha256', 'old'),
            'expires_at' => now()->subDays(10),
        ]);
        AiWhatsappInbound::query()->create([
            'wa_message_id' => 'wamid.old.1',
            'status' => 'processed',
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);
        // Force created_at for inbound (timestamps)
        DB::table('ai_whatsapp_inbound')->where('wa_message_id', 'wamid.old.1')->update(['created_at' => now()->subDays(40)]);

        $outbound = AiWhatsappOutbound::query()->create([
            'wa_message_id' => 'wamid.out.old',
            'conversation_id' => $pinned->id,
            'picker' => ['kind' => 'picker', 'options' => []],
        ]);
        DB::table('ai_whatsapp_outbound')->where('id', $outbound->id)->update(['created_at' => now()->subDays(10)]);

        DB::table('customer_message_budgets')->insert([
            'key' => 'phone:old',
            'date' => now()->subDays(10)->toDateString(),
            'sends' => 3,
        ]);

        app(PruneAiConversations::class)->handle(app(SettingService::class));

        $this->assertNotNull(AiConversation::withTrashed()->find($pinned->id));
        $this->assertNull(AiConversation::withTrashed()->find($unpinned->id));
        $this->assertDatabaseCount('ai_whatsapp_link_tokens', 0);
        $this->assertDatabaseCount('ai_whatsapp_inbound', 0);
        $this->assertNull($outbound->fresh()->picker);
        $this->assertDatabaseCount('customer_message_budgets', 0);
    }

    public function test_e164_phone_rejects_non_egyptian_leading_zero_number(): void
    {
        $this->assertSame('+201012345678', E164Phone::normalize('01012345678'));

        $this->expectException(ValidationException::class);
        E164Phone::normalize('0966501234567');
    }

    public function test_evaluate_ai_agent_refuses_live_run_in_production_without_force(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->artisan('ai:evaluate')
                ->assertFailed();
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }

    public function test_watch_whatsapp_connection_alerts_on_logged_out_even_after_reconnecting_alert(): void
    {
        config([
            'services.whatsapp_gateway.url' => 'http://wa-gateway.test',
            'services.whatsapp_gateway.secret' => 'sec',
        ]);
        Cache::put('wa-gateway-alerted', 'reconnecting', now()->addHours(6));

        Http::fake([
            'http://wa-gateway.test/status' => Http::sequence()
                ->push(['state' => 'logged_out'], 200)
                ->push(['state' => 'connected'], 200),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['state'] ?? null) === 'logged_out');

        $this->artisan('whatsapp:watch-connection')->assertSuccessful();
        $this->assertTrue(Cache::has('wa-gateway-logged-out-alerted'));

        // Connected clears logged-out alert key
        $this->artisan('whatsapp:watch-connection')->assertSuccessful();
        $this->assertFalse(Cache::has('wa-gateway-logged-out-alerted'));
    }
}
