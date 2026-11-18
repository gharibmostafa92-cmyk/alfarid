<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Models\InboundMessage;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Processes an inbound wholesale customer WhatsApp message asynchronously
 * (media download, Gemini/assistant inference, and Graph API reply).
 */
class ProcessCustomerWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /**
     * @param  array{id: string, from: string, text: string, button_id: ?string, kind: string, media_id: ?string}  $message
     */
    public function __construct(
        public int $inboundId,
        public int $customerId,
        public array $message,
        public string $sender,
    ) {
        // Uses the same database connection as inbound_messages so ingestion
        // and enqueueing can commit atomically in the webhook transaction.
        $this->onConnection('whatsapp');
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('customer-whatsapp-inbound:'.$this->inboundId))
                ->expireAfter(180)
                ->releaseAfter(20),
        ];
    }

    public function handle(CustomerWholesaleAssistant $assistant, SettingService $settings): void
    {
        $customer = Customer::query()->find($this->customerId);
        $inbound = InboundMessage::query()->find($this->inboundId);
        if ($customer === null || $inbound === null) {
            return;
        }

        $payload = is_array($inbound->payload) ? $inbound->payload : [];
        if (($payload['assistant_shadow_processed'] ?? false) || ($payload['assistant_reply_sent_at'] ?? null) !== null) {
            return;
        }

        $shadowMode = $settings->getBool('ai_wholesale_shadow_mode', true);
        $token = config('services.whatsapp_business.token');
        $phoneId = config('services.whatsapp_business.phone_number_id');
        if (! $shadowMode && (! is_string($token) || $token === '' || ! is_string($phoneId) || $phoneId === '')) {
            throw new RuntimeException('WhatsApp Business credentials are not configured.');
        }

        $reply = is_string($payload['assistant_reply'] ?? null)
            ? $payload['assistant_reply']
            : $this->respond($assistant, $customer);
        if ($reply === '') {
            $this->persistReply($inbound->id, '', $shadowMode);

            return;
        }

        $state = $this->persistReply($inbound->id, $reply, $shadowMode);
        if ($state['stop'] || $shadowMode) {
            return;
        }

        Http::withToken($token)
            ->connectTimeout(5)
            ->timeout(20)
            ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($this->sender, '+'),
                'type' => 'text',
                'text' => ['body' => mb_substr($reply, 0, 4096)],
            ])
            ->throw();

        DB::transaction(function () use ($inbound): void {
            $locked = InboundMessage::query()->lockForUpdate()->find($inbound->id);
            if ($locked === null) {
                return;
            }
            $payload = is_array($locked->payload) ? $locked->payload : [];
            $payload['assistant_reply_sent_at'] ??= now()->toIso8601String();
            $locked->forceFill(['payload' => $payload])->save();
        });
    }

    /**
     * Stores the generated reply before external delivery so queue retries reuse
     * the same assistant result instead of rerunning a potentially stateful flow.
     *
     * @return array{stop: bool}
     */
    private function persistReply(int $inboundId, string $reply, bool $shadowMode): array
    {
        return DB::transaction(function () use ($inboundId, $reply, $shadowMode): array {
            $inbound = InboundMessage::query()->lockForUpdate()->find($inboundId);
            if ($inbound === null) {
                return ['stop' => true];
            }
            $payload = is_array($inbound->payload) ? $inbound->payload : [];
            if (($payload['assistant_shadow_processed'] ?? false)
                || ($payload['assistant_reply_sent_at'] ?? null) !== null) {
                return ['stop' => true];
            }
            if (! is_string($payload['assistant_reply'] ?? null)) {
                $payload['assistant_reply'] = $reply;
                $payload['assistant_reply_created_at'] = now()->toIso8601String();
            }
            if ($shadowMode) {
                $payload['assistant_shadow_processed'] = true;
                $payload['assistant_shadow_processed_at'] = now()->toIso8601String();
            }
            $inbound->forceFill(['payload' => $payload])->save();

            return ['stop' => false];
        });
    }

    private function respond(CustomerWholesaleAssistant $assistant, Customer $customer): string
    {
        if (in_array($this->message['kind'], ['image', 'audio'], true) && $this->message['media_id'] !== null) {
            if (($verification = $assistant->requireVerifiedChannel($customer, sender: $this->sender)) !== null) {
                return $verification;
            }

            $bytes = $this->downloadMedia($this->message['media_id']);
            if ($bytes === null) {
                return 'الملف موصلش. ابعته تاني من فضلك.';
            }

            $path = tempnam(sys_get_temp_dir(), 'wa-media');
            if ($path === false) {
                return 'الملف موصلش. ابعته تاني من فضلك.';
            }
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                @unlink($path);

                return 'الملف موصلش. ابعته تاني من فضلك.';
            }
            $isImage = $this->message['kind'] === 'image';
            $file = new UploadedFile(
                $path,
                $isImage ? 'sheet.jpg' : 'voice.ogg',
                $isImage ? 'image/jpeg' : 'audio/ogg',
                null,
                true,
            );
            try {
                return $isImage
                    ? $assistant->readOrderImage($customer, $file)
                    : $assistant->transcribeAndReply($customer, $file);
            } finally {
                @unlink($path);
            }
        }

        return $assistant->reply($customer, $this->message['text'], $this->message['button_id'], $this->sender);
    }

    /**
     * Downloads media bytes from Meta Graph API with strict ID, CDN host, and streaming size guards.
     */
    public function downloadMedia(string $mediaId): ?string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $mediaId)) {
            return null;
        }

        $token = config('services.whatsapp_business.token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $meta = Http::withToken($token)
            ->connectTimeout(3)
            ->timeout(10)
            ->get("https://graph.facebook.com/v21.0/{$mediaId}");
        if ($meta->serverError() || $meta->status() === 429) {
            throw new RuntimeException('Temporary WhatsApp media metadata failure.');
        }
        if (! $meta->successful()) {
            return null;
        }

        $url = $meta->json('url');
        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            return null;
        }

        $bin = Http::withToken($token)
            ->withOptions(['stream' => true])
            ->connectTimeout(3)
            ->timeout(10)
            ->get($url);
        if ($bin->serverError() || $bin->status() === 429) {
            throw new RuntimeException('Temporary WhatsApp media download failure.');
        }
        if (! $bin->successful()) {
            return null;
        }

        $max = max(1, (int) config('services.gemini.max_image_bytes', 4 * 1024 * 1024));
        $stream = $bin->toPsrResponse()->getBody();
        $buffer = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $max) {
                    return null;
                }
            }
        } finally {
            $stream->close();
        }

        return $buffer === '' ? null : $buffer;
    }

    private function isAllowedMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        foreach (['fbsbx.com', 'fbcdn.net', 'whatsapp.net'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
