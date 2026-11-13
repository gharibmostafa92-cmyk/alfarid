<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\ReturnRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Manages customer wallet balance and immutable ledger transactions.
 *
 * All balance adjustments are computed using integer cents to prevent floating-point
 * rounding errors, guarded by pessimistic locking and idempotency uniqueness checks.
 */
class WalletService
{
    public const ORDER_REFERENCES = ['order', 'orders', Order::class];

    private const RETURN_REFERENCES = ['return_request', 'return_requests', ReturnRequest::class];

    public const ALLOWED_TYPES = [
        'opening',
        'topup',
        'checkout_payment',
        'refund',
        'return_refund',
        'adjustment',
        'order_reversal',
    ];

    private const TEST_TYPES = ['test_topup', 'test_debit', 'test_credit', 'test_zero'];

    private const CREDIT_OR_ZERO_TYPES = [
        'opening',
        'topup',
        'refund',
        'return_refund',
        'order_reversal',
    ];

    /**
     * Record a debit or credit transaction on a customer's wallet ledger.
     *
     * @param  float|string  $amount  Positive for credit/top-up, negative for debit/payment
     * @param  string  $type  Transaction type (e.g. checkout_payment, refund, adjustment)
     * @param  string  $referenceType  Morph class or table name of the triggering source
     * @param  int  $referenceId  ID of the triggering source record
     * @param  int|null  $createdBy  User ID if triggered by an administrator
     *
     * @throws BusinessException
     */
    public function record(int $customerId, float|string $amount, string $type, string $referenceType, int $referenceId, ?int $createdBy = null): CustomerWalletTransaction
    {
        $referenceTypes = match (true) {
            in_array($referenceType, self::ORDER_REFERENCES, true) => self::ORDER_REFERENCES,
            in_array($referenceType, self::RETURN_REFERENCES, true) => self::RETURN_REFERENCES,
            default => [$referenceType],
        };

        // Accept strings from BigDecimal callers and never round-trip through float:
        // float($signed) already lost precision before this method was reached.
        $signed = BigDecimal::of(is_string($amount) ? $amount : (string) $amount)
            ->toScale(2, RoundingMode::HalfUp);
        $this->assertAmountSign($type, $signed);

        return DB::transaction(function () use ($customerId, $signed, $type, $referenceTypes, $referenceId, $createdBy): CustomerWalletTransaction {
            // F9: order-scoped refunds share one (reference, type) key per order, so a second
            // partial refund would either collide (false 500) or double-spend. Route refunds
            // through wallet_adjustment documents, which are unique per adjustment.
            if ($type === 'refund' && $referenceTypes === self::ORDER_REFERENCES) {
                throw new BusinessException('استرداد المحفظة يتم عبر مستند تسوية فقط.', [], 422);
            }
            // Lock customer row to serialize concurrent debits/credits on the same balance
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            if ($type === 'checkout_payment' && $customer->status !== CustomerStatus::Approved) {
                throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
            }
            // Idempotency is scoped to the customer: reference ids are only unique per table.
            $existing = CustomerWalletTransaction::query()
                ->where('customer_id', $customerId)
                ->whereIn('reference_type', $referenceTypes)
                ->where('reference_id', $referenceId)
                ->where('type', $type)
                ->first();
            if ($existing !== null) {
                // A replay of the identical event returns the original row. Legacy rows
                // stored under the FQCN alias are still treated as a conflict so an
                // alias retry cannot silently hide a different reference identity.
                $sameReference = (string) $existing->reference_type === $referenceTypes[0];
                if ($sameReference && BigDecimal::of((string) $existing->amount)->isEqualTo($signed)) {
                    return $existing;
                }
                throw new BusinessException('حركة المحفظة مسجلة سابقًا.', [], 409);
            }

            $balance = BigDecimal::of((string) $customer->wallet_balance)->plus($signed)->toScale(2);
            if ($balance->isNegative()) {
                throw new BusinessException('رصيد المحفظة لا يكفي قيمة الطلب كاملة.');
            }
            if ($balance->isGreaterThan('9999999999.99')) {
                throw new BusinessException('رصيد المحفظة يتجاوز الحد المسموح.');
            }
            $entry = CustomerWalletTransaction::create([
                'customer_id' => $customerId, 'type' => $type, 'amount' => (string) $signed,
                'balance_after' => (string) $balance, 'reference_type' => $referenceTypes[0],
                'reference_id' => $referenceId, 'created_by' => $createdBy,
            ]);
            $customer->forceFill(['wallet_balance' => (string) $balance])->save();

            return $entry;
        });
    }

    private function assertAmountSign(string $type, BigDecimal $amount): void
    {
        $isAllowed = in_array($type, self::ALLOWED_TYPES, true)
            || (app()->environment('testing') && in_array($type, self::TEST_TYPES, true));
        if (! $isAllowed) {
            throw new BusinessException('نوع حركة المحفظة غير مدعوم.');
        }

        // Zero movements remain permitted by explicit product decision (task2 F17 follow-up).
        if ($type === 'checkout_payment' && $amount->isPositive()) {
            throw new BusinessException('دفع المحفظة لا يمكن أن يكون مبلغًا موجبًا.');
        }
        if (in_array($type, self::CREDIT_OR_ZERO_TYPES, true) && $amount->isNegative()) {
            throw new BusinessException('نوع حركة المحفظة لا يقبل مبلغًا سالبًا.');
        }
    }
}
