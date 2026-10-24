<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * أُنشئت تحويلة مخزون جديدة بانتظار الاعتماد.
 *
 * M1: بعد الـ commit فقط — داخل معاملة الإنشاء كان العامل قد يلتقط
 * المهمة قبل الحفظ فيفشل بـ ModelNotFoundException.
 */
class TransferPendingApproval implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public StockTransfer $transfer) {}
}
