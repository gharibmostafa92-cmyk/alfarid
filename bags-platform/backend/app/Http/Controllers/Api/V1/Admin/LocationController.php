<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreLocationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLocationRequest;
use App\Http\Resources\Api\V1\Admin\LocationResource;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        $location->delete();

        return response()->noContent();
    }

    private function ensureLocationBelongsToBranch(Branch $branch, Location $location): void
    {
        abort_unless($location->branch_id === $branch->getKey(), 404);
    }
}
