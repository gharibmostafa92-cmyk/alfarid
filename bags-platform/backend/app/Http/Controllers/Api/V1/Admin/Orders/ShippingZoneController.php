<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shipping\ShippingZoneRequest;
use App\Http\Resources\Admin\ShippingZoneResource;
use App\Models\ShippingZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Geographical shipping zones and city coverage mapping administration.
 */
class ShippingZoneController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:shipping.view', only: ['index', 'show']),
            new Middleware('can:shipping.create', only: ['store']),
            new Middleware('can:shipping.update', only: ['update']),
            new Middleware('can:shipping.delete', only: ['destroy']),
        ];
    }

    /**
     * List paginated shipping zones with covered cities.
     */
    public function index(): AnonymousResourceCollection
    {
        return ShippingZoneResource::collection(
            ShippingZone::query()->with('cities')->latest('id')->paginate(20)
        );
    }

    /**
     * Create a new shipping zone and sync covered city associations.
     */
    public function store(ShippingZoneRequest $request): JsonResponse
    {
        $data = $request->validated();
        $zone = DB::transaction(function () use ($data): ShippingZone {
            $zone = ShippingZone::create($data);
            $zone->cities()->sync($this->cityIds($data));

            return $zone;
        });

        return $this->zoneResource($zone)->response()->setStatusCode(201);
    }

    /**
     * Retrieve details of a shipping zone and its assigned cities.
     */
    public function show(ShippingZone $shippingZone): ShippingZoneResource
    {
        return $this->zoneResource($shippingZone);
    }

    /**
     * Update shipping zone parameters and sync city assignments.
     */
    public function update(ShippingZoneRequest $request, ShippingZone $shippingZone): ShippingZoneResource
    {
        $data = $request->validated();
        DB::transaction(function () use ($shippingZone, $data): void {
            $shippingZone->update($data);
            if (array_key_exists('city_ids', $data)) {
                $shippingZone->cities()->sync($this->cityIds($data));
            }
        });

        return $this->zoneResource($shippingZone);
    }

    /**
     * Delete a shipping zone.
     */
    public function destroy(ShippingZone $shippingZone): JsonResponse
    {
        $shippingZone->delete();

        return response()->json(['message' => 'تم حذف منطقة الشحن']);
    }

    /**
     * Repeated city ids must not hit the composite primary key twice.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function cityIds(array $data): array
    {
        return array_values(array_unique(array_map(intval(...), $data['city_ids'] ?? [])));
    }

    /**
     * Wrap shipping zone model with covered cities in an API resource.
     *
     * @param  ShippingZone  $zone  Target shipping zone
     * @return ShippingZoneResource Formatted resource
     */
    private function zoneResource(ShippingZone $zone): ShippingZoneResource
    {
        return ShippingZoneResource::make($zone->loadMissing('cities'));
    }
}
