<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreLocationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLocationRequest;
use App\Http\Resources\Api\V1\Admin\LocationResource;
use App\Models\Branch;
use App\Models\Location;
use App\Models\StockLevel;
use App\Services\BranchAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        // M4: موظف الفرع A لا يعطّل مواقع الفرع B (كان يعلّق تحويلاتها مع H3).
        app(BranchAccess::class)->assertBranch($request->user()->id, $branch->id);
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $locations = $branch->locations()
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return LocationResource::collection($locations);
    }

    public function store(StoreLocationRequest $request, Branch $branch): JsonResponse
    {
        app(BranchAccess::class)->assertBranch($request->user()->id, $branch->id);
        $location = $branch->locations()->create($request->validated());

        return LocationResource::make($location)->response()->setStatusCode(201);
    }

    public function show(Request $request, Branch $branch, Location $location): LocationResource
    {
        app(BranchAccess::class)->assertBranch($request->user()->id, $branch->id);
        $this->ensureLocationBelongsToBranch($branch, $location);

        return LocationResource::make($location);
    }

    public function update(UpdateLocationRequest $request, Branch $branch, Location $location): LocationResource
    {
        app(BranchAccess::class)->assertBranch($request->user()->id, $branch->id);
        $this->ensureLocationBelongsToBranch($branch, $location);
        $location->update($request->validated());

        return LocationResource::make($location);
    }

    public function destroy(Request $request, Branch $branch, Location $location): JsonResponse|Response
    {
        app(BranchAccess::class)->assertBranch($request->user()->id, $branch->id);
        $this->ensureLocationBelongsToBranch($branch, $location);

        try {
            $deleted = DB::transaction(function () use ($branch, $location): bool {
                $lockedLocation = Location::query()
                    ->whereKey($location->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->ensureLocationBelongsToBranch($branch, $lockedLocation);

                // L3: ضمان الـ FK يكفي للدفتر — بلا مسح Schema في كل طلب.
                if (DB::table('stock_movements')->where('location_id', $lockedLocation->getKey())->exists()) {
                    return false;
                }

                // L3: صفوف الرصيد الصفرية (أثر جانبي لـ lockLevels) تُنظَّف بدل
                // منع الحذف الدائم؛ وغير الصفرية تُرفض برسالة مفهومة.
                $hasBalances = StockLevel::query()->where('location_id', $lockedLocation->getKey())
                    ->hasNonZeroBalance()
                    ->exists();
                if ($hasBalances) {
                    throw new BusinessException('لا يمكن حذف الموقع وفيه أرصدة (متاح/محجوز/تالف/في الطريق)؛ صفّرها أولًا.');
                }
                StockLevel::query()->where('location_id', $lockedLocation->getKey())->delete();

                $lockedLocation->delete();

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
            'message' => 'لا يمكن حذف الموقع لارتباطه بحركات مخزنية.',
        ], 409);
    }

    private function ensureLocationBelongsToBranch(Branch $branch, Location $location): void
    {
        abort_unless($location->branch_id === $branch->getKey(), 404);
    }
}
