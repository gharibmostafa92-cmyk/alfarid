<?php

declare(strict_types=1);

namespace App\Notifications\Wholesale;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies applicant merchant of their onboarding application review outcome (approved or rejected).
 */
class WholesaleApplicationReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $decision,
        private readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $approved = $this->decision === 'approved';
        $message = $approved
            ? 'تمت الموافقة على حساب الجملة الخاص بك — يمكنك الآن الشراء بأسعار الجملة.'
            : 'نأسف، تم رفض طلب تفعيل حساب الجملة الخاص بك'.($this->reason !== null ? " — السبب: {$this->reason}" : '.');

        return [
            'kind' => 'wholesale_application_reviewed',
            'decision' => $this->decision,
            'reason' => $this->reason,
            'message' => $message,
            // Standardized schema for wholesale portal: {title, body, icon, link:{screen, id}}
            'title' => $approved ? 'تمت الموافقة على حساب الجملة' : 'تم رفض طلب تفعيل حساب الجملة',
            'body' => $message,
            'icon' => $approved ? 'fa-circle-check' : 'fa-circle-xmark',
            'link' => ['screen' => null, 'id' => null],
        ];
    }
}
