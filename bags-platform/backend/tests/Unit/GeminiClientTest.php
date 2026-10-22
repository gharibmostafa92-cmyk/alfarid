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
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Http::preventStrayRequests();
    }

    private function completion(): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
            ['text' => 'Internal reasoning', 'thought' => true], ['text' => 'OK'],
        ]]]], 'usageMetadata' => ['promptTokenCount' => 4, 'candidatesTokenCount' => 2, 'totalTokenCount' => 9]];
    }

    public function test_text_and_images_use_the_server_side_key_and_return_usage_without_reasoning(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->completion())]);
        $result = app(GeminiClient::class)->generate('Describe this bag.', [UploadedFile::fake()->image('bag.png')], 'Answer in Arabic.');
        $this->assertSame('OK', $result['text']);
        $this->assertSame(9, $result['usage']['total_tokens']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key')
            && $request['contents'][0]['parts'][1]['inlineData']['mimeType'] === 'image/png'
            && $request['systemInstruction']['parts'][0]['text'] === 'Answer in Arabic.');
    }

    public function test_retryable_failure_is_retried_once(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push([], 429)->push($this->completion())]);
        $this->assertSame('OK', app(GeminiClient::class)->generate('Hello')['text']);
        Http::assertSentCount(2);
    }

    public function test_provider_errors_do_not_expose_provider_bodies_or_credentials(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'private-prompt test-key']], 403)]);
        try {
            app(GeminiClient::class)->generate('private-prompt');
            $this->fail('Expected a provider exception.');
        } catch (GeminiException $exception) {
            $this->assertSame(403, $exception->providerStatus);
            $this->assertStringNotContainsString('private-prompt', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_missing_credentials_fail_before_sending_a_request(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => '']);
        $this->expectException(LogicException::class);
        app(GeminiClient::class)->generate('Hello');
    }

    public function test_invalid_images_are_not_sent_to_the_provider(): void
    {
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        app(GeminiClient::class)->generate('Describe', [UploadedFile::fake()->create('file.svg', 1, 'image/svg+xml')]);
    }

    public function test_incomplete_output_is_not_reported_as_a_success(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'MAX_TOKENS']]])]);
        $this->expectException(GeminiException::class);
        app(GeminiClient::class)->generate('Hello');
    }
}
