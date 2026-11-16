<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class StaffAuthenticationAlert extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(private readonly string $reason, private readonly ?int $staffId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'authentication_security_alert', 'reason' => $this->reason,
            'staff_id' => $this->staffId,
            'message' => 'تم رصد عدد مرتفع من محاولات الدخول الفاشلة؛ راجع نشاط المصادقة وحسابات الموظفين.'];
    }
}
