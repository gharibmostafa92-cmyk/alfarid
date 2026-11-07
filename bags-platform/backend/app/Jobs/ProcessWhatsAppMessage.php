<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Services\Ai\WhatsApp\WhatsAppGatewayException;
use App\Services\Ai\WhatsApp\WhatsAppInboundProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Queued background job that decrypts and dispatches inbound WhatsApp messages to the AI assistant.
 */
class ProcessWhatsAppMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * Releasing the job on lock contention (423) increments attempt counters.
     * $maxExceptions isolates genuine uncaught crashes from normal backoff retries.
     */
    public int $tries = 25;

    public int $maxExceptions = 3;

    /** @param array<string, mixed> $message */
    public function __construct(public int $inboundId, public array $message)
    {
        $this->onConnection('whatsapp');
        $this->onQueue('whatsapp-ai');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $jid = (string) ($this->message['remote_jid'] ?? '');
        $alt = (string) ($this->message['remote_jid_alt'] ?? '');
        $identity = AiWhatsappIdentity::query()
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->where(function ($query) use ($jid, $alt): void {
                foreach (array_filter([$jid, $alt]) as $key) {
                    $query->orWhere('wa_jid', $key)->orWhere('wa_lid', $key);
                }
            })
            ->first();
        $key = $identity ? 'wa-user:'.$identity->user_id : 'wa-pending:'.($jid !== '' ? $jid : $alt);

        return [(new WithoutOverlapping($key))->releaseAfter(15)->expireAfter(360)];
    }

    public function handle(WhatsAppInboundProcessor $processor): void
    {
        $inbound = AiWhatsappInbound::query()->find($this->inboundId);
        if ($inbound === null) {
            return;
        }
        try {
            $result = $processor->process($inbound, $this->message);
        } catch (WhatsAppGatewayException) {
            // Delivery can fail after a turn or action has already committed.
            // Replaying the inbound message could select and execute another card.
            $inbound->forceFill(['status' => 'failed', 'ignore_reason' => 'gateway_delivery'])->save();

            return;
        }
        if ($result === 'release') {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AiWhatsappInbound::query()->whereKey($this->inboundId)->where('status', 'queued')->update([
            'status' => 'failed',
            'ignore_reason' => 'exception',
            'updated_at' => now(),
        ]);
    }
}
