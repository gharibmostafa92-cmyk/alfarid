<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\User;
use App\Models\WalletAdjustment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Manual wallet adjustments (disbursement / refund / correction).
 *
 * The ledger itself stays append-only: reversals are new rows pointing at
 * the original via reverses_id, never updates or deletes.
 */
final class WalletAdjustmentService
{
    public const KINDS = ['disbursement', 'refund', 'adjustment'];

    public const DIRECTIONS = ['credit', 'debit'];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly OperationIdempotencyService $idempotency,
    ) {}

    /**
     * @param  array{customer_id: int, kind: string, amount: mixed, direction?: string, reason: string, source?: string, ai_action_id?: string|null}  $data
     */
    public function adjust(array $data, User $actor, ?string $idempotencyKey): WalletAdjustment
    {
        $kind = (string) ($data['kind'] ?? 'adjustment');
        if (! in_array($kind, self::KINDS, true)) {
            throw new BusinessException('نوع التعديل غير معروف.');
        }
        $amount = BigDecimal::of((string) ($data['amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
        if ($amount->isLessThanOrEqualTo('0')) {
            throw new BusinessException('مبلغ التعديل لازم يكون أكبر من صفر.');
        }
        $direction = $kind === 'disbursement' ? 'debit' : ($kind === 'refund' ? 'credit' : (string) ($data['direction'] ?? ''));
        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new BusinessException('حدد اتجاه التعديل (credit/debit).');
        }
        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 500);
        if ($reason === '') {
            throw new BusinessException('سبب التعديل إجباري.');
        }
        $source = (string) ($data['source'] ?? 'admin');
        if (! in_array($source, ['admin', 'ai'], true)) {
            throw new BusinessException('مصدر التعديل غير معروف.');
        }
        $signed = $direction === 'credit' ? $amount : $amount->negated();
        $walletType = $kind === 'refund' ? 'refund' : 'adjustment';
        $payload = ['customer_id' => (int) $data['customer_id'], 'kind' => $kind,
            'amount' => (string) $amount, 'direction' => $direction, 'reason' => $reason, 'source' => $source];

        return $this->idempotency->run('wallet_adjustment', $actor, $idempotencyKey, $payload,
            function () use ($data, $actor, $amount, $direction, $reason, $source, $signed, $walletType): WalletAdjustment {
                $customer = Customer::query()->lockForUpdate()->findOrFail((int) $data['customer_id']);
                $adjustment = WalletAdjustment::create([
                    'customer_id' => $customer->id,
                    'amount' => (string) $amount,
                    'direction' => $direction,
                    'reason' => $reason,
                    'source' => $source,
                    'created_by' => $actor->id,
                    'ai_action_id' => $data['ai_action_id'] ?? null,
                    'created_at' => now(),
                ]);
                $this->wallet->record($customer->id, (float) (string) $signed, $walletType,
                    'wallet_adjustment', $adjustment->id, $actor->id);

                return $adjustment;
            });
    }

    /**
     * Reverses an adjustment with a counter-entry (the original row is kept).
     *
     * Only the creator or a super-admin may reverse.
     */
    public function reverse(WalletAdjustment $adjustment, User $actor, ?string $idempotencyKey, ?string $reason = null): WalletAdjustment
    {
        if ($adjustment->reversals()->exists()) {
            throw new BusinessException('هذا التعديل معكوس بالفعل.');
        }
        $isCreator = (int) $adjustment->created_by === (int) $actor->id;
        $isSuperAdmin = method_exists($actor, 'hasRole') && $actor->hasRole(StaffRole::SuperAdmin->value, 'staff');
        if (! $isCreator && ! $isSuperAdmin) {
            throw new BusinessException('فقط منشئ التعديل أو مدير عام يمكنه عكسه.', [], 403);
        }
        $opposite = $adjustment->direction === 'credit' ? 'debit' : 'credit';

        $reversal = $this->adjust([
            'customer_id' => $adjustment->customer_id,
            'kind' => 'adjustment',
            'amount' => (string) $adjustment->amount,
            'direction' => $opposite,
            'reason' => mb_substr('عكس تعديل #'.$adjustment->id.($reason !== null && trim($reason) !== '' ? ': '.trim($reason) : ': '.$adjustment->reason), 0, 500),
            'source' => $adjustment->source,
        ] + ($adjustment->ai_action_id !== null ? ['ai_action_id' => $adjustment->ai_action_id] : []), $actor, $idempotencyKey);
        if ($reversal->reverses_id === null) {
            $reversal->update(['reverses_id' => $adjustment->id]);
        }

        return $reversal->fresh() ?? $reversal;
    }
}
