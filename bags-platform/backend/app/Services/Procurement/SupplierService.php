<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Models\SupplierUser;
use Illuminate\Support\Facades\DB;

/**
 * Manages supplier profiles and opening balances.
 *
 * The current balance initializes from the opening balance upon creation.
 * Subsequent balance movements are strictly managed through SupplierFinanceService
 * via posted invoices and recorded payments.
 */
class SupplierService
{
    /**
     * Create a new supplier record and initialize current balance to opening balance.
     *
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
     * Update supplier details, allowing opening balance adjustment only prior to recorded financial transactions.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessException
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        // Current balance is an accounting ledger outcome and cannot be edited manually
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
            if (array_key_exists('is_active', $data) && $data['is_active'] === false && $supplier->wasChanged('is_active')) {
                // Lock portal users so a concurrent login cannot mint a token after
                // the deactivation revokes the old sessions.
                $userIds = $supplier->users()->lockForUpdate()->pluck('id');
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', (new SupplierUser)->getMorphClass())
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();
            }

            return $supplier->fresh();
        });
    }
}
