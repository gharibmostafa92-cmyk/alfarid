<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Customer\CustomerStatus;
use App\Enums\StaffRole;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\Shared\OperationIdempotencyService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
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
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw new BusinessException('مفتاح عدم التكرار مطلوب لتعديل المحفظة.');
        }

        return $this->recordAdjustment($data, $actor, $idempotencyKey);
    }

    private function recordAdjustment(array $data, User $actor, ?string $idempotencyKey, ?int $reversesId = null): WalletAdjustment
    {
        $input = $this->validateAdjustmentInput($data);
        $payload = [
            'customer_id' => (int) $data['customer_id'],
            'kind' => $input['kind'],
            'amount' => (string) $input['amount'],
            'direction' => $input['direction'],
            'reason' => $input['reason'],
            'source' => $input['source'],
        ];
        if ($reversesId !== null) {
            $payload['reverses_id'] = $reversesId;
        }

        $execute = fn (): WalletAdjustment => DB::transaction(function () use ($data, $actor, $input, $reversesId): WalletAdjustment {
            $walletType = $reversesId !== null
                ? $this->resolveReversalWalletType($reversesId, (int) $data['customer_id'], $input['amount'], $input['direction'], $input['wallet_type'], $actor)
                : $input['wallet_type'];

            $customer = Customer::query()->lockForUpdate()->findOrFail((int) $data['customer_id']);
            if ($reversesId === null && in_array($customer->status, [CustomerStatus::Rejected, CustomerStatus::Suspended], true)) {
                throw new BusinessException('لا يمكن تعديل محفظة عميل مرفوض أو محظور.');
            }

            $adjustment = WalletAdjustment::create([
                'customer_id' => $customer->id,
                'amount' => (string) $input['amount'],
                'direction' => $input['direction'],
                'reason' => $input['reason'],
                'source' => $input['source'],
                'created_by' => $actor->id,
                'ai_action_id' => $data['ai_action_id'] ?? null,
                'reverses_id' => $reversesId,
                'created_at' => now(),
            ]);

            $this->wallet->record($customer->id, (string) $input['signed'], $walletType, 'wallet_adjustment', $adjustment->id, $actor->id);

            return $adjustment;
        });

        return $this->runWithSqlErrorMapping(
            fn (): WalletAdjustment => $idempotencyKey !== null
                ? $this->idempotency->run('wallet_adjustment', $actor, $idempotencyKey, $payload, $execute)
                : $execute(),
        );
    }

    /**
     * @return array{kind: string, amount: BigDecimal, direction: string, reason: string, source: string, signed: BigDecimal, wallet_type: string}
     */
    private function validateAdjustmentInput(array $data): array
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

        $direction = match ($kind) {
            'disbursement' => 'debit',
            'refund' => 'credit',
            default => (string) ($data['direction'] ?? ''),
        };
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

        return [
            'kind' => $kind,
            'amount' => $amount,
            'direction' => $direction,
            'reason' => $reason,
            'source' => $source,
            'signed' => $direction === 'credit' ? $amount : $amount->negated(),
            'wallet_type' => $kind === 'refund' ? 'refund' : 'adjustment',
        ];
    }

    private function resolveReversalWalletType(
        int $reversesId,
        int $customerId,
        BigDecimal $amount,
        string $direction,
        string $defaultWalletType,
        User $actor,
    ): string {
        $original = WalletAdjustment::query()->lockForUpdate()->findOrFail($reversesId);
        if (! $this->canReverse($original, $actor)) {
            throw new BusinessException('فقط منشئ التعديل أو مدير عام يمكنه عكسه.', [], 403);
        }
        if ($original->reversals()->exists()) {
            throw new BusinessException('هذا التعديل معكوس بالفعل.', [], 409);
        }
        if ($customerId !== (int) $original->customer_id
            || ! $amount->isEqualTo((string) $original->amount)
            || $direction === $original->direction) {
            throw new BusinessException('بيانات عكس التعديل لا تطابق التعديل الأصلي.', [], 422);
        }

        $isOriginalRefund = $direction === 'debit' && CustomerWalletTransaction::query()
            ->where('customer_id', $original->customer_id)
            ->where('reference_type', 'wallet_adjustment')
            ->where('reference_id', $original->id)
            ->where('type', 'refund')
            ->exists();

        return $isOriginalRefund ? 'refund_reversal' : $defaultWalletType;
    }

    private function runWithSqlErrorMapping(\Closure $callback): WalletAdjustment
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
            if ($sqlState === '23505') {
                throw new BusinessException('هذا التعديل معكوس بالفعل.', [], 409);
            }
            if ($sqlState === '23514') {
                throw new BusinessException('بيانات التعديل لا تحقق قواعد المحفظة.', [], 422);
            }
            throw $exception;
        }
    }

    /**
     * Reverses an adjustment with a counter-entry (the original row is kept).
     *
     * Only the creator or a super-admin may reverse.
     */
    public function reverse(WalletAdjustment $adjustment, User $actor, ?string $idempotencyKey, ?string $reason = null): WalletAdjustment
    {
        $reasonText = trim((string) $reason);

        $execute = fn (): WalletAdjustment => DB::transaction(function () use ($adjustment, $actor, $reasonText): WalletAdjustment {
            $original = WalletAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            $opposite = $original->direction === 'credit' ? 'debit' : 'credit';

            return $this->recordAdjustment([
                'customer_id' => $original->customer_id,
                'kind' => 'adjustment',
                'amount' => (string) $original->amount,
                'direction' => $opposite,
                'reason' => mb_substr('عكس تعديل #'.$original->id.($reasonText !== '' ? ': '.$reasonText : ': '.$original->reason), 0, 500),
                'source' => $original->source,
            ], $actor, null, $original->id);
        });

        if ($idempotencyKey === null) {
            return $execute();
        }

        return $this->idempotency->run('wallet_adjustment_reverse', $actor, $idempotencyKey, [
            'adjustment_id' => $adjustment->id,
            'reason' => $reasonText,
        ], $execute);
    }

    private function canReverse(WalletAdjustment $original, User $actor): bool
    {
        $isCreator = (int) $original->created_by === (int) $actor->id;
        $isSuperAdmin = method_exists($actor, 'hasRole') && $actor->hasRole(StaffRole::SuperAdmin->value, 'staff');

        return $isCreator || $isSuperAdmin;
    }
}
