<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inventory;

use App\Actions\Locations\SetLocationStatus;
use App\Enums\LocationType;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreLocationRequest;
use App\Http\Requests\Admin\Inventory\UpdateLocationRequest;
use App\Http\Resources\Admin\Inventory\LocationResource;
use App\Models\Branch;
use App\Models\Location;
use App\Models\StockLevel;
use App\Services\Inventory\BranchAccess;
use App\Services\Auth\StaffAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Branch storage locations management (aisles, shelves, staging areas) with balance checks.
 */
class LocationController extends Controller
{
    public function __construct(private readonly BranchAccess $branchAccess) {}

    /**
     * List locations belonging to a specific branch.
     */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        // Prevent cross-branch operational leakage and unauthorized location inspection.
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

    /**
     * Create a new location under the specified active branch.
     */
    public function store(StoreLocationRequest $request, Branch $branch): JsonResponse
    {
        $this->branchAccess->assertBranch($request->user()->id, $branch->id);
        // Creating a location inside an inactive branch is rejected.
        abort_unless((bool) $branch->fresh()->is_active, 422, 'لا يمكن إنشاء موقع داخل فرع متوقف.');
        try {
            $location = DB::transaction(function () use ($request, $branch): Location {
                $created = $branch->locations()->create($request->validated());
                $created->refresh();
                // Audit record is emitted within transaction to prevent unlogged mutations if rollback occurs.
                StaffAdministration::audit($request->user('staff'), 'location.created', 'location', $created->getKey(),
                    ['name', 'code', 'type'], [], ['name' => $created->name, 'code' => $created->code, 'type' => $created->type->value, 'branch' => $branch->name],
                    via: ['channel' => 'rest']);

                return $created;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'كود الموقع مستخدم بالفعل في هذا الفرع.'], 422);
        }

        return LocationResource::make($location)->response()->setStatusCode(201);
    }

    /**
     * Retrieve details for a specific branch location.
     */
    public function show(Request $request, Branch $branch, Location $location): LocationResource
    {
        $this->authorizeLocation((int) $request->user()->id, $branch, $location);

        return LocationResource::make($location);
    }

    /**
     * Update location details or toggle active status with balance checks.
     */
    public function update(UpdateLocationRequest $request, Branch $branch, Location $location, SetLocationStatus $setStatus): LocationResource
    {
        $this->authorizeLocation((int) $request->user()->id, $branch, $location);
        $validated = $request->validated();
        if (array_key_exists('is_active', $validated) && count($validated) === 1) {
            $updated = $setStatus($request->user('staff'), $location, (bool) $validated['is_active'], ['channel' => 'rest']);

            return LocationResource::make($updated);
        }
        // Pessimistic lock row to guard against concurrent type change or deactivation with non-zero balances.
        // Convert unique constraint race conditions to 422 instead of unhandled 500 error.
        try {
            $updated = DB::transaction(function () use ($request, $location, $validated): Location {
                $locked = Location::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
                $before = ['name' => $locked->name, 'code' => $locked->code, 'type' => $locked->type->value, 'is_active' => (bool) $locked->is_active];
                $after = $before;
                foreach (['name' => null, 'code' => null, 'is_active' => null] as $field => $_) {
                    if (array_key_exists($field, $validated)) {
                        $after[$field] = $field === 'is_active' ? (bool) $validated[$field] : $validated[$field];
                    }
                }
                if (isset($validated['type'])) {
                    $after['type'] = $validated['type'] instanceof LocationType ? $validated['type']->value : (string) $validated['type'];
                    if ($after['type'] !== $before['type']) {
                        $hasBalances = StockLevel::query()->where('location_id', $locked->getKey())->hasNonZeroBalance()->exists();
                        abort_unless(! $hasBalances, 422, 'لا يمكن تغيير نوع الموقع وهو عليه أرصدة غير صفرية؛ صفّرها أولًا.');
                    }
                }
                if (array_key_exists('is_active', $validated) && ! (bool) $validated['is_active']) {
                    $hasBalances = StockLevel::query()->where('location_id', $locked->getKey())->hasNonZeroBalance()->exists();
                    abort_unless(! $hasBalances, 422, 'لا يمكن تعطيل الموقع وهو عليه أرصدة غير صفرية.');
                }
                if (array_key_exists('is_active', $validated) && (bool) $validated['is_active']
                    && ! (bool) Branch::query()->find($locked->branch_id)?->is_active) {
                    abort(422, 'لا يمكن تفعيل موقع داخل فرع متوقف.');
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

    /**
     * Delete a location if it has no ledger history and no active stock balances.
     */
    public function destroy(Request $request, Branch $branch, Location $location): JsonResponse|Response
    {
        $this->authorizeLocation((int) $request->user()->id, $branch, $location);

        try {
            $deleted = DB::transaction(function () use ($request, $branch, $location): bool {
                // Consistent lock ordering (stock levels first, then location) prevents 40P01 deadlocks.
                StockLevel::query()->where('location_id', $location->getKey())
                    ->orderBy('branch_id')
                    ->orderByRaw('location_id ASC NULLS FIRST')
                    ->orderBy('product_variant_id')
                    ->lockForUpdate()
                    ->get();
                $lockedLocation = Location::query()
                    ->whereKey($location->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->ensureLocationBelongsToBranch($branch, $lockedLocation);

                // Foreign key constraint prevents deletion if ledger movements reference this location.
                if (DB::table('stock_movements')->where('location_id', $lockedLocation->getKey())->exists()) {
                    return false;
                }

                // Clean zero-balance placeholder rows created by lockLevels; non-zero balances reject deletion.
                $hasBalances = StockLevel::query()->where('location_id', $lockedLocation->getKey())
                    ->hasNonZeroBalance()
                    ->exists();
                if ($hasBalances) {
                    throw new BusinessException('لا يمكن حذف الموقع وفيه أرصدة (متاح/محجوز/تالف/في الطريق)؛ صفّرها أولًا.');
                }
                StockLevel::query()->where('location_id', $lockedLocation->getKey())->delete();

                // Audit location removal alongside staff actions.
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
