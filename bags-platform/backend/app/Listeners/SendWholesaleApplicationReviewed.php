<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WholesaleApplicationReviewed;
use App\Notifications\WholesaleApplicationReviewedNotification;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * إشعار التاجر بنتيجة مراجعة حسابه (قبول/رفض).
 * 0.5: التنفيذ بعد الـ commit فقط — وإلا فشل الـ transaction بعد
 * الـ dispatch كان يبعت "اتقبلت" على حاجة متنفذتش (خصوصًا في مسار
 * الـ AI حيث التنفيذ جوه transaction متداخلة في PendingActionService).
 * ملحوظة: ShouldQueueAfterCommit مش ShouldHandleEventsAfterCommit —
 * الأخير بيأجّل المستمعين غير المُصفوفة فقط، أما المستمع المصفوف
 * (ShouldQueue) فلا يؤجَّل إلا بـ ShouldQueueAfterCommit (موثّق
 * بكود الفريمورك واختبار الـ rollback).
 */
class SendWholesaleApplicationReviewed implements ShouldQueueAfterCommit
{
    public function handle(WholesaleApplicationReviewed $event): void
    {
        $event->customer->notify(new WholesaleApplicationReviewedNotification($event->decision, $event->reason));
    }
}
