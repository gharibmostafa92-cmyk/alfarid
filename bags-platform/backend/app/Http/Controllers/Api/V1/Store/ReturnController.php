<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Return\StoreReturnRequest;
use App\Http\Resources\Store\ReturnRequestResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;

/**
 * مرتجعات العميل المسجل: تقديم طلب إرجاع لطلب مُسلَّم يخصه (مع صور عيوب).
 * بقية الدورة (اعتماد/استلام/رد) إدارية بالكامل في Admin\ReturnController.
 */
class ReturnController extends Controller
{
    public function __construct(protected ReturnService $returns) {}

    public function store(StoreReturnRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        // الطلب يجب أن يخص العميل نفسه — غير الموجود في حسابه = 404 لا 403
        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->findOrFail((int) $request->validated('order_id'));

        $return = $this->returns->createReturnRequest($order, $request->payload(), $customer);
        $return->load(['items.orderItem', 'order']);

        return ReturnRequestResource::make($return)->response()->setStatusCode(201);
    }
}
