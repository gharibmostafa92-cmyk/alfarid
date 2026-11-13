<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Inventory\BranchAccess;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shared stop/delete guards for treasury write tools.
 * Mirrors TreasuryController::update/destroy so REST and AI stay identical.
 */
final class FinanceWriteGuards
{
    public function __construct(private BranchAccess $branches) {}

    /**
     * Resolves the branch for a treasury mutation, enforcing scope.
     *
     * @return int|null Branch id, or null for a central treasury
     *
     * @throws AuthorizationException If the branch is out of the actor's scope
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the branch is missing
     */
    public function resolveBranch(User $actor, ?int $branchId): ?int
    {
        if ($branchId === null) {
            $this->branches->assertCentralAccess($actor);

            return null;
        }
        $restricted = $this->branches->restrictedBranch($actor);
        if ($restricted !== null && $restricted !== $branchId) {
            throw new AuthorizationException('الفرع خارج نطاقك.');
        }
        $this->branches->assertBranch($actor->id, $branchId);

        return $branchId;
    }

    /**
     * Guards treasury creation against inactive, missing, or mismatched branches.
     *
     * @return string|null Denial message, or null when creation is allowed
     */
    public function creationBlocker(User $actor, ?int $branchId, string $type): ?string
    {
        try {
            $this->resolveBranch($actor, $branchId);
        } catch (AuthorizationException $e) {
            return $e->getMessage();
        }
        if ($branchId === null) {
            if ($type === 'pos_drawer') {
                return 'درج نقطة البيع لازم يكون تابع لفرع.';
            }

            return null;
        }
        $branch = \App\Models\Branch::find($branchId);
        if ($branch === null) {
            return 'الفرع مش موجود.';
        }
        if (! $branch->is_active) {
            return match ($type) {
                'main_safe' => 'الخزينة الرئيسية لازم تتبع فرع مفعّل.',
                'bank_account' => 'الحساب البنكي لازم يتبع فرع مفعّل.',
                'pos_drawer' => 'درج نقطة البيع لازم يتبع فرع مفعّل.',
                default => 'الخزينة لازم تتبع فرع مفعّل.',
            };
        }

        return null;
    }

    /** @return list<string> Reasons stopping/deactivating is blocked (empty = allowed) */
    public function deactivationBlockers(Treasury $treasury, bool $includePendingOut = true): array
    {
        $blockers = [];
        if (! self::decimalBalance($treasury->current_balance)->isZero()) {
            $blockers[] = 'الرصيد مش صفر';
        }
        $unsettled = PosShift::query()->where('treasury_id', $treasury->id)
            ->whereIn('status', ['open', 'closed_pending_approval'])->exists();
        if ($unsettled) {
            $blockers[] = 'في ورديات غير مسوّاة';
        }
        if ($includePendingOut && TreasuryTransfer::query()
            ->where(fn ($q) => $q->where('from_treasury_id', $treasury->id)->orWhere('to_treasury_id', $treasury->id))
            ->where('status', \App\Enums\Finance\TreasuryTransferStatus::Pending)
            ->exists()) {
            $blockers[] = 'في تحويلات معلقة';
        }

        return $blockers;
    }

    /**
     * Deactivation intentionally ignores ledger history: a retired treasury may
     * keep its audit trail as long as no money or unsettled work remains.
     * Branch moves still need a pristine account.
     *
     * @return list<string> Reasons moving a treasury to another branch is blocked
     */
    public function branchMoveBlockers(Treasury $treasury): array
    {
        $blockers = [];
        if (! self::decimalBalance($treasury->current_balance)->isZero()) {
            $blockers[] = 'الرصيد مش صفر';
        }
        if (PosShift::query()->where('treasury_id', $treasury->id)
            ->whereIn('status', ['open', 'closed_pending_approval'])->exists()) {
            $blockers[] = 'في ورديات غير مسوّاة';
        }
        if (TreasuryTransaction::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'ليها سجل حركات';
        }
        if (TreasuryTransfer::query()
            ->where(fn ($q) => $q->where('from_treasury_id', $treasury->id)->orWhere('to_treasury_id', $treasury->id))
            ->exists()) {
            $blockers[] = 'ليها تحويلات';
        }

        return $blockers;
    }

