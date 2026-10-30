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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

use App\Services\StaffAdministration;

class LocationController extends Controller
{
    public function __construct(private readonly BranchAccess $branchAccess) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        // M4: موظف الفرع A لا يعطّل مواقع الفرع B (كان يعلّق تحويلاتها مع H3).
        $this->branchAccess->assertBranch($request->user()->id, $branch->id);
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
        $this->branchAccess->assertBranch($request->user()->id, $branch->id);
        try {
            $location = $branch->locations()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'كود الموقع مستخدم بالفعل في هذا الفرع.'], 422);
        }
        // M5: تدقيق الإنشاء زي ما المساعد بيعمل.
        StaffAdministration::audit($request->user('staff'), 'location.created', 'location', $location->getKey(),
            ['name', 'code', 'type'], [], ['name' => $location->name, 'code' => $location->code, 'type' => $location->type->value, 'branch' => $branch->name],
            via: ['channel' => 'rest']);

        return LocationResource::make($location)->response()->setStatusCode(201);
    }

    public function show(Request $request, Branch $branch, Location $location): LocationResource
    {
        $this->authorizeLocation((int) $request->user()->id, $branch, $location);

        return LocationResource::make($location);
    }

    public function update(UpdateLocationRequest $request, Branch $branch, Location $location, \App\Actions\Locations\SetLocationStatus $setStatus): LocationResource
    {
        $this->authorizeLocation((int) $request->user()->id, $branch, $location);
        $validated = $request->validated();
        if (array_key_exists('is_active', $validated) && count($validated) === 1) {
            $updated = $setStatus($request->user('staff'), $location, (bool) $validated['is_active'], ['channel' => 'rest']);

            return LocationResource::make($updated);
        }
        // M5: قفل توافقي على الصف، ورفض تغيير النوع/التعطيل لموقع عليه
        // أرصدة — تغيير المعنى من غير تحذير أو سجل تدقيق.
        // M-1: سباق قيد فريد متزامن يُرجع 422 بدل 500.
        try {
            $updated = DB::transaction(function () use ($request, $branch, $location, $validated): Location {
            $locked = Location::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $before = ['name' => $locked->name, 'code' => $locked->code, 'type' => $locked->type->value, 'is_active' => (bool) $locked->is_active];
            $after = $before;
            foreach (['name' => null, 'code' => null, 'is_active' => null] as $field => $_) {
                if (array_key_exists($field, $validated)) {
                    $after[$field] = $field === 'is_active' ? (bool) $validated[$field] : $validated[$field];
                }
            }
            if (isset($validated['type'])) {
                $after['type'] = $validated['type'] instanceof \App\Enums\LocationType ? $validated['type']->value : (string) $validated['type'];
                if ($after['type'] !== $before['type']) {
                    $hasBalances = StockLevel::query()->where('location_id', $locked->getKey())->hasNonZeroBalance()->exists();
                    abort_unless(! $hasBalances, 422, 'لا يمكن تغيير نوع الموقع وهو عليه أرصدة غير صفرية؛ صفّرها أولًا.');
                }
            }
            if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active']) {
                $hasBalances = StockLevel::query()->where('location_id', $locked->getKey())->hasNonZeroBalance()->exists();
                abort_unless(! $hasBalances, 422, 'لا يمكن تعطيل الموقع وهو عليه أرصدة غير صفرية.');
            }
            $locked->update($validated);
            StaffAdministration::audit($request->user('staff'), 'location.updated', 'location', $locked->getKey(),
                array_keys($validated), $before, $after, via: ['channel' => 'rest']);

            return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(422, 'كود الموقع مستخدم بالفعل في هذا الفرع.');
        }

        return LocationResource::make($updated);
    }

    public function destroy(Request $request, Branch $branch, Location $location): JsonResponse|Response
    {
        $this->authorizeLocation((int) $request->user()->id, $branch, $location);

        try {
            $deleted = DB::transaction(function () use ($request, $branch, $location): bool {
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

                // M5: تدقيق حذف الموقع زي بقية عمليات الإدارة.
                StaffAdministration::audit($request->user('staff'), 'location.deleted', 'location', $lockedLocation->getKey(),
                    ['name', 'code', 'type'],
                    ['name' => $lockedLocation->name, 'code' => $lockedLocation->code, 'type' => $lockedLocation->type->value], [],
                    via: ['channel' => 'rest']);

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

    private function authorizeLocation(int $userId, Branch $branch, Location $location): void
    {
        $this->branchAccess->assertBranch($userId, $branch->id);
        $this->ensureLocationBelongsToBranch($branch, $location);
    }
}
