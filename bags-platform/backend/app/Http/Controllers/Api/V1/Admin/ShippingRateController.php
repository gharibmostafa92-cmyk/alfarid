<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shipping\ShippingRateRequest;
use App\Http\Resources\Admin\ShippingRateResource;
use App\Models\ShippingRate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

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
        $rate = $this->persist(fn () => ShippingRate::create($request->validated()));

        return $this->rateResource($rate)->response()->setStatusCode(201);
    }

    public function show(ShippingRate $shippingRate): ShippingRateResource
    {
        return $this->rateResource($shippingRate);
    }

    public function update(ShippingRateRequest $request, ShippingRate $shippingRate): ShippingRateResource
    {
        $this->persist(function () use ($request, $shippingRate): ShippingRate {
            $shippingRate->update($request->validated());

            return $shippingRate;
        });

        return $this->rateResource($shippingRate);
    }

    public function destroy(ShippingRate $shippingRate): JsonResponse
    {
        $shippingRate->delete();

        return response()->json(['message' => 'تم حذف سعر الشحن']);
    }

    /**
     * The unique (company, zone) index is the race backstop. Surface it as 422
     * instead of an unhandled constraint error.
     *
     * @param  callable(): ShippingRate  $write
     */
    private function persist(callable $write): ShippingRate
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'shipping_zone_id' => 'يوجد سعر شحن بالفعل لهذه الشركة والمنطقة.',
            ]);
        }
    }

    private function rateResource(ShippingRate $rate): ShippingRateResource
    {
        return ShippingRateResource::make($rate->loadMissing(['company', 'zone']));
    }
}
