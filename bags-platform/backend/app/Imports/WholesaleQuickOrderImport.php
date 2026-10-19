<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * قراءة خام لملف الطلب السريع (sku, qty): تُخزَّن صفوف أول ورقة في
 * الخاصية rows — التطبيع والتحقق في QuickOrderController والخدمة.
 */
class WholesaleQuickOrderImport implements ToArray
{
    /** @var list<array<int, mixed>> */
    public array $rows = [];

    public function array(array $array): void
    {
        if ($this->rows === []) {
            $this->rows = $array;
        }
    }
}
