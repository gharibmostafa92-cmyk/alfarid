<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * انخفض المتاح لمتغير في فرع إلى حد إعادة الطلب أو دونه — يُطلق من
 * InventoryService::recordMovement عند عبور الحد نزولًا فقط (لا تكرار
 * مع كل حركة لاحقة والرصيد تحت الحد أصلًا).
 */
class StockLowDetected
{
    use Dispatchable;

    public function __construct(
        public int $productVariantId,
        public int $branchId,
        public float $available,
        public float $reorderLevel,
    ) {}
}
