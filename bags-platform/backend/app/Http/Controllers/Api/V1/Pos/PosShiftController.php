<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosShiftController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * فتح وردية جديدة.
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
     * عرض الوردية المفتوحة للكاشير الحالي.
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
     * إغلاق الوردية.
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
