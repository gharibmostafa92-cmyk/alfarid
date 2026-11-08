<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Jobs\SendOutboundMessage;
use App\Models\Customer;
use App\Models\OutboundMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persists outbound wholesale customer messages to a database outbox for transactional delivery.
 */
final class WholesaleOutbox
{
    /**
     * Enqueues an outbound notification using idempotency deduplication and opt-in enforcement.
     *
     * @param  string  $key  Idempotency key uniquely identifying this notification event.
     * @param  string  $channel  Delivery channel name (e.g. customer_whatsapp, sms).
     * @param  int|null  $customerId  Recipient customer ID if known.
     * @param  string  $template  Template identifier approved by provider.
     * @param  array<string, mixed>  $payload  Template placeholder variables.
     * @param  CarbonInterface|null  $scheduledFor  Deferred dispatch timestamp if scheduled.
     * @param  bool  $deliver  False when customer lacks opt-in consent to record skipped audit.
     */
    public function queue(
        string $key,
        string $channel,
        ?int $customerId,
        string $template,
        array $payload,
        ?CarbonInterface $scheduledFor = null,
        bool $deliver = true,
    ): void {
        try {
            // Savepoint-guarded: callers queue from inside service transactions
            // (model event listeners), and on Postgres a caught unique
            // violation without a savepoint would abort the whole outer
            // transaction instead of just dropping the duplicate.
            $message = DB::transaction(fn () => OutboundMessage::query()->create([
                'customer_id' => $customerId,
                'channel' => $channel,
                'idempotency_key' => $key,
                'status' => $deliver ? 'pending' : 'skipped',
                'template' => $template,
                'payload' => $payload,
                // Mark as skipped when the customer has not explicitly opted into notifications.
                'error' => $deliver ? null : 'no_opt_in',
                'scheduled_for' => $scheduledFor,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Silently drop duplicate idempotency keys to guarantee at-most-once enqueue semantics.
            return;
        }

        if ($deliver) {
            SendOutboundMessage::dispatch($message->id);
        }
    }

    /**
     * Checks if the merchant has granted explicit opt-in consent for WhatsApp communications.
     */
    public function customerAllowsWhatsapp(?Customer $customer): bool
    {
        // Meta Business Policy mandates verified opt-in consent prior to sending unsolicited messages.
        return $customer?->whatsapp_opt_in_at !== null;
    }

    /**
     * Calculates next allowed contact time based on merchant preferred business hours.
     *
     * @param  Customer|null  $customer  Customer with optional preferred_contact_hours settings.
     * @return CarbonInterface Target contact timestamp aligned with business window in local timezone.
     */
    public function nextContactAt(?Customer $customer): CarbonInterface
    {
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $now = now($tz);
        $hours = $customer?->preferred_contact_hours;
        if (! is_array($hours) || ! isset($hours['from'], $hours['to'])) {
            return $now;
        }

        try {
            $from = $now->copy()->setTimeFromTimeString((string) $hours['from']);
            $to = $now->copy()->setTimeFromTimeString((string) $hours['to']);
        } catch (Throwable) {
            return $now;
        }

        if ($now->betweenIncluded($from, $to)) {
            return $now;
        }

        // If outside hours, defer until the opening hour today or the following morning.
        return $now->lt($from) ? $from : $from->copy()->addDay();
    }
}
