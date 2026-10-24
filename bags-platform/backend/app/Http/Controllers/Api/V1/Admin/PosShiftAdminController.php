<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Models\User;
use App\Services\PosService;
use App\Services\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * متحكم مراجعة واعتماد الورديات من لوحة الإدارة.
 */
class PosShiftAdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:pos_shifts.view',    only: ['index', 'show']),
            new Middleware('can:pos_shifts.approve', only: ['approve']),
        ];
    }

    public function __construct(private readonly PosService $pos) {}

    /**
     * GET /admin/shifts — قائمة الورديات للمراجعة الإدارية.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'user_id'   => ['nullable', 'integer', 'min:1'],
            'status'    => ['nullable', Rule::in(array_column(PosShiftStatus::cases(), 'value'))],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date'   => ['nullable', 'date_format:Y-m-d'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        $shifts = PosShift::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
            ->with(['cashier', 'treasury', 'branch', 'approver'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('opened_at', '>=', $v))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('opened_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($shifts);
    }

    /**
     * GET /admin/shifts/{id}
     */
    public function show(Request $request, PosShift $shift): JsonResponse
    {
        app(BranchAccess::class)->assertBranch($request->user()->id, $shift->branch_id);
        return response()->json([
            'data' => $shift->load(['cashier', 'treasury', 'branch', 'approver', 'expenses']),
        ]);
    }

    /**
     * POST /admin/shifts/{id}/approve — اعتماد الوردية وترحيل النقدية للخزنة.
     */
    public function approve(Request $request, PosShift $shift): JsonResponse
    {
        /** @var User $approver */
        $approver = $request->user('staff');

        $shift = $this->pos->approveShift($shift, $approver);

        return response()->json([
            'message' => 'تم اعتماد الوردية وترحيل النقدية للخزنة الرئيسية.',
            'data'    => $shift->load(['cashier', 'treasury', 'branch', 'approver']),
        ]);
    }
}
