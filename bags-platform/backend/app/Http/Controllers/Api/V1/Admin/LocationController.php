<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreLocationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLocationRequest;
use App\Http\Resources\Api\V1\Admin\LocationResource;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocationController extends Controller
{
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
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
        $location = $branch->locations()->create($request->validated());

        return LocationResource::make($location)->response()->setStatusCode(201);
    }

    public function show(Branch $branch, Location $location): LocationResource
    {
        $this->ensureLocationBelongsToBranch($branch, $location);

        return LocationResource::make($location);
    }

    public function update(UpdateLocationRequest $request, Branch $branch, Location $location): LocationResource
    {
        $this->ensureLocationBelongsToBranch($branch, $location);
        $location->update($request->validated());

        return LocationResource::make($location->refresh());
    }

    public function destroy(Branch $branch, Location $location): JsonResponse|Response
    {
        $this->ensureLocationBelongsToBranch($branch, $location);

        try {
            $deleted = DB::transaction(function () use ($branch, $location): bool {
                $lockedLocation = Location::query()
                    ->whereKey($location->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->ensureLocationBelongsToBranch($branch, $lockedLocation);

                if ($this->hasInventoryMovements($lockedLocation)) {
                    return false;
                }

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

    private function hasInventoryMovements(Location $location): bool
    {
        foreach (['inventory_movements', 'stock_movements', 'inventory_transactions', 'stock_transactions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'location_id')) {
                continue;
            }

            if (DB::table($table)->where('location_id', $location->getKey())->exists()) {
                return true;
            }
        }

        return false;
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
