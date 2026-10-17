<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotion\CouponRequest;
use App\Http\Resources\Admin\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** إدارة كوبونات الخصم. */
class CouponController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:coupons.view', only: ['index', 'show']),
            new Middleware('can:coupons.create', only: ['store']),
            new Middleware('can:coupons.update', only: ['update']),
            new Middleware('can:coupons.delete', only: ['destroy']),
        ];
    }

    public function index(): AnonymousResourceCollection
    {
        return CouponResource::collection(Coupon::query()->latest('id')->paginate(20));
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['value'] ??= 0;
        $data['usage_limit_per_customer'] ??= 1;
        $coupon = Coupon::create($data);

        return CouponResource::make($coupon)->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        return CouponResource::make($coupon);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon->update($request->validated());

        return CouponResource::make($coupon);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return response()->json(['message' => 'تم حذف الكوبون']);
    }
}
