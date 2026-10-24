<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchRequest;
use App\Http\Resources\Api\V1\Admin\BranchResource;
use App\Models\Branch;
use App\Models\StockLevel;
use App\Services\BranchAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $branches = Branch::query()
            ->withCount(['locations', 'users'])
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        // M4: الفروع كيانات مركزية — إنشاؤها ليس لموظف فرع مقيّد.
        app(BranchAccess::class)->assertCentralAccess($request->user());
        $branch = Branch::query()->create($request->validated());

        return $this->branchResource($branch)->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return $this->branchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());
        DB::transaction(function () use ($request, $branch): void {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            if ($request->validated('is_active') === false && (
                StockLevel::query()->where('branch_id', $locked->id)->where('in_transit', '>', 0)->exists()
                || \App\Models\StockTransfer::query()->where('to_branch_id', $locked->id)->where('status', 'shipped')->exists()
            )) {
                throw new BusinessException('لا يمكن تعطيل الفرع قبل استلام التحويلات المشحونة إليه.');
            }
            $locked->update($request->validated());
        });
        $branch->refresh();

        return $this->branchResource($branch);
    }

    private function branchResource(Branch $branch): BranchResource
    {
        return BranchResource::make($branch->loadCount(['locations', 'users']));
    }

    public function destroy(Request $request, Branch $branch): JsonResponse|Response
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());

        try {
            $deleted = DB::transaction(function () use ($branch): bool {
                $lockedBranch = Branch::query()
                    ->whereKey($branch->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedBranch->users()->exists()) {
                    return false;
                }

                // L3: ضمان الـ FK يكفي للدفتر — بلا مسح Schema في كل طلب.
                // كل حركات التطبيق تحمل branch_id، وحركات المواقع تُمسك
                // أيضًا بضمان FK عند حذف الموقع تباعًا.
                $hasMovements = DB::table('stock_movements')
                    ->where('branch_id', $lockedBranch->getKey())
                    ->exists();
                if ($hasMovements) {
                    return false;
                }

                // L3: الصفرية تُنظَّف (حذف الفرع يحذف مواقعه تباعًا)، وغير
                // الصفرية تُرفض برسالة مفهومة بدل 409 مبهم.
                $hasBalances = StockLevel::query()->where('branch_id', $lockedBranch->getKey())
                    ->hasNonZeroBalance()
                    ->exists();
                if ($hasBalances) {
                    throw new BusinessException('لا يمكن حذف الفرع وفيه أرصدة (متاح/محجوز/تالف/في الطريق)؛ صفّرها أولًا.');
                }
                StockLevel::query()->where('branch_id', $lockedBranch->getKey())->delete();

                $lockedBranch->delete();

                return true;
            });
        } catch (QueryException $exception) {
            if (! $this->isIntegrityConstraintViolation($exception)) {
                throw $exception;
            }

            return $this->conflictResponse();
        }

        if (! $deleted) {
            return $this->conflictResponse();
        }

        return response()->noContent();
    }

    private function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23503'], true);
    }

    private function conflictResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'لا يمكن حذف الفرع لارتباطه بموظفين أو حركات مخزنية.',
        ], 409);
    }
}
