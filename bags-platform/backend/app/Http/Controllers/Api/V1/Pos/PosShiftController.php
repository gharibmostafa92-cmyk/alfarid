<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\PosService;
use App\Services\Shared\IdempotencyFingerprint;
use App\Services\Shared\OperationIdempotencyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'data' => $this->pos->getAvailableDrawers($branchId, $user),
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

        $data = array_merge($this->formatShift($shift), [
            'opening_cash'          => number_format((float) $totals['opening_cash'], 2, '.', ''),
            'cash_sales_total'      => number_format((float) $totals['cash_sales_total'], 2, '.', ''),
            'card_sales_total'      => number_format((float) $totals['card_sales_total'], 2, '.', ''),
            'cash_returns_total'    => number_format((float) $totals['cash_returns_total'], 2, '.', ''),
            'cash_expenses_total'   => number_format((float) $totals['cash_expenses_total'], 2, '.', ''),
            'expected_closing_cash' => number_format((float) $totals['expected_closing_cash'], 2, '.', ''),
            'invoices_count'        => (int) $totals['invoices_count'],
        ]);

        // Blind close: the cashier must count physical cash without seeing the
        // live expected ledger. Supervisors with the explicit permission still do.
        if (! $request->user('staff')?->can('pos_shifts.view_expected')) {
            unset(
                $data['cash_sales_total'],
                $data['cash_returns_total'],
                $data['cash_expenses_total'],
                $data['expected_closing_cash'],
            );
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Open a new cashier shift with initial drawer cash float.
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'treasury_id'  => ['required', 'integer', 'min:1'],
            'opening_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');
        if ($user->branch_id === null) {
            throw new AuthorizationException('حسابك غير مرتبط بأي فرع.');
        }

        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        // F-10: an Idempotency-Key makes retried opens return the already
        // opened shift; requests without a key keep the legacy behavior.
        $open = fn (): PosShift => $this->pos->openShift(
            user: $user,
            branchId: $branchId,
            treasuryId: (int) $data['treasury_id'],
            openingCash: (float) $data['opening_cash'],
        );
        $idempotencyKey = $this->idempotencyKey($request);
        /** @var PosShift $shift */
        $shift = $idempotencyKey !== null
            ? app(OperationIdempotencyService::class)->run(
                'pos_shift_open',
                $user,
                $idempotencyKey,
                ['branch_id' => $branchId, 'treasury_id' => (int) $data['treasury_id'], 'opening_cash' => $data['opening_cash']],
                $open,
            )
            : $open();

        return response()->json([
            'message' => 'تم فتح الوردية بنجاح.',
            'data'    => $this->formatShiftFor($user, $shift->load(['cashier', 'treasury', 'branch'])),
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
            'data'           => $this->formatShiftFor($user, $shift),
            'has_open_shift' => true,
        ]);
    }

    /**
     * Close the cashier shift with counted cash for administrative reconciliation.
     *
     * F-10: this endpoint intentionally lives outside the pos.shift middleware
     * so a retried close after an unacked commit can replay the original close
     * summary via Idempotency-Key instead of dying with 403 no_open_shift.
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'actual_closing_cash' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'notes'               => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        $replay = $this->replayClose($request, $user, $data);
        if ($replay !== null) {
            return $replay;
        }

        /** @var PosShift|null $shift */
        $shift = $request->attributes->get('pos_shift') ?? $this->resolveOpenShift($user);

        if ($shift === null) {
            return response()->json([
                'message' => 'لا توجد وردية مفتوحة. افتح وردية أولًا لإتمام العملية.',
                'code' => 'no_open_shift',
            ], 403);
        }

        $close = fn (): PosShift => $this->pos->closeShift(
            shift: $shift,
            actualCash: (float) $data['actual_closing_cash'],
            notes: $data['notes'] ?? null,
        );
        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey !== null) {
            /** @var PosShift $shift */
            $shift = app(OperationIdempotencyService::class)->run(
                'pos_shift_close',
                $user,
                $idempotencyKey,
                $this->closePayload($shift->id, $data),
                $close,
            );
        } else {
            $shift = $close();
        }

        return response()->json([
            'message' => 'تم إغلاق الوردية وهي بانتظار اعتماد المدير.',
            'data'    => $this->formatShiftFor($user, $shift),
        ]);
    }

    /**
     * F-10: open-shift resolution mirroring EnsureOpenPosShift (same messages
     * and codes) for the close endpoint, which runs before that middleware.
     */
    private function resolveOpenShift(User $user): ?PosShift
    {
        if ($user->branch_id === null) {
            throw new AuthorizationException('لا يمكن استخدام نقطة البيع بدون ارتباط بفرع.');
        }

        $shifts = PosShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', PosShiftStatus::Open)
            ->orderBy('id')
            ->get();

        if ($shifts->count() > 1) {
            throw new BusinessException('يوجد أكثر من وردية مفتوحة. أغلق الورديات الزائدة قبل إتمام العملية.', [], 409);
        }

        return $shifts->first();
    }

    /**
     * F-10: replay a committed close when the shift is already closed and the
     * client retries with the same Idempotency-Key and payload.
     */
    private function replayClose(Request $request, User $user, array $data): ?JsonResponse
    {
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return null;
        }

        $record = DB::table('operation_idempotency_keys')
            ->where('operation', 'pos_shift_close')
            ->where('actor_type', $user->getMorphClass())
            ->where('actor_id', $user->getKey())
            ->where('key', $key)
            ->first();
        if ($record === null) {
            return null;
        }

        $shift = $record->result_id ? PosShift::query()->where('user_id', $user->id)->find($record->result_id) : null;
        if ($shift instanceof PosShift) {
            $payload = $this->closePayload($shift->id, $data);
            $legacyPayload = [...$payload, 'actual_closing_cash' => $data['actual_closing_cash']];
            if (hash_equals((string) $record->request_hash, IdempotencyFingerprint::hash($payload))
                || hash_equals((string) $record->request_hash, IdempotencyFingerprint::hash($legacyPayload))) {
                return response()->json([
                    'message' => 'تم إغلاق الوردية وهي بانتظار اعتماد المدير.',
                    'data'    => $this->formatShiftFor($user, $shift),
                ]);
            }
            throw new BusinessException('مفتاح عدم التكرار مستخدم مع بيانات مختلفة.', [], 409);
        }

        return null;
    }

    /**
     * F-10: canonical close payload — built identically on first attempt and
     * on replay so the fingerprint matches.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function closePayload(int $shiftId, array $data): array
    {
        return [
            'shift_id' => $shiftId,
            'actual_closing_cash' => (float) $data['actual_closing_cash'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    // ===================================================================
    //  Helper
    // ===================================================================

    /**
     * Blind-close projection: hide the live expected ledger and variance from
     * cashiers, including in the close response, unless they hold the explicit
     * pos_shifts.view_expected permission.
     */
    private function formatShiftFor(User $user, PosShift $shift): array
    {
        $data = $this->formatShift($shift);

        if (! $user->can('pos_shifts.view_expected')) {
            unset(
                $data['expected_closing_cash'],
                $data['cash_sales_total'],
                $data['cash_returns_total'],
                $data['cash_expenses_total'],
                $data['cash_difference'],
            );
        }

        return $data;
    }

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
            'cashier'               => ($shift->relationLoaded('cashier') && $shift->cashier !== null) ? [
                'id'   => $shift->cashier->id,
                'name' => $shift->cashier->name,
            ] : null,
            'treasury'              => ($shift->relationLoaded('treasury') && $shift->treasury !== null) ? [
                'id'   => $shift->treasury->id,
                'name' => $shift->treasury->name,
                'code' => $shift->treasury->code,
            ] : null,
            'branch'                => ($shift->relationLoaded('branch') && $shift->branch !== null) ? [
                'id'   => $shift->branch->id,
                'name' => $shift->branch->name,
            ] : null,
        ];
    }
}
