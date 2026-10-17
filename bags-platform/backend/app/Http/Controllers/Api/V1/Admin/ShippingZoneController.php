<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shipping\ShippingZoneRequest;
use App\Http\Resources\Admin\ShippingZoneResource;
use App\Models\ShippingZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** إدارة مناطق الشحن ومدنها. */
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

    public function index(): AnonymousResourceCollection
    {
        return ShippingZoneResource::collection(
            ShippingZone::query()->with('cities')->latest('id')->paginate(20)
        );
    }

    public function store(ShippingZoneRequest $request): JsonResponse
    {
        $data = $request->validated();
        $zone = ShippingZone::create($data);
        $zone->cities()->sync($data['city_ids'] ?? []);

        return ShippingZoneResource::make($zone->load('cities'))->response()->setStatusCode(201);
    }

    public function show(ShippingZone $shippingZone): ShippingZoneResource
    {
        return ShippingZoneResource::make($shippingZone->load('cities'));
    }

    public function update(ShippingZoneRequest $request, ShippingZone $shippingZone): ShippingZoneResource
    {
        $data = $request->validated();
        $shippingZone->update($data);
        if (array_key_exists('city_ids', $data)) {
            $shippingZone->cities()->sync($data['city_ids'] ?? []);
        }

        return ShippingZoneResource::make($shippingZone->load('cities'));
    }

    public function destroy(ShippingZone $shippingZone): JsonResponse
    {
        $shippingZone->delete();

        return response()->json(['message' => 'تم حذف منطقة الشحن']);
    }
}