    /** @return list<string> Reasons deleting a treasury is blocked (empty = allowed) */
    public function deletionBlockers(Treasury $treasury): array
    {
        $blockers = [];
        $unsettled = PosShift::query()->where('treasury_id', $treasury->id)
            ->whereIn('status', ['open', 'closed_pending_approval'])->exists();
        if ($unsettled) {
            $blockers[] = 'في ورديات غير مسوّاة';
        }
        if (! self::decimalBalance($treasury->current_balance)->isZero()) {
            $blockers[] = 'الرصيد مش صفر';
        }
        if (TreasuryTransaction::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'ليها سجل حركات';
        }
        if (PosShift::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'مرتبطة بورديات';
        }
        if (Expense::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'مرتبطة بمصروفات';
        }
        if (WholesalePaymentNotice::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'مرتبطة بإشعارات دفع';
        }
        if (TreasuryTransfer::query()
            ->where(fn ($q) => $q->where('from_treasury_id', $treasury->id)->orWhere('to_treasury_id', $treasury->id))
            ->exists()) {
            $blockers[] = 'مرتبطة بتحويلات خزينة';
        }

        return $blockers;
    }

    public function accountVersions(Treasury $treasury): array
    {
        return [
            'treasury:'.$treasury->id => self::ledgerVersion($treasury),
            'treasury_metadata:'.$treasury->id => hash('sha256', json_encode([
                $treasury->name, $treasury->code, $treasury->branch_id, $treasury->is_active, (string) $treasury->updated_at,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Smart top-up suggestion (Phase 5.5): finds a same-branch treasury able
     * to cover a shortfall, with a ready one-click prompt for the model.
     *
     * @return array{treasury_id:int,name:string,balance:float,shortfall:float,prompt:string}|null
     */
    public function suggestTopUp(?int $branchId, int $excludeTreasuryId, float|string $shortfall, string $targetName): ?array
    {
        try {
            $shortfallDec = BigDecimal::of((string) $shortfall)->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            return null;
        }
        if (! $shortfallDec->isPositive()) {
            return null;
        }

        $candidates = Treasury::query()->where('is_active', true)
            ->whereKeyNot($excludeTreasuryId)
            ->where('branch_id', $branchId)
            ->whereIn('type', [\App\Enums\Finance\TreasuryType::MainSafe, \App\Enums\Finance\TreasuryType::BankAccount])
            ->where('current_balance', '>=', (string) $shortfallDec)
            ->orderByDesc('current_balance')
            ->get(['id', 'name', 'current_balance']);

        $donor = null;
        $effectiveBalance = null;
        $pendingOutByTreasury = TreasuryTransfer::query()
            ->whereIn('from_treasury_id', $candidates->modelKeys())
            ->where('status', \App\Enums\Finance\TreasuryTransferStatus::Pending)
            ->selectRaw('from_treasury_id, SUM(amount) AS reserved')
            ->groupBy('from_treasury_id')
            ->pluck('reserved', 'from_treasury_id');

        foreach ($candidates as $cand) {
            $pendingOut = (string) ($pendingOutByTreasury[$cand->id] ?? '0');

            $candBal = self::decimalBalance($cand->current_balance)->minus(BigDecimal::of($pendingOut)->toScale(2, RoundingMode::HalfUp));
            if ($candBal->compareTo($shortfallDec) >= 0) {
                $donor = $cand;
                $effectiveBalance = $candBal;
                break;
            }
        }

        if ($donor === null || $effectiveBalance === null) {
            return null;
        }

        $shortfallStr = (string) $shortfallDec;
        $balanceStr = (string) $effectiveBalance;

        $cleanDonor = mb_substr(trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s\-_#]/u', ' ', strip_tags((string) $donor->name)))), 0, 50);
        $cleanTarget = mb_substr(trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s\-_#]/u', ' ', strip_tags($targetName)))), 0, 50);
        if ($cleanTarget === '') {
            $cleanTarget = 'الخزينة المستهدفة';
        }

        return [
            'treasury_id' => $donor->id,
            'name' => (string) $donor->name,
            'balance' => (float) $balanceStr,
            'shortfall' => (float) $shortfallStr,
            'prompt' => "حوّل {$shortfallStr} من {$cleanDonor} إلى {$cleanTarget}",
        ];
    }

    public static function ledgerVersion(Treasury $treasury): string
    {
        return ($treasury->transactions()->max('id') ?? 0).':'.(string) self::decimalBalance($treasury->current_balance);
    }

    private static function decimalBalance(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HalfUp);
    }

    public static function expenseVersion(Expense $expense): string
    {
        return $expense->updated_at->timestamp.':'.(int) ($expense->voided_at !== null);
    }

    public static function transferVersion(TreasuryTransfer $transfer): string
    {
        return hash('sha256', json_encode([
            $transfer->id,
            $transfer->status->value,
            (string) $transfer->amount,
            $transfer->from_treasury_id,
            $transfer->to_treasury_id,
            $transfer->updated_at?->toIso8601ZuluString() ?? '',
        ], JSON_THROW_ON_ERROR));
    }
}
