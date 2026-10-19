<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
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
        return DB::transaction(function () use ($supplier, $data): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            unset($data['current_balance']);
            if (array_key_exists('opening_balance', $data)
                && (float) $data['opening_balance'] !== (float) $supplier->opening_balance) {
                if ($supplier->invoices()->exists() || $supplier->payments()->exists()) {
                    throw new BusinessException('لا يمكن تعديل الرصيد الافتتاحي بعد تسجيل فواتير أو دفعات.');
                }
                $data['current_balance'] = round((float) $supplier->current_balance
                    + (float) $data['opening_balance'] - (float) $supplier->opening_balance, 2);
            }
            $supplier->update($data);

            return $supplier->fresh();
        });
    }
}
