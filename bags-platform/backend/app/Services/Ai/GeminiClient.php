<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;

class GeminiClient
{
    /**
     * @param  list<UploadedFile>  $images
     * @return array{text: string, model: string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    public function generate(string $prompt, array $images = [], ?string $system = null, ?string $model = null): array
    {
        $key = (string) config('services.gemini.api_key');
        $model ??= config('services.gemini.model');
        if ($key === '' || ! is_string($model) || ! preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
            throw new LogicException('إعدادات Gemini غير مكتملة.');
        }
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('نص الطلب مطلوب.');
        }
        if (count($images) > config('services.gemini.max_images', 5)) {
            throw new InvalidArgumentException('الحد الأقصى 5 صور في الطلب.');
        }

        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            if (! $image instanceof UploadedFile || ! $image->isValid()
                || ! in_array($image->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)
                || $image->getSize() > config('services.gemini.max_image_bytes', 4 * 1024 * 1024)) {
                throw new InvalidArgumentException('الصورة يجب أن تكون JPG أو PNG أو WebP وبحد أقصى 4MB.');
            }
            $parts[] = ['inlineData' => ['mimeType' => $image->getMimeType(), 'data' => base64_encode($image->getContent())]];
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
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
            } catch (ConnectionException) {
                if ($attempt === 0) {
                    continue;
                }
                throw new GeminiException('تعذّر الاتصال بخدمة Gemini.');
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt === 0) {
                usleep(250000);

                continue;
            }
            if (! $response->successful()) {
                throw new GeminiException('تعذّر تنفيذ طلب Gemini.', $response->status());
            }

            $candidate = $response->json('candidates.0', []);
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

            return ['text' => trim($text), 'model' => $model, 'usage' => [
                'input_tokens' => $input, 'output_tokens' => $output,
                'total_tokens' => (int) ($usage['totalTokenCount'] ?? $input + $output),
            ]];
        }

        throw new GeminiException('تعذّر تنفيذ طلب Gemini.');
    }
}
