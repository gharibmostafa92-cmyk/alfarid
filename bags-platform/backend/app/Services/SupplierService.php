<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * إدارة الموردين: قاعدة الرصيد الوحيدة هنا أن current_balance يبدأ من
 * opening_balance لحظة الإنشاء؛ بعدها لا يتحرك إلا عبر SupplierFinanceService
 * (اعتماد فواتير ودفعات) — تعديل بيانات المورد لا يمس رصيده الجاري.
 */
class SupplierService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data): Supplier {
            $data['current_balance'] = $data['opening_balance'] ?? 0;

            return Supplier::create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        // الرصيد الجاري نتيجة دفترية — لا يُعدَّل يدويًا من شاشة المورد
        unset($data['current_balance']);
        $supplier->update($data);

        return $supplier->fresh();
    }
}
