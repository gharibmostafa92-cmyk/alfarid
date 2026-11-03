<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** ملاحظات مراجعة أسبوعية لأنماط المخزون الغريبة — مش اتهامات. */
class InventoryAnomalyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{title:string,detail:string}> $notes */
    public function __construct(private readonly array $notes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'inventory_anomalies',
            'notes' => $this->notes,
            'message' => 'ملاحظات للمراجعة: '.count($this->notes).' ملاحظة على أنماط المخزون هذا الأسبوع.',
        ];
    }
}
