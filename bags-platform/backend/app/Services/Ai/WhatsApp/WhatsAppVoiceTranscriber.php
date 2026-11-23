<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use App\Services\Ai\GeminiClient;

class WhatsAppVoiceTranscriber
{
    public function __construct(private GeminiClient $gemini) {}

    public function transcribe(string $bytes, string $mime): ?string
    {
        $result = $this->gemini->converse([[
            'role' => 'user',
            'parts' => [
                ['text' => 'Transcribe the speech verbatim in its original language, including Egyptian Arabic. Output only the transcript. For silence or unintelligible speech output [EMPTY].'],
                ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]],
            ],
        ]]);

        $text = trim($result['text']);

        return $text === '' || $text === '[EMPTY]' ? null : $text;
    }
}
