<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Return\StoreReturnRequest;
use App\Http\Resources\Store\ReturnRequestResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\Orders\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer portal return requests for delivered orders, including defect photo attachments.
 */
class ReturnController extends Controller
{
    private const RELATIONS = ['items.orderItem', 'order'];

    public function __construct(protected ReturnService $returns) {}

    /**
     * Paginate return requests for the authenticated customer.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReturnRequestResource::collection(ReturnRequest::query()
            ->where('customer_id', $request->user()->id)->with(self::RELATIONS)->latest('id')->paginate(20));
    }

    /**
     * Display specific customer return request details.
     */
    public function show(Request $request, int $returnRequest): ReturnRequestResource
    {
        $return = ReturnRequest::query()->where('customer_id', $request->user()->id)
            ->with(self::RELATIONS)->findOrFail($returnRequest);

        return $this->returnResource($return);
    }

    /**
     * Submit a new return request against a delivered customer order.
     */
    public function store(StoreReturnRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        // Scope order lookup to current customer; throw 404 rather than 403 to prevent order ID enumeration.
        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->findOrFail((int) $request->validated('order_id'));

        $return = $this->returns->createReturnRequest($order, $request->payload(), $customer);

        return $this->returnResource($return)->response()->setStatusCode(201);
    }

    /**
     * Wrap return request model with eager-loaded relations in an API resource.
     *
     * @param  ReturnRequest  $return  Target return request model
     * @return ReturnRequestResource Formatted resource
     */
    private function returnResource(ReturnRequest $return): ReturnRequestResource
    {
        return ReturnRequestResource::make($return->loadMissing(self::RELATIONS));
    }
}
