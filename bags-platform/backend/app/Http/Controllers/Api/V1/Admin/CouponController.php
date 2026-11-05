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
        $data = $this->withoutNulls($request->validated());
        $data['value'] ??= 0;
        $data['usage_limit_per_customer'] ??= 1;
        try {
            $coupon = Coupon::create($data);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent creates with the same code both pass validation;
            // the loser must get 422, not 500.
            throw ValidationException::withMessages(['code' => 'رمز الكوبون مستخدم بالفعل.']);
        }

        return CouponResource::make($coupon)->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        return CouponResource::make($coupon);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon->update($this->withoutNulls($request->validated()));

        return CouponResource::make($coupon);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        // سجل الاستخدام تاريخ مالي: كوبون مُستخدَم لا يُحذف، يُعطَّل (M9).
        // The existence check runs inside the transaction under an exclusive
        // row lock: a usage committed between check and delete must refuse the
        // delete (409/422) instead of cascade-deleting the financial record,
        // and a delete committed first makes the racing recordUsage fail safe.
        try {
            DB::transaction(function () use ($coupon): void {
                $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
                if ($locked->usages()->exists()) {
                    throw new BusinessException('لا يمكن حذف كوبون له استخدامات مسجلة؛ عطّله بدلًا من حذفه.');
                }
                // فكّ ارتباط السلال المعلقة حتى لا يتعطل إتمام شرائها (M9)
                Cart::query()->where('coupon_id', $locked->id)->update(['coupon_id' => null]);
                $locked->delete();
            });
        } catch (QueryException $e) {
            // A usage committed after our check on another connection trips the
            // restrict FK: refuse with 422 instead of leaking a 500.
            if (($e->errorInfo[0] ?? $e->getCode()) === '23503') {
                throw new BusinessException('لا يمكن حذف كوبون له استخدامات مسجلة؛ عطّله بدلًا من حذفه.');
            }

            throw $e;
        }

        return response()->json(['message' => 'تم حذف الكوبون']);
    }

    /**
     * ‏null صريح لحقول NOT NULL يُحذف من الحمولة بدل ما يكسر الحفظ بـ 500 (M8).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
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
