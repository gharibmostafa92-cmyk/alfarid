<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Models\AiWhatsappOutbound;
use App\Models\AiPendingAction;
use App\Services\Ai\WhatsApp\WhatsAppGatewayException;
use App\Services\Ai\WhatsApp\WhatsAppInboundProcessor;
use App\Services\Ai\WhatsApp\E164Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        $jids = array_values(array_unique(array_filter([$jid, $alt], fn (string $value): bool => $value !== '')));
        $phones = array_values(array_unique(array_filter(array_map(E164Phone::fromWhatsappJid(...), $jids))));
        $identity = ($jids !== [] || $phones !== [])
            ? AiWhatsappIdentity::query()
                ->where(function ($query) use ($jids, $phones): void {
                    if ($jids !== []) {
                        $query->whereIn('wa_jid', $jids)->orWhereIn('wa_lid', $jids);
                    }
                    if ($phones !== []) {
                        $query->orWhereIn('phone_e164', $phones);
                    }
                })
                ->first()
            : null;

        // Acquire sender locks from every current and previously linked alias.
        // Keeping those locks alongside the user lock bridges the interval where
        // a link message changes from an unknown JID to a resolved identity.
        $lockIds = array_map(fn (string $value): string => 'jid:'.$value, $jids);
        foreach ($phones as $phone) {
            $lockIds[] = 'phone:'.$phone;
        }
        if ($identity !== null) {
            foreach ([$identity->wa_jid, $identity->wa_lid] as $alias) {
                if (is_string($alias) && $alias !== '') {
                    $lockIds[] = 'jid:'.$alias;
                }
            }
            if (is_string($identity->phone_e164) && $identity->phone_e164 !== '') {
                $lockIds[] = 'phone:'.$identity->phone_e164;
            }
        }

        // A LID-only first pairing message may not yet match the identity's
        // stored phone or JIDs. Its one-time token still identifies the target
        // user, so the following linked message shares the same user lock.
        if (preg_match('/^ربط\s+([A-Za-z0-9]{4}-[A-Za-z0-9]{4})\s*$/u', trim((string) ($this->message['text'] ?? '')), $matches)) {
            $tokenHash = hash('sha256', strtoupper($matches[1]));
            $pendingIdentity = AiWhatsappIdentity::query()
                ->whereHas('linkTokens', fn ($query) => $query->where('token_hash', $tokenHash)
                    ->whereNull('consumed_at')->where('expires_at', '>', now()))
                ->first();
            $identity ??= $pendingIdentity;
        }

        $keys = array_map(fn (string $value): string => 'wa-sender:'.hash('sha256', $value), array_values(array_unique($lockIds)));
        if ($identity !== null) {
            $keys[] = 'wa-user:'.$identity->user_id;
        }
        sort($keys, SORT_STRING);

        return array_map(fn (string $key): WithoutOverlapping => (new WithoutOverlapping($key))->releaseAfter(15)->expireAfter(360), array_values(array_unique($keys)));
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
            if ($inbound->refresh()->status === 'queued') {
                $inbound->forceFill(['status' => 'failed', 'ignore_reason' => 'gateway_delivery'])->save();
            }

            return;
        }
        if ($result === 'release') {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $inbound = AiWhatsappInbound::query()->whereKey($this->inboundId)->lockForUpdate()->first();
            if ($inbound === null) {
                return;
            }
            if ($inbound->status === 'queued') {
                $inbound->forceFill(['status' => 'failed', 'ignore_reason' => 'exception'])->save();
            }

            AiWhatsappOutbound::query()
                ->where('inbound_id', $inbound->id)
                ->where('delivery_status', 'sending')
                ->update([
                    'delivery_status' => 'unknown',
                    'recipient_jid' => null,
                    'body' => null,
                    'updated_at' => now(),
                ]);
            AiWhatsappOutbound::query()
                ->where('inbound_id', $inbound->id)
                ->where('delivery_status', 'pending')
                ->update([
                    'delivery_status' => 'blocked',
                    'recipient_jid' => null,
                    'body' => null,
                    'updated_at' => now(),
                ]);

            AiWhatsappOutbound::query()
                ->where('inbound_id', $inbound->id)
                ->whereIn('delivery_status', ['sent', 'unknown', 'blocked'])
                ->update([
                    'recipient_jid' => null,
                    'body' => null,
                    'updated_at' => now(),
                ]);
            $actionIds = AiWhatsappOutbound::query()
                ->where('inbound_id', $inbound->id)
                ->whereIn('delivery_status', ['unknown', 'blocked'])
                ->whereNotNull('pending_action_id')
                ->pluck('pending_action_id')
                ->unique();
            foreach ($actionIds as $actionId) {
                if (AiWhatsappOutbound::query()->where('pending_action_id', $actionId)->where('delivery_status', 'sent')->exists()) {
                    continue;
                }
                $action = AiPendingAction::query()->whereKey($actionId)->where('status', 'pending')->first();
                $action?->forceFill([
                    'status' => 'failed',
                    'result' => ['delivery_error' => 'whatsapp_card_delivery_unconfirmed'],
                ])->save();
            }
        });
    }
}
