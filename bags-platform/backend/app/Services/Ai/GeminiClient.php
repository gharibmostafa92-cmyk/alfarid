<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * Low-level HTTP client for Google Generative AI (Gemini) API with safe retry semantics and token tracking.
 */
class GeminiClient
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

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
     * Validates API key and model name against path traversal or empty values.
     *
     * @return array{0: string, 1: string}
     */
    private static function resolveConfig(?string $modelOverride): array
    {
        $key = (string) config('services.gemini.api_key');
        $model = $modelOverride ?? config('services.gemini.model');
        if ($key === '' || ! is_string($model) || ! preg_match('/^[a-zA-Z0-9._-]+$/', $model) || str_contains($model, '..')) {
            throw new LogicException('إعدادات Gemini غير مكتملة.');
        }

        return [$key, $model];
    }

    /**
     * Normalizes and validates uploaded media files into Gemini inlineData parts.
     *
     * @param  array<int, UploadedFile>|UploadedFile|null  $files
     * @param  list<string>  $allowedMimes
     * @return list<array<string, mixed>>
     */
    private static function buildMediaParts(array|UploadedFile|null $files, array $allowedMimes): array
    {
        if ($files === null) {
            return [];
        }
        $list = $files instanceof UploadedFile ? [$files] : array_values($files);
        if (count($list) > (int) config('services.gemini.max_images', 5)) {
            throw new InvalidArgumentException('الحد الأقصى 5 صور في الطلب.');
        }

        $maxBytes = (int) config('services.gemini.max_image_bytes', 4 * 1024 * 1024);
        $parts = [];
        foreach ($list as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()
                || ! in_array($file->getMimeType(), $allowedMimes, true)
                || $file->getSize() > $maxBytes) {
                throw new InvalidArgumentException('الصورة يجب أن تكون JPG أو PNG أو WebP وبحد أقصى 4MB.');
            }
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $file->getMimeType() ?: 'image/jpeg',
                    'data' => base64_encode((string) $file->getContent()),
                ],
            ];
        }

        return $parts;
    }

    /**
     * Executes standard text/multimodal generation request against Gemini.
     *
     * @param  string  $prompt  User or prompt text.
     * @param  list<UploadedFile>|string|null  $images  Uploaded image attachments (or system prompt if string passed as 2nd arg).
     * @param  string|null  $system  Optional system instructions.
     * @param  string|null  $model  Specific model identifier to route to.
     * @return array{text: string, model: string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     *
     * @throws GeminiException If API fails or generation is incomplete.
     */
    public function generate(string $prompt, array|string|null $images = [], ?string $system = null, ?string $model = null): array
    {
        if (is_string($images)) {
            $model = $system;
            $system = $images;
            $images = [];
        }
        [$key, $model] = self::resolveConfig($model);
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('نص الطلب مطلوب.');
        }

        $parts = array_merge(
            [['text' => $prompt]],
            self::buildMediaParts($images ?? [], self::IMAGE_MIMES)
        );

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
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
            $input = (int) ($usage['promptTokenCount'] ?? 0);
            $output = (int) ($usage['candidatesTokenCount'] ?? 0);

            return [
                'text' => trim($text),
                'model' => $model,
                'usage' => [
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                    'total_tokens' => (int) ($usage['totalTokenCount'] ?? ($input + $output)),
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
     * @param  list<UploadedFile>|UploadedFile|null  $images  Optional raster images, or a single Ogg voice note or PDF receipt.
     * @param  string|null  $model  Specific model identifier to route to.
     * @return array{data: array<string, mixed>, model: string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     *
     * @throws GeminiException If API fails, format is invalid, or generation is incomplete.
     */
    public function generateStructured(string $prompt, array $schema, array|UploadedFile|null $images = null, ?string $model = null): array
    {
        [$key, $model] = self::resolveConfig($model);
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('نص الطلب مطلوب.');
        }
        if ($schema === []) {
            throw new InvalidArgumentException('مخطط الاستجابة مطلوب.');
        }

        $allowedMimes = $images instanceof UploadedFile
            ? [...self::IMAGE_MIMES, 'audio/ogg', 'application/pdf']
            : self::IMAGE_MIMES;
        $parts = array_merge([['text' => $prompt]], self::buildMediaParts($images, $allowedMimes));

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
            if (trim($raw) === '') {
                throw new GeminiException('لم ترجع خدمة Gemini إجابة نصية.');
            }
            try {
                $decoded = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new GeminiException('لم ترجع خدمة Gemini بنية JSON صالحة.');
            }
            if (! is_array($decoded)) {
                throw new GeminiException('لم ترجع خدمة Gemini بنية JSON صالحة.');
            }

            $usage = $response->json('usageMetadata', []);
            $input = (int) ($usage['promptTokenCount'] ?? 0);
            $output = (int) ($usage['candidatesTokenCount'] ?? 0);

            return [
                'data' => $decoded,
                'model' => $model,
                'usage' => [
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                    'total_tokens' => (int) ($usage['totalTokenCount'] ?? ($input + $output)),
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
     * @return array{parts: list<array<string, mixed>>, text: string, calls: list<array{name: string, args: array<string, mixed>, id?: string}>, finish: string, model: string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     *
     * @throws GeminiException If API request fails or response cannot be completed.
     */
    public function converse(array $contents, array $tools = [], ?string $system = null, ?string $modelOverride = null, ?string $toolChoice = null): array
    {
        [$key, $model] = self::resolveConfig($modelOverride);
        if ($contents === []) {
            throw new InvalidArgumentException('محتوى المحادثة مطلوب.');
        }

        // Preserve empty JSON objects ({}) for functionCall.args and functionResponse.response across DB hydration.
        foreach ($contents as $turnIndex => $turn) {
            foreach ($turn['parts'] ?? [] as $partIndex => $part) {
                foreach (['functionCall' => 'args', 'functionResponse' => 'response'] as $node => $field) {
                    if (isset($part[$node][$field]) && is_array($part[$node][$field])) {
                        $contents[$turnIndex]['parts'][$partIndex][$node][$field] = (object) $part[$node][$field];
                    }
                }
            }
        }

        $maxOutputTokens = max(1024, (int) config('services.gemini.max_output_tokens', 2048));
        $generationConfig = [
            'temperature' => 0.2,
            'maxOutputTokens' => $maxOutputTokens,
        ];
        if (preg_match('/^gemini-2\.5/i', $model) === 1) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => min(1024, (int) ($maxOutputTokens / 4))];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => $generationConfig,
        ];

        if ($system !== null && trim($system) !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($tools !== []) {
            $payload['tools'] = [['functionDeclarations' => $tools]];
            $mode = $toolChoice !== null
                ? (strtoupper($toolChoice) === 'NONE' ? 'NONE' : (strtoupper($toolChoice) === 'ANY' ? 'ANY' : 'AUTO'))
                : 'AUTO';
            $payload['toolConfig'] = [
                'functionCallingConfig' => [
                    'mode' => $mode,
                ],
            ];
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
                        $calls[array_key_last($calls)]['id'] = $part['functionCall']['id'];
                    }
                }
            }
            if ($calls === [] && trim($text) === '') {
                throw GeminiException::withUsage('لم ترجع خدمة Gemini إجابة مكتملة.', $measured);
            }
            $input = $measured['input_tokens'];
            $output = max($measured['output_tokens'], $measured['total_tokens'] - $input);

            return [
                'parts' => $parts,
                'text' => trim($text),
                'calls' => $calls,
                'finish' => $finish,
                'model' => $model,
                'usage' => [
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                    'total_tokens' => max($measured['total_tokens'], $input + $output),
                ],
            ];
        }

        throw new GeminiException('تعذّر إكمال الطلب بخدمة Gemini.');
    }
}
