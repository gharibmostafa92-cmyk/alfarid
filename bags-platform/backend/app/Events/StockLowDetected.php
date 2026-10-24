<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * انخفض المتاح لمتغير في فرع إلى حد إعادة الطلب أو دونه — يُطلق من
 * InventoryService::recordMovement عند عبور الحد نزولًا فقط (لا تكرار
 * مع كل حركة لاحقة والرصيد تحت الحد أصلًا).
 *
 * M1: بعد الـ commit فقط — إطلاقه داخل المعاملة كان يدخل مهامًا للطابور
 * عن حركات قد تتراجع، ويفشل التسلسل لو التقطها العامل قبل الـ commit.
 */
class StockLowDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $productVariantId,
        public int $branchId,
        public float $available,
        public float $reorderLevel,
    ) {}
}
