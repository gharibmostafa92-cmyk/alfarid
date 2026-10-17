<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shipping\ShippingRateRequest;
use App\Http\Resources\Admin\ShippingRateResource;
use App\Models\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** إدارة أسعار الشحن (شركة × منطقة). */
class ShippingRateController extends Controller implements HasMiddleware
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
        return ShippingRateResource::collection(
            ShippingRate::query()->with(['company', 'zone'])->latest('id')->paginate(20)
        );
    }

    public function store(ShippingRateRequest $request): JsonResponse
    {
        $rate = ShippingRate::create($request->validated());

        return ShippingRateResource::make($rate->load(['company', 'zone']))->response()->setStatusCode(201);
    }

    public function show(ShippingRate $shippingRate): ShippingRateResource
    {
        return ShippingRateResource::make($shippingRate->load(['company', 'zone']));
    }

    public function update(ShippingRateRequest $request, ShippingRate $shippingRate): ShippingRateResource
    {
        $shippingRate->update($request->validated());

        return ShippingRateResource::make($shippingRate->load(['company', 'zone']));
    }

    public function destroy(ShippingRate $shippingRate): JsonResponse
    {
        $shippingRate->delete();

        return response()->json(['message' => 'تم حذف سعر الشحن']);
    }
}
