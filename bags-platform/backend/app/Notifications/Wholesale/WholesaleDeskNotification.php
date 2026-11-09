<?php

declare(strict_types=1);

namespace App\Notifications\Wholesale;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Internal wholesale event notification containing operational IDs and metrics without raw receipt content.
 */
class WholesaleDeskNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        private readonly string $kind,
        private readonly string $message,
        private readonly array $payload = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            ...$this->payload,
        ];
    }
}
