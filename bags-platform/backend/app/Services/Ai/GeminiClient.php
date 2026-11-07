<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;

/**
 * Low-level HTTP client for Google Generative AI (Gemini) API with safe retry semantics and token tracking.
 */
class GeminiClient
{
    /**
     * Determines whether a connection exception is a safe-to-retry connect or DNS failure.
     *
     * Read timeouts are excluded because remote execution may have succeeded, risking duplicate billing.
     */
    private static function isConnectFailure(ConnectionException $e): bool
    {
        $message = strtolower($e->getMessage());

        return ! str_contains($message, 'timed out')
            && ! str_contains($message, 'timeout')
            && ! str_contains($message, 'cURL error 28');
    }

    /**
     * Executes standard text generation request against Gemini.
     *
     * @param  string  $prompt  User or prompt text.
     * @param  string|null  $system  Optional system instructions.
     * @param  string|null  $modelOverride  Specific model identifier to route to.
     * @return array{text: string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     *
     * @throws GeminiException If API fails or generation is incomplete.
     */
    public function generate(string $prompt, ?string $system = null, ?string $modelOverride = null): array
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            throw new LogicException('Gemini API key is not configured.');
        }

        $model = $modelOverride ?? (string) config('services.gemini.model', 'gemini-1.5-flash');
        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => (int) config('services.gemini.max_output_tokens', 2048),
            ],
        ];
        if ($system !== null && trim($system) !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::acceptJson()->asJson()->withHeaders(['x-goog-api-key' => $key])
                    ->withOptions(['verify' => config('services.integrations.ca_bundle') ?: true])
                    ->connectTimeout(5)->timeout(max(1, (int) config('services.gemini.timeout_seconds', 20)))
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', $payload);
            } catch (ConnectionException $e) {
                // Retry connect failures only; read timeouts may have executed remotely and retrying risks duplicate billing.
                if ($attempt === 0 && self::isConnectFailure($e)) {
                    logger()->warning('gemini.request_retry', ['method' => 'generate', 'reason' => 'connect_failure']);

                    continue;
                }
                throw new GeminiException('تعذّر الاتصال بخدمة Gemini.');
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt === 0) {
                logger()->warning('gemini.request_retry', ['method' => 'generate', 'reason' => 'http_'.$response->status()]);
                // Respect upstream Retry-After header with a 1-to-5 second backoff window.
                usleep(min(5, max(1, (int) $response->header('Retry-After'))) * 1_000_000);

                continue;
            }
            if (! $response->successful()) {
                logger()->error('gemini.converse_failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 2000),
                    'model' => $model,
                ]);
                throw new GeminiException('تعذّر تنفيذ طلب Gemini.', $response->status());
            }

            $candidate = $response->json('candidates.0', []);
            if (! is_array($candidate)) {
                $candidate = [];
            }
            if (($candidate['finishReason'] ?? null) !== 'STOP') {
                throw new GeminiException('لم ترجع خدمة Gemini إجابة مكتملة.');
            }
            $text = collect($candidate['content']['parts'] ?? [])
                ->reject(fn ($part) => ! empty($part['thought']))
                ->pluck('text')->filter(fn ($part) => is_string($part))->implode('');
            if (trim($text) === '') {
                throw new GeminiException('لم ترجع خدمة Gemini إجابة نصية.');
            }

            $usage = $response->json('usageMetadata', []);

            return [
                'text' => $text,
                'usage' => [
                    'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                    'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                    'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
                ],
            ];
        }

        throw new GeminiException('تعذّر إكمال الطلب بخدمة Gemini.');
    }

    /**
     * Executes structured JSON schema-guided generation request.
     *
     * @param  string  $prompt  User or prompt text.
     * @param  array<string, mixed>  $schema  Target JSON schema specification.
     * @param  UploadedFile|null  $file  Optional image attachment for multimodal analysis.
     * @param  string|null  $modelOverride  Specific model identifier to route to.
     * @return array{data: array<string, mixed>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     *
     * @throws GeminiException If API fails, format is invalid, or generation is incomplete.
     */
    public function generateStructured(string $prompt, array $schema, ?UploadedFile $file = null, ?string $modelOverride = null): array
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            throw new LogicException('Gemini API key is not configured.');
        }

        $model = $modelOverride ?? (string) config('services.gemini.model', 'gemini-1.5-flash');
        $parts = [['text' => $prompt]];

        if ($file !== null) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $file->getMimeType() ?: 'image/jpeg',
                    'data' => base64_encode($file->getContent()),
                ],
            ];
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => (int) config('services.gemini.max_output_tokens', 2048),
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::acceptJson()->asJson()->withHeaders(['x-goog-api-key' => $key])
                    ->withOptions(['verify' => config('services.integrations.ca_bundle') ?: true])
                    ->connectTimeout(5)->timeout(max(1, (int) config('services.gemini.timeout_seconds', 20)))
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', $payload);
            } catch (ConnectionException $e) {
                // Retry connect failures only to avoid duplicate token consumption on server read timeouts.
                if ($attempt === 0 && self::isConnectFailure($e)) {
                    logger()->warning('gemini.request_retry', ['method' => 'generateStructured', 'reason' => 'connect_failure']);

                    continue;
                }
                throw new GeminiException('تعذّر الاتصال بخدمة Gemini.');
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt === 0) {
                logger()->warning('gemini.request_retry', ['method' => 'generateStructured', 'reason' => 'http_'.$response->status()]);
                usleep(min(5, max(1, (int) $response->header('Retry-After'))) * 1_000_000);

                continue;
            }
            if (! $response->successful()) {
                logger()->error('gemini.converse_failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 2000),
                    'model' => $model,
                ]);
                throw new GeminiException('تعذّر تنفيذ طلب Gemini.', $response->status());
            }

            $candidate = $response->json('candidates.0', []);
            if (! is_array($candidate)) {
                $candidate = [];
            }
            if (($candidate['finishReason'] ?? null) !== 'STOP') {
                throw new GeminiException('لم ترجع خدمة Gemini إجابة مكتملة.');
            }
            $raw = collect($candidate['content']['parts'] ?? [])
                ->reject(fn ($part) => ! empty($part['thought']))
                ->pluck('text')->filter(fn ($part) => is_string($part))->implode('');
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new GeminiException('تعذّر تفسير مخرجات Gemini المهيكلة.');
            }

            $usage = $response->json('usageMetadata', []);

            return [
                'data' => $decoded,
                'usage' => [
                    'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                    'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                    'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
                ],
            ];
        }

        throw new GeminiException('تعذّر إكمال الطلب بخدمة Gemini.');
    }

    /**
     * Executes conversational turn with multi-step tool and function-calling support.
     *
     * @param  list<array<string, mixed>>  $contents  Message turn history.
     * @param  list<array<string, mixed>>  $tools  Declared callable tools.
     * @param  string|null  $system  System prompt instructions.
     * @param  string|null  $modelOverride  Specific model identifier to route to.
     * @param  string|null  $toolChoice  Tool choice constraint ('auto', 'none', etc.).
     * @return array{text: string, tool_calls: list<array{name: string, args: array<string, mixed>, id?: string}>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     *
     * @throws GeminiException If API request fails or response cannot be completed.
     */
    public function converse(array $contents, array $tools = [], ?string $system = null, ?string $modelOverride = null, ?string $toolChoice = null): array
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            throw new LogicException('Gemini API key is not configured.');
        }

        $model = $modelOverride ?? (string) config('services.gemini.model', 'gemini-1.5-flash');
        foreach ($contents as $turnIndex => $turn) {
            foreach ($turn['parts'] ?? [] as $partIndex => $part) {
                foreach (['functionCall' => 'args', 'functionResponse' => 'response'] as $node => $field) {
                    if (isset($part[$node][$field]) && is_array($part[$node][$field])) {
                        $contents[$turnIndex]['parts'][$partIndex][$node][$field] = (object) $part[$node][$field];
                    }
                }
            }
        }
        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => (int) config('services.gemini.max_output_tokens', 2048),
            ],
        ];

        if ($system !== null && trim($system) !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($tools !== []) {
            $payload['tools'] = [['functionDeclarations' => $tools]];
            if ($toolChoice !== null) {
                $payload['toolConfig'] = [
                    'functionCallingConfig' => [
                        'mode' => strtoupper($toolChoice) === 'NONE' ? 'NONE' : (strtoupper($toolChoice) === 'ANY' ? 'ANY' : 'AUTO'),
                    ],
                ];
            }
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::acceptJson()->asJson()->withHeaders(['x-goog-api-key' => $key])
                    ->withOptions(['verify' => config('services.integrations.ca_bundle') ?: true])
                    ->connectTimeout(5)->timeout(max(1, (int) config('services.gemini.timeout_seconds', 20)))
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', $payload);
            } catch (ConnectionException $e) {
                // Retry connect failures only to avoid duplicate token consumption on server read timeouts.
                if ($attempt === 0 && self::isConnectFailure($e)) {
                    logger()->warning('gemini.request_retry', ['method' => 'converse', 'reason' => 'connect_failure']);

                    continue;
                }
                throw new GeminiException('تعذّر الاتصال بخدمة Gemini.');
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt === 0) {
                logger()->warning('gemini.request_retry', ['method' => 'converse', 'reason' => 'http_'.$response->status()]);
                // Respect upstream Retry-After header with a 1-to-5 second backoff window.
                usleep(min(5, max(1, (int) $response->header('Retry-After'))) * 1_000_000);

                continue;
            }
            if (! $response->successful()) {
                logger()->error('gemini.converse_failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 2000),
                    'model' => $model,
                ]);
                throw new GeminiException('تعذّر تنفيذ طلب Gemini.', $response->status());
            }
            $candidate = $response->json('candidates.0', []);
            if (! is_array($candidate)) {
                $candidate = []; // Default to empty array so missing candidates fail gracefully via GeminiException instead of TypeError.
            }
            $finish = (string) ($candidate['finishReason'] ?? '');
            $usage = $response->json('usageMetadata', []);
            // Record token usage before error checks so incomplete responses (e.g. MAX_TOKENS, SAFETY) still bill against token budgets.
            $measured = [
                'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
            ];
            if ($finish !== 'STOP') {
                throw GeminiException::withUsage('لم ترجع خدمة Gemini إجابة مكتملة.', $measured, $response->status());
            }
            $parts = $candidate['content']['parts'] ?? [];
            if (! is_array($parts)) {
                $parts = [];
            }
            $text = collect($parts)->reject(fn ($p) => ! empty($p['thought']))->pluck('text')->filter(fn ($p) => is_string($p))->implode('');
            $calls = [];
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['functionCall']['name'])) {
                    $calls[] = ['name' => (string) $part['functionCall']['name'], 'args' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : []];
                    if (isset($part['functionCall']['id']) && is_string($part['functionCall']['id'])) {
                        $calls[count($calls) - 1]['id'] = $part['functionCall']['id'];
                    }
                }
            }

            return [
                'parts' => $parts,
                'text' => $text,
                'calls' => $calls,
                'finish' => $finish,
                'model' => $model,
                'usage' => $measured,
            ];
        }

        throw new GeminiException('تعذّر إكمال الطلب بخدمة Gemini.');
    }
}
