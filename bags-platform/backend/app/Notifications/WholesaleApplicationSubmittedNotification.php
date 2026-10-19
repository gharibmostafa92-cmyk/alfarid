<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** طلب تسجيل تاجر جملة جديد بانتظار المراجعة — للإدارة. */
class WholesaleApplicationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Customer $customer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'wholesale_application_submitted',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_type' => $this->customer->type,
            'message' => "سجّل تاجر جديد ({$this->customer->name}) وبانتظار مراجعة حسابه.",
        ];
    }
}
