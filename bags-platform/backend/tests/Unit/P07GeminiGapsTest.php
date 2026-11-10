<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * task.txt item 23 gaps: Retry-After capping, server-error retries,
 * DNS retries, incomplete output, malformed JSON, bad client config.
 */
class P07GeminiGapsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Http::preventStrayRequests();
    }

    private function completion(string $text = 'OK'): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2]];
    }

    public function test_retry_after_header_is_capped_at_five_seconds(): void
    {
        $started = microtime(true);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '30'])
            ->push($this->completion())]);
        $this->assertSame('OK', app(GeminiClient::class)->generate('Hello')['text']);
        Http::assertSentCount(2);
        // 30s capped to 5s: the call takes ~5s, never ~30s.
        $this->assertLessThan(25, microtime(true) - $started);
    }

    public function test_server_error_is_retried_once_then_thrown(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'boom']], 503)]);
        try {
            app(GeminiClient::class)->generate('Hello');
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame(503, $e->providerStatus);
            $this->assertStringNotContainsString('boom', $e->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_dns_failure_is_retried_once(): void
    {
        $calls = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: generativelanguage.googleapis.com');
        }]);
        try {
            app(GeminiClient::class)->generate('Hello');
            $this->fail('expected GeminiException');
        } catch (GeminiException) {
            $this->assertSame(2, $calls);
        }
    }

    public function test_max_tokens_output_is_reported_not_silent(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'نص ناقص']]]]],
                'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'totalTokenCount' => 5]])]);
        try {
            app(GeminiClient::class)->generate('Hello');
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame('لم ترجع خدمة Gemini إجابة مكتملة.', $e->getMessage());
        }

        // The chat path attaches the aborted tokens so the caller can bill them.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'نص ناقص']]]]],
                'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'totalTokenCount' => 5]])]);
        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame('لم ترجع خدمة Gemini إجابة مكتملة.', $e->getMessage());
            $this->assertSame(['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5], $e->usage());
        }
    }

    public function test_malformed_structured_json_is_rejected(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{oops']]]]]])]);
        try {
            app(GeminiClient::class)->generateStructured('Give JSON', ['type' => 'OBJECT']);
            $this->fail('expected GeminiException');
        } catch (GeminiException $e) {
            $this->assertSame('لم ترجع خدمة Gemini بنية JSON صالحة.', $e->getMessage());
        }
    }

    public function test_empty_key_or_bad_model_fail_before_any_request(): void
    {
        Http::fake();
        config(['services.gemini.model' => '']);
        try {
            app(GeminiClient::class)->generate('Hello');
            $this->fail('expected LogicException for empty model');
        } catch (LogicException) {
        }
        config(['services.gemini.model' => '../evil']);
        try {
            app(GeminiClient::class)->generate('Hello');
            $this->fail('expected LogicException for path-like model');
        } catch (LogicException) {
        }
        Http::assertNothingSent();
    }

    public function test_structured_success_validates_and_returns_data(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"a":1}']]]]],
                'usageMetadata' => ['promptTokenCount' => 2, 'candidatesTokenCount' => 1, 'totalTokenCount' => 3]])]);
        $result = app(GeminiClient::class)->generateStructured('Give JSON', ['type' => 'OBJECT']);
        $this->assertSame(['a' => 1], $result['data']);
        Http::assertSent(fn (Request $request) => ($request['generationConfig']['responseMimeType'] ?? null) === 'application/json');
    }

    #[DataProvider('invalidSingleAttachments')]
    public function test_structured_generation_rejects_invalid_single_attachments_before_http(string $name, int $kilobytes, string $mime): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->completion('{"a":1}'))]);
        $file = UploadedFile::fake()->create($name, $kilobytes, $mime);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('الصورة يجب أن تكون JPG أو PNG أو WebP وبحد أقصى 4MB.');
        try {
            app(GeminiClient::class)->generateStructured('Give JSON', ['type' => 'OBJECT'], $file);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function invalidSingleAttachments(): array
    {
        return [
            'unsupported MIME' => ['sheet.svg', 1, 'image/svg+xml'],
            'over byte limit' => ['sheet.png', 4097, 'image/png'],
        ];
    }

    public function test_structured_generation_uses_detected_mime_for_supported_single_attachments(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->completion('{"a":1}'))]);
        $attachments = [
            'image/png' => UploadedFile::fake()->image('sheet.png'),
            // Ogg page containing an Opus identification packet, enough for MIME detection.
            'audio/ogg' => UploadedFile::fake()->createWithContent('voice.ogg', base64_decode('T2dnUwACAAAAAAAAAAABAAAAAAAAAAAAAAABE09wdXNIZWFkAQE4AYC7AAAAAAA=')),
            'application/pdf' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n"),
        ];

        foreach ($attachments as $mime => $fixture) {
            $file = new UploadedFile($fixture->getPathname(), 'upload.jpg', 'image/jpeg', null, true);
            $result = app(GeminiClient::class)->generateStructured('Give JSON', ['type' => 'OBJECT'], $file);

            $this->assertSame(['a' => 1], $result['data']);
            Http::assertSent(fn (Request $request) => $request['contents'][0]['parts'][1]['inlineData'] === [
                'mimeType' => $mime,
                'data' => base64_encode($file->getContent()),
            ]);
        }
        Http::assertSentCount(3);
    }
}
