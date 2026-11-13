<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Customer\CustomerStatus;
use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Shared\OperationIdempotencyService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

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
        return $this->recordAdjustment($data, $actor, $idempotencyKey);
    }

    private function recordAdjustment(array $data, User $actor, ?string $idempotencyKey, ?int $reversesId = null): WalletAdjustment
    {
        $kind = (string) ($data['kind'] ?? 'adjustment');
        if (! in_array($kind, self::KINDS, true)) {
            throw new BusinessException('نوع التعديل غير معروف.');
        }
        try {
            $amount = BigDecimal::of((string) ($data['amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new BusinessException('مبلغ التعديل غير صالح.');
        }
        if ($amount->isLessThanOrEqualTo('0')) {
            throw new BusinessException('مبلغ التعديل لازم يكون أكبر من صفر.');
        }
        if ($amount->isGreaterThan('9999999999.99')) {
            throw new BusinessException('مبلغ التعديل يتجاوز الحد المسموح.');
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

        if ($reversesId !== null) {
            $payload['reverses_id'] = $reversesId;
        }

        return $this->idempotency->run('wallet_adjustment', $actor, $idempotencyKey, $payload,
            fn (): WalletAdjustment => DB::transaction(function () use ($data, $actor, $amount, $direction, $reason, $source, $signed, $walletType, $reversesId): WalletAdjustment {
                if ($reversesId !== null) {
                    $original = WalletAdjustment::query()->lockForUpdate()->findOrFail($reversesId);
                    if (! $this->canReverse($original, $actor)) {
                        throw new BusinessException('فقط منشئ التعديل أو مدير عام يمكنه عكسه.', [], 403);
                    }
                    if ($original->reversals()->exists()) {
                        throw new BusinessException('هذا التعديل معكوس بالفعل.', [], 409);
                    }
                }
                $customer = Customer::query()->lockForUpdate()->findOrFail((int) $data['customer_id']);
                if ($reversesId === null && in_array($customer->status, [CustomerStatus::Rejected, CustomerStatus::Suspended], true)) {
                    throw new BusinessException('لا يمكن تعديل محفظة عميل مرفوض أو محظور.');
                }
                $adjustment = WalletAdjustment::create([
                    'customer_id' => $customer->id,
                    'amount' => (string) $amount,
                    'direction' => $direction,
                    'reason' => $reason,
                    'source' => $source,
                    'created_by' => $actor->id,
                    'ai_action_id' => $data['ai_action_id'] ?? null,
                    'reverses_id' => $reversesId,
                    'created_at' => now(),
                ]);
                $this->wallet->record($customer->id, (string) $signed, $walletType,
                    'wallet_adjustment', $adjustment->id, $actor->id);

                return $adjustment;
            }));
    }

    /**
     * Reverses an adjustment with a counter-entry (the original row is kept).
     *
     * Only the creator or a super-admin may reverse.
     */
    public function reverse(WalletAdjustment $adjustment, User $actor, ?string $idempotencyKey, ?string $reason = null): WalletAdjustment
    {
        $original = WalletAdjustment::query()->findOrFail($adjustment->id);
        if (! $this->canReverse($original, $actor)) {
            throw new BusinessException('فقط منشئ التعديل أو مدير عام يمكنه عكسه.', [], 403);
        }
        if ($original->reversals()->exists()) {
            throw new BusinessException('هذا التعديل معكوس بالفعل.', [], 409);
        }
        $opposite = $original->direction === 'credit' ? 'debit' : 'credit';

        return $this->recordAdjustment([
            'customer_id' => $original->customer_id,
            'kind' => 'adjustment',
            'amount' => (string) $original->amount,
            'direction' => $opposite,
            'reason' => mb_substr('عكس تعديل #'.$original->id.($reason !== null && trim($reason) !== '' ? ': '.trim($reason) : ': '.$original->reason), 0, 500),
            'source' => $original->source,
        ], $actor, $idempotencyKey ?? 'wallet-adj-rev-'.$original->id, $original->id);
    }

    private function canReverse(WalletAdjustment $original, User $actor): bool
    {
        $isCreator = (int) $original->created_by === (int) $actor->id;
        $isSuperAdmin = method_exists($actor, 'hasRole') && $actor->hasRole(StaffRole::SuperAdmin->value, 'staff');

        return $isCreator || $isSuperAdmin;
    }
}
