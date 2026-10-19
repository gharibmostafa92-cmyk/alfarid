<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function record(int $customerId, float $amount, string $type, string $referenceType, int $referenceId, ?int $createdBy = null): CustomerWalletTransaction
    {
        return DB::transaction(function () use ($customerId, $amount, $type, $referenceType, $referenceId, $createdBy): CustomerWalletTransaction {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            if ($type === 'checkout_payment' && $customer->status !== CustomerStatus::Approved) {
                throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
            }
            if (CustomerWalletTransaction::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)->where('type', $type)->exists()) {
                throw new BusinessException('حركة المحفظة مسجلة سابقًا.', [], 409);
            }
            $amountCents = (int) round($amount * 100);
            $balanceCents = (int) round((float) $customer->wallet_balance * 100) + $amountCents;
            if ($balanceCents < 0) {
                throw new BusinessException('رصيد المحفظة لا يكفي قيمة الطلب كاملة.');
            }
            if ($balanceCents > 999999999999) {
                throw new BusinessException('رصيد المحفظة يتجاوز الحد المسموح.');
            }
            $entry = CustomerWalletTransaction::create([
                'customer_id' => $customerId, 'type' => $type, 'amount' => $amountCents / 100,
                'balance_after' => $balanceCents / 100, 'reference_type' => $referenceType,
                'reference_id' => $referenceId, 'created_by' => $createdBy,
            ]);
            $customer->forceFill(['wallet_balance' => $balanceCents / 100])->save();

            return $entry;
        });
    }
}
