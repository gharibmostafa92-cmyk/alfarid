<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\AiPendingAction;
use App\Models\TreasuryTransfer;
use App\Services\BranchAccess;
use App\Services\TreasuryService;

/**
 * Row locks for finance write tools. Treasuries are always locked in primary
 * key order via TreasuryService::lockTreasuries.
 */
final class FinanceLocks
{
    public function lockTargets(AiPendingAction $action): void
    {
        $ids = array_filter([$action->args['from_treasury_id'] ?? null, $action->args['to_treasury_id'] ?? null, $action->args['treasury_id'] ?? null]);
        app(TreasuryService::class)->lockTreasuries(array_values(array_unique($ids)), $action->user_id);
    }

    public function lockBranches(AiPendingAction $action): void
    {
        $branchId = $action->args['branch_id'] ?? null;
        if ($branchId !== null) {
            app(BranchAccess::class)->lockActiveBranches([(int) $branchId]);
        }
    }

    /** @param list<int> $transferIds */
    public function lockTransfers(array $transferIds, ?int $userId): void
    {
        $ids = [];
        // Match the REST approval path: lock transfers before their treasuries.
        $transfers = TreasuryTransfer::query()->whereKey($transferIds)->orderBy('id')
            ->lockForUpdate()->get(['id', 'from_treasury_id', 'to_treasury_id']);
        foreach ($transfers as $transfer) {
            $ids[] = (int) $transfer->from_treasury_id;
            $ids[] = (int) $transfer->to_treasury_id;
        }
        app(TreasuryService::class)->lockTreasuries(array_values(array_unique($ids)), $userId);
    }
}
