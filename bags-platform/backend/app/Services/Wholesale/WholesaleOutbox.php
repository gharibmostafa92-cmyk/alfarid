<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Jobs\SendOutboundMessage;
use App\Models\Customer;
use App\Models\OutboundMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Writes customer messages to the outbox queue for background transmission.
 */
final class WholesaleOutbox
{
    /** @param  array<string, mixed>  $payload */
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
            $message = OutboundMessage::query()->create([
                'customer_id' => $customerId,
                'channel' => $channel,
                'idempotency_key' => $key,
                'status' => $deliver ? 'pending' : 'skipped',
                'template' => $template,
                'payload' => $payload,
                'error' => $deliver ? null : 'no_opt_in',
                'scheduled_for' => $scheduledFor,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($deliver) {
            SendOutboundMessage::dispatch($message->id);
        }
    }

    public function customerAllowsWhatsapp(?Customer $customer): bool
    {
        return $customer?->whatsapp_opt_in_at !== null;
    }

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

        return $now->lt($from) ? $from : $from->copy()->addDay();
    }
}
