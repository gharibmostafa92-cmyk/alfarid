<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryType;
use App\Http\Controllers\Controller;
use App\Models\Treasury;
use App\Models\PosShift;
use App\Services\BranchAccess;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class TreasuryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:treasuries.view', only: ['index', 'show']),
            new Middleware('can:treasuries.create', only: ['store']),
            new Middleware('can:treasuries.update', only: ['update']),
            new Middleware('can:treasuries.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        $treasuries = Treasury::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
            ->with('branch')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('branch_id')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($treasuries);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50', 'unique:treasuries,code'],
            'branch_id' => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
            'type' => ['required', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertBranch($request, $data['branch_id'] ?? null);
        $treasury = Treasury::create($data + ['current_balance' => 0]);

        return response()->json([
            'message' => 'تم إنشاء الخزينة بنجاح.',
            'data' => $this->treasuryData($treasury),
        ], 201);
    }

    public function show(Request $request, Treasury $treasury): JsonResponse
    {
        app(BranchAccess::class)->assertTreasury($request->user()->id, $treasury);
        return response()->json(['data' => $this->treasuryData($treasury)]);
    }

    public function update(Request $request, Treasury $treasury): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('treasuries', 'code')->ignore($treasury->id)],
            'branch_id' => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
            'type' => ['sometimes', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        app(BranchAccess::class)->assertTreasury($request->user()->id, $treasury);
        if (array_key_exists('branch_id', $data)) $this->assertBranch($request, $data['branch_id']);
        DB::transaction(function () use ($treasury, $data): void {
            $locked = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
            if (isset($data['type']) && $data['type'] !== $locked->type->value) throw new BusinessException('نوع الخزينة غير قابل للتغيير.');
            $unsettled = PosShift::query()->where('treasury_id', $locked->id)->whereIn('status', ['open','closed_pending_approval'])->exists();
            if (($unsettled || (float) $locked->current_balance !== 0.0 || DB::table('treasury_transactions')->where('treasury_id', $locked->id)->exists()) && array_key_exists('branch_id', $data) && $data['branch_id'] != $locked->branch_id) throw new BusinessException('لا يمكن نقل خزينة لها رصيد أو سجل حركات إلى فرع آخر.');
            if ($unsettled && array_key_exists('is_active', $data) && ! $data['is_active']) throw new BusinessException('لا يمكن تعطيل درج له وردية لم تُسوَّ.');
            $locked->update($data);
        });
        $treasury->refresh();

        return response()->json([
            'message' => 'تم تحديث الخزينة بنجاح.',
            'data' => $this->treasuryData($treasury),
        ]);
    }

    public function destroy(Request $request, Treasury $treasury): JsonResponse
    {
        app(BranchAccess::class)->assertTreasury($request->user()->id, $treasury);
        DB::transaction(function () use ($treasury): void {
            $locked = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
            if ((float) $locked->current_balance !== 0.0 || DB::table('treasury_transactions')->where('treasury_id', $locked->id)->exists()
                || PosShift::query()->where('treasury_id', $locked->id)->exists()) {
                throw new BusinessException('لا يمكن حذف خزينة لها رصيد أو سجل حركات أو ورديات.');
            }
            $locked->delete();
        });

        return response()->json(['message' => 'تم حذف الخزينة.']);
    }

    private function assertBranch(Request $request, ?int $branchId): void
    {
        if ($branchId === null) app(BranchAccess::class)->assertCentralAccess($request->user());
        else app(BranchAccess::class)->assertBranch($request->user()->id, $branchId);
    }

    private function treasuryData(Treasury $treasury): Treasury
    {
        return $treasury->loadMissing('branch');
    }
}
