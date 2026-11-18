<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale;

use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use Throwable;

/**
 * Shared branch & central-access checks for AI wholesale tools, matching
 * WholesalePaymentNoticeController and QuotationController authorization boundaries.
 */
final class WholesaleAccessGuard
{
    public static function denyIfNoNoticeAccess(
        BranchAccess $branches,
        User $actor,
        WholesalePaymentNotice $notice,
        ?int $treasuryId = null,
    ): ?ToolOutcome {
        $notice->loadMissing('order', 'treasury');

        try {
            $orderBranchId = $notice->order?->allocated_branch_id;
            if ($orderBranchId === null) {
                $branches->assertCentralAccess($actor);
            } else {
                $branches->assertBranch($actor->id, (int) $orderBranchId);
            }

            if ($treasuryId !== null) {
                $treasury = Treasury::query()->find($treasuryId);
                if ($treasury === null) {
                    return ToolOutcome::denied('الخزينة مش موجودة.', auditable: false);
                }
                $branches->assertTreasury($actor->id, $treasury);
            } elseif ($notice->treasury !== null) {
                $branches->assertTreasury($actor->id, $notice->treasury);
            }
        } catch (Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }

        return null;
    }

    public static function denyIfNoCentralAccess(BranchAccess $branches, User $actor): ?ToolOutcome
    {
        try {
            $branches->assertCentralAccess($actor);
        } catch (Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }

        return null;
    }

    public static function noticeFingerprint(WholesalePaymentNotice $notice): string
    {
        return hash('sha256', implode('|', [
            $notice->status->value,
            number_format((float) $notice->amount, 2, '.', ''),
            (string) $notice->reference_number,
            (string) $notice->match_score,
            $notice->updated_at?->toIso8601String() ?? '',
        ]));
    }
}
