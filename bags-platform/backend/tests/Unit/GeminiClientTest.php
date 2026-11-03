<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use App\Services\Ai\ToolRegistry;
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

    public function test_connect_failure_is_retried_once(): void
    {
        $calls = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$calls) {
            if ($calls++ === 0) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to generativelanguage.googleapis.com');
            }

            return Http::response($this->completion());
        }]);
        $this->assertSame('OK', app(GeminiClient::class)->generate('Hello')['text']);
        // Thrown stubs bypass Http::assertSentCount — the call counter proves the retry.
        $this->assertSame(2, $calls);
    }

    public function test_read_timeout_is_not_retried_to_avoid_double_billing(): void
    {
        $calls = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$calls): never {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
        }]);
        try {
            app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
            $this->fail('Expected a provider exception.');
        } catch (GeminiException) {
            $this->assertSame(1, $calls);
        }
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

    public function test_converse_returns_function_call_and_preserves_thought_signature_parts(): void
    {
        $parts = [['text' => 'reasoning', 'thought' => true, 'thoughtSignature' => 'sig-1'], ['functionCall' => ['name' => 'set_branch_status', 'args' => ['branch_id' => 7, 'active' => false]]]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => $parts]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 3, 'totalTokenCount' => 13]])]);
        $result = app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'وقف الفرع']]]], [['name' => 'set_branch_status', 'parameters' => ['type' => 'OBJECT']]], 'system');
        $this->assertSame('', $result['text']);
        $this->assertSame('set_branch_status', $result['calls'][0]['name']);
        $this->assertSame(['branch_id' => 7, 'active' => false], $result['calls'][0]['args']);
        $this->assertSame($parts, $result['parts']);
        $this->assertSame('sig-1', $result['parts'][0]['thoughtSignature']);
        Http::assertSent(fn (Request $r) => $r['tools'][0]['functionDeclarations'][0]['name'] === 'set_branch_status' && $r['toolConfig']['functionCallingConfig']['mode'] === 'AUTO');
    }

    public function test_converse_allows_function_call_without_text(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['functionCall' => ['name' => 'list_branches', 'args' => []]]]]]], 'usageMetadata' => []])]);
        $result = app(GeminiClient::class)->converse([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
        $this->assertSame('list_branches', $result['calls'][0]['name']);
    }

    public function test_tool_declarations_encode_empty_properties_as_json_objects(): void
    {
        $definitions = app(ToolRegistry::class)->definitions();
        $declarations = array_values(array_map(fn ($definition) => $definition['declaration'], $definitions));
        $encoded = json_decode(json_encode($declarations, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
        foreach ($encoded as $declaration) {
            $this->assertInstanceOf(\stdClass::class, $declaration->parameters->properties, $declaration->name);
        }
    }

    public function test_function_history_encodes_empty_argument_and_response_maps_as_objects(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'جاهز']]]]]])]);
        app(GeminiClient::class)->converse([
            ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'list_roles', 'args' => []], 'thoughtSignature' => 'sig']]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'list_roles', 'response' => []]]]],
        ]);
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body());

            return $body->contents[0]->parts[0]->functionCall->args instanceof \stdClass
                && $body->contents[1]->parts[0]->functionResponse->response instanceof \stdClass;
        });
    }

    public function test_incomplete_output_is_not_reported_as_a_success(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'MAX_TOKENS']]])]);
        $this->expectException(GeminiException::class);
        app(GeminiClient::class)->generate('Hello');
    }
}
