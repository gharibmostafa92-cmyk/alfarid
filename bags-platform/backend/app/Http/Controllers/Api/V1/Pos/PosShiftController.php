<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\PosService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cashier POS terminal shifts: opening with drawer cash float, active shift monitoring, and reconciliation closing.
 */
class PosShiftController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * List active POS drawers for the authenticated cashier's branch with busy status and balance.
     */
    public function availableDrawers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        if ($user->branch_id === null) {
            throw new AuthorizationException('حسابك غير مرتبط بأي فرع.');
        }

        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        return response()->json([
            'data' => $this->pos->getAvailableDrawers($branchId),
        ]);
    }

    /**
     * Retrieve live computed financial totals and invoice count for the current open shift.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        $shift->load(['cashier', 'treasury', 'branch']);

        $totals = $this->pos->computeShiftTotals($shift);

        return response()->json([
            'data' => array_merge($this->formatShift($shift), [
                'opening_cash'          => number_format((float) $totals['opening_cash'], 2, '.', ''),
                'cash_sales_total'      => number_format((float) $totals['cash_sales_total'], 2, '.', ''),
                'card_sales_total'      => number_format((float) $totals['card_sales_total'], 2, '.', ''),
                'cash_returns_total'    => number_format((float) $totals['cash_returns_total'], 2, '.', ''),
                'cash_expenses_total'   => number_format((float) $totals['cash_expenses_total'], 2, '.', ''),
                'expected_closing_cash' => number_format((float) $totals['expected_closing_cash'], 2, '.', ''),
                'invoices_count'        => (int) $totals['invoices_count'],
            ]),
        ]);
    }

    /**
     * Open a new cashier shift with initial drawer cash float.
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'treasury_id'  => ['required', 'integer', 'min:1'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        $shift = $this->pos->openShift(
            user: $user,
            branchId: (int) $user->branch_id,
            treasuryId: (int) $data['treasury_id'],
            openingCash: (float) $data['opening_cash'],
        );

        return response()->json([
            'message' => 'تم فتح الوردية بنجاح.',
            'data'    => $this->formatShift($shift->load(['cashier', 'treasury', 'branch'])),
        ], 201);
    }

    /**
     * Retrieve current open shift details for the authenticated cashier.
     */
    public function current(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $shift = PosShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', PosShiftStatus::Open)
            ->with(['cashier', 'treasury', 'branch'])
            ->latest('id')
            ->first();

        if ($shift === null) {
            return response()->json(['data' => null, 'has_open_shift' => false]);
        }

        return response()->json([
            'data'           => $this->formatShift($shift),
            'has_open_shift' => true,
        ]);
    }

    /**
     * Close the cashier shift with counted cash for administrative reconciliation.
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'actual_closing_cash' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'notes'               => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $shift = $this->pos->closeShift(
            shift: $shift,
            actualCash: (float) $data['actual_closing_cash'],
            notes: $data['notes'] ?? null,
        );

        return response()->json([
            'message' => 'تم إغلاق الوردية وهي بانتظار اعتماد المدير.',
            'data'    => $this->formatShift($shift),
        ]);
    }

    // ===================================================================
    //  Helper
    // ===================================================================

    private function formatShift(PosShift $shift): array
    {
        return [
            'id'                    => $shift->id,
            'shift_number'          => $shift->shift_number,
            'status'                => $shift->status->value,
            'opened_at'             => $shift->opened_at,
            'closed_at'             => $shift->closed_at,
            'approved_at'           => $shift->approved_at,
            'opening_cash'          => $shift->opening_cash,
            'cash_sales_total'      => $shift->cash_sales_total,
            'card_sales_total'      => $shift->card_sales_total,
            'cash_returns_total'    => $shift->cash_returns_total,
            'cash_expenses_total'   => $shift->cash_expenses_total,
            'expected_closing_cash' => $shift->expected_closing_cash,
            'actual_closing_cash'   => $shift->actual_closing_cash,
            'cash_difference'       => $shift->cash_difference,
            'notes'                 => $shift->notes,
            'cashier'               => $shift->relationLoaded('cashier') ? [
                'id'   => $shift->cashier->id,
                'name' => $shift->cashier->name,
            ] : null,
            'treasury'              => $shift->relationLoaded('treasury') ? [
                'id'   => $shift->treasury->id,
                'name' => $shift->treasury->name,
                'code' => $shift->treasury->code,
            ] : null,
            'branch'                => $shift->relationLoaded('branch') ? [
                'id'   => $shift->branch->id,
                'name' => $shift->branch->name,
            ] : null,
        ];
    }
}
