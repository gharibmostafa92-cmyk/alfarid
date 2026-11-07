<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotion\CouponRequest;
use App\Http\Resources\Admin\CouponResource;
use App\Models\Cart;
use App\Models\Coupon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotional discount coupon administration: listing, creation, updates, and safe deletion.
 */
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

    /**
     * Paginate promotional discount coupons.
     */
    public function index(): AnonymousResourceCollection
    {
        return CouponResource::collection(Coupon::query()->latest('id')->paginate(20));
    }

    /**
     * Create a new promotional discount coupon.
     */
    public function store(CouponRequest $request): JsonResponse
    {
        $data = $this->withoutNulls($request->validated());
        $data['value'] ??= 0;
        $data['usage_limit_per_customer'] ??= 1;
        try {
            $coupon = Coupon::create($data);
        } catch (UniqueConstraintViolationException) {
            // Concurrent creations with identical code pass validation; convert DB unique collision to 422.
            throw ValidationException::withMessages(['code' => 'رمز الكوبون مستخدم بالفعل.']);
        }

        return CouponResource::make($coupon)->response()->setStatusCode(201);
    }

    /**
     * Retrieve details of a specific coupon.
     */
    public function show(Coupon $coupon): CouponResource
    {
        return CouponResource::make($coupon);
    }

    /**
     * Update attributes of an existing coupon.
     */
    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon->update($this->withoutNulls($request->validated()));

        return CouponResource::make($coupon);
    }

    /**
     * Delete an unused coupon under exclusive row lock.
     *
     * @throws BusinessException If coupon usage records already exist.
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        // Coupon usage history constitutes financial records; used coupons must be deactivated rather than deleted.
        try {
            DB::transaction(function () use ($coupon): void {
                $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
                if ($locked->usages()->exists()) {
                    throw new BusinessException('لا يمكن حذف كوبون له استخدامات مسجلة؛ عطّله بدلًا من حذفه.');
                }
                // Detach deleted coupon from pending carts to prevent blocking customer checkout.
                Cart::query()->where('coupon_id', $locked->id)->update(['coupon_id' => null]);
                $locked->delete();
            });
        } catch (QueryException $e) {
            // Racing usage record trips restrict FK constraint; convert to business exception.
            if (($e->errorInfo[0] ?? $e->getCode()) === '23503') {
                throw new BusinessException('لا يمكن حذف كوبون له استخدامات مسجلة؛ عطّله بدلًا من حذفه.');
            }

            throw $e;
        }

        return response()->json(['message' => 'تم حذف الكوبون']);
    }

    /**
     * Strip explicit nulls on non-nullable fields to prevent database constraint violations.
     *
     * @param  array<string, mixed>  $data  Request payload.
     * @return array<string, mixed> Filtered attributes.
     */
    private function withoutNulls(array $data): array
    {
        foreach (['value', 'usage_limit_per_customer', 'is_active'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
