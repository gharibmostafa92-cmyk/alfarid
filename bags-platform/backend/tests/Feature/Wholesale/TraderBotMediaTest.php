<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TraderBotMediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Ogg page with an Opus identification packet for MIME detection.
    private const OGG_OPUS_HEADER = 'T2dnUwACAAAAAAAAAAABAAAAAAAAAAAAAAABE09wdXNIZWFkAQE4AYC7AAAAAAA=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        config(['services.gemini.api_key' => 'test-key']);
    }

    private function verifiedTrader(): Customer
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 50000,
        ]);
        CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => $customer->phone,
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        return $customer;
    }

    private function stockedVariant(string $productName, string $sku): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published', 'name_ar' => $productName]);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['sku' => $sku, 'retail_price' => 100, 'is_active' => true]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 1000,
        ]);

        return $variant->fresh();
    }

    private function fakeImage(string $name = 'sheet.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function fakeAudio(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('voice.ogg', base64_decode(self::OGG_OPUS_HEADER))->mimeType('audio/ogg');
    }

    private function fakeGeminiStructured(array $data): void
    {
        Http::fake([
            'generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]],
                ]],
                'usageMetadata' => [],
            ], 200),
        ]);
    }

    public function test_handwritten_order_sheet_resolves_to_lookup_summary(): void
    {
        $customer = $this->verifiedTrader();
        $this->stockedVariant('شنطة كروس بني', 'CROSS-1');
        $this->fakeGeminiStructured(['lines' => [['code' => 'CROSS-1', 'qty' => 50]]]);

        $reply = app(CustomerWholesaleAssistant::class)->readOrderImage($customer, $this->fakeImage());

        $this->assertStringContainsString('50', $reply);
        $this->assertStringContainsString('سلة', $reply);
    }

    public function test_ocr_text_is_data_and_never_approves_a_notice(): void
    {
        $customer = $this->verifiedTrader();
        $this->stockedVariant('شنطة كروس بني', 'CROSS-1');
        $this->fakeGeminiStructured(['lines' => [['code' => 'CROSS-1', 'qty' => 1]]]);

        $reply = app(CustomerWholesaleAssistant::class)->readOrderImage($customer, $this->fakeImage());

        $this->assertSame(0, WholesalePaymentNotice::query()->count());
        $this->assertStringContainsString('سلة', $reply);
    }

    public function test_voice_note_transcribes_then_answers(): void
    {
        $customer = $this->verifiedTrader();
        $this->fakeGeminiStructured(['text' => 'رصيدي']);

        $reply = app(CustomerWholesaleAssistant::class)->transcribeAndReply($customer, $this->fakeAudio());

        $this->assertStringContainsString('المديونية', $reply);
    }

    public function test_webhook_image_message_resolves_order_sheet(): void
    {
        config([
            'services.whatsapp_business.webhook_secret' => 'cloud-secret',
            'services.whatsapp_business.token' => 'wa-token',
            'services.gemini.api_key' => 'test-key',
        ]);
        $customer = $this->verifiedTrader();
        $this->stockedVariant('شنطة كروس بني', 'CROSS-1');
        Http::fakeSequence()
            ->push(['url' => 'https://fb.cdn/sheet1'], 200)
            ->push($this->fakeImage()->getContent(), 200)
            ->push([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => '{"lines":[{"code":"CROSS-1","qty":50}]}']]],
                ]],
                'usageMetadata' => [],
            ], 200);
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.img1',
                            'from' => '20'.ltrim($customer->phone, '0'),
                            'type' => 'image',
                            'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg', 'caption' => 'طلبية'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->postSigned($body)->assertOk();

        $this->assertStringContainsString('50', $response->json('reply'));
        $this->assertStringContainsString('سلة', $response->json('reply'));
    }

    public function test_webhook_voice_message_transcribes_then_answers(): void
    {
        config([
            'services.whatsapp_business.webhook_secret' => 'cloud-secret',
            'services.whatsapp_business.token' => 'wa-token',
            'services.gemini.api_key' => 'test-key',
        ]);
        $customer = $this->verifiedTrader();
        Http::fakeSequence()
            ->push(['url' => 'https://fb.cdn/voice1'], 200)
            ->push($this->fakeAudio()->getContent(), 200)
            ->push([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => '{"text":"رصيدي"}']]],
                ]],
                'usageMetadata' => [],
            ], 200);
        $body = json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.aud1',
                            'from' => '20'.ltrim($customer->phone, '0'),
                            'type' => 'audio',
                            'audio' => ['id' => 'media-2', 'mime_type' => 'audio/ogg'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->postSigned($body)->assertOk();

        $this->assertStringContainsString('المديونية', $response->json('reply'));
    }

    private function postSigned(string $body): \Illuminate\Testing\TestResponse
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'cloud-secret');

        return $this->call('POST', '/api/v1/webhooks/whatsapp/customers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WA_SIGNATURE' => $signature,
            'HTTP_X_WA_TIMESTAMP' => $timestamp,
        ], $body);
    }
}
