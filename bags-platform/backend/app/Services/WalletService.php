<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Manages customer wallet balance and immutable ledger transactions.
 *
 * All balance adjustments are computed using integer cents to prevent floating-point
 * rounding errors, guarded by pessimistic locking and idempotency uniqueness checks.
 */
class WalletService
{
    /**
     * Record a debit or credit transaction on a customer's wallet ledger.
     *
     * @param  int  $customerId
     * @param  float  $amount  Positive for credit/top-up, negative for debit/payment
     * @param  string  $type  Transaction type (e.g. checkout_payment, refund, adjustment)
     * @param  string  $referenceType  Morph class or table name of the triggering source
     * @param  int  $referenceId  ID of the triggering source record
     * @param  int|null  $createdBy  User ID if triggered by an administrator
     * @return CustomerWalletTransaction
     *
     * @throws BusinessException
     */
    public function record(int $customerId, float $amount, string $type, string $referenceType, int $referenceId, ?int $createdBy = null): CustomerWalletTransaction
    {
        return DB::transaction(function () use ($customerId, $amount, $type, $referenceType, $referenceId, $createdBy): CustomerWalletTransaction {
            // Lock customer row to serialize concurrent debits/credits on the same balance
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            if ($type === 'checkout_payment' && $customer->status !== CustomerStatus::Approved) {
                throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
            }
            // Enforce idempotency: prevent duplicate ledger entries for the same reference event
            if (CustomerWalletTransaction::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)->where('type', $type)->exists()) {
                throw new BusinessException('حركة المحفظة مسجلة سابقًا.', [], 409);
            }
            // Calculate balances in integer cents to eliminate floating-point drift
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
