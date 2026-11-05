<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\Expense;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\BranchAccess;
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
     * Guards treasury creation against inactive or mismatched branches.
     *
     * @return string|null Denial message, or null when creation is allowed
     */
    public function creationBlocker(User $actor, ?int $branchId, string $type): ?string
    {
        if ($branchId === null) {
            return null;
        }
        try {
            $this->resolveBranch($actor, $branchId);
        } catch (AuthorizationException $e) {
            return $e->getMessage();
        }
        $branch = \App\Models\Branch::find($branchId);
        if ($branch === null) {
            return 'الفرع مش موجود.';
        }
        if ($type === 'main_safe' && ! $branch->is_active) {
            return 'الخزينة الرئيسية لازم تتبع فرع مفعّل.';
        }
        if ($type === 'bank_account' && ! $branch->is_active) {
            return 'الحساب البنكي لازم يتبع فرع مفعّل.';
        }

        return null;
    }

    /** @return list<string> Reasons stopping/deleting is blocked (empty = allowed) */
    public function deactivationBlockers(Treasury $treasury, bool $includePendingOut = true): array
    {
        $blockers = [];
        if ((float) $treasury->current_balance !== 0.0) {
            $blockers[] = 'الرصيد مش صفر';
        }
        $unsettled = PosShift::query()->where('treasury_id', $treasury->id)
            ->whereIn('status', ['open', 'closed_pending_approval'])->exists();
        if ($unsettled) {
            $blockers[] = 'في ورديات غير مسوّاة';
        }
        if (TreasuryTransaction::query()->where('treasury_id', $treasury->id)->exists()) {
            $blockers[] = 'ليها سجل حركات';
        }
        if ($includePendingOut && TreasuryTransfer::query()
            ->where('from_treasury_id', $treasury->id)
            ->where('status', \App\Enums\TreasuryTransferStatus::Pending)->exists()) {
            $blockers[] = 'في تحويلات صادرة معلقة';
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
    public function suggestTopUp(?int $branchId, int $excludeTreasuryId, float $shortfall, string $targetName): ?array
    {
        if ($shortfall <= 0) {
            return null;
        }
        $donor = Treasury::query()->where('is_active', true)
            ->whereKeyNot($excludeTreasuryId)
            ->where('branch_id', $branchId)
            ->where('current_balance', '>=', $shortfall)
            ->orderByDesc('current_balance')->first(['id', 'name', 'current_balance']);
        if ($donor === null) {
            return null;
        }
        $amount = round($shortfall, 2);

        return [
            'treasury_id' => $donor->id,
            'name' => (string) $donor->name,
            'balance' => (float) $donor->current_balance,
            'shortfall' => $amount,
            'prompt' => "حوّل {$amount} من {$donor->name} إلى {$targetName}",
        ];
    }

    public static function ledgerVersion(Treasury $treasury): string
    {
        return ($treasury->transactions()->max('id') ?? 0).':'.$treasury->current_balance;
    }

    public static function expenseVersion(Expense $expense): string
    {
        return $expense->updated_at->timestamp.':'.(int) ($expense->voided_at !== null);
    }

    public static function transferVersion(TreasuryTransfer $transfer): string
    {
        return $transfer->status->value.':'.$transfer->updated_at->timestamp;
    }
}
