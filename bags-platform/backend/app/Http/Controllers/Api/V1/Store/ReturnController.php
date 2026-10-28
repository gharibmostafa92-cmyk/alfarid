<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Return\StoreReturnRequest;
use App\Http\Resources\Store\ReturnRequestResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * مرتجعات العميل المسجل: تقديم طلب إرجاع لطلب مُسلَّم يخصه (مع صور عيوب).
 * بقية الدورة (اعتماد/استلام/رد) إدارية بالكامل في Admin\ReturnController.
 */
class ReturnController extends Controller
{
    private const RELATIONS = ['items.orderItem', 'order'];

    public function __construct(protected ReturnService $returns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ReturnRequestResource::collection(ReturnRequest::query()
            ->where('customer_id', $request->user()->id)->with(self::RELATIONS)->latest('id')->paginate(20));
    }

    public function show(Request $request, int $returnRequest): ReturnRequestResource
    {
        $return = ReturnRequest::query()->where('customer_id', $request->user()->id)
            ->with(self::RELATIONS)->findOrFail($returnRequest);

        return $this->returnResource($return);
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        // الطلب يجب أن يخص العميل نفسه — غير الموجود في حسابه = 404 لا 403
        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->findOrFail((int) $request->validated('order_id'));

        $return = $this->returns->createReturnRequest($order, $request->payload(), $customer);

        return $this->returnResource($return)->response()->setStatusCode(201);
    }

    private function returnResource(ReturnRequest $return): ReturnRequestResource
    {
        return ReturnRequestResource::make($return->loadMissing(self::RELATIONS));
    }
}
