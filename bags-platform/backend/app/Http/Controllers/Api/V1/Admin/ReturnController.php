<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReturnStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Return\ApproveReturnRequest;
use App\Http\Requests\Admin\Return\ReceiveReturnRequest;
use App\Http\Requests\Admin\Return\RefundReturnRequest;
use App\Http\Requests\Admin\Return\StoreReturnRequest;
use App\Http\Requests\Admin\Return\UpdateReturnRequest;
use App\Http\Resources\Admin\ReturnRequestResource;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * إدارة المرتجعات (المرحلة 9): عرض وإنشاء يدوي + دورة الاعتماد
 * والاستلام والرد المالي — كل المنطق والآثار في ReturnService.
 */
class ReturnController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:returns.view', only: ['index', 'show']),
            new Middleware('can:returns.create', only: ['store']),
            new Middleware('can:returns.update', only: ['update', 'approve', 'receive', 'refund']),
            new Middleware('can:returns.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected ReturnService $returns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));
        $returns = ReturnRequest::query()
            ->with(['order:id,order_number', 'customer:id,name', 'items.orderItem'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('order_id'), fn ($q, $orderId) => $q->where('order_id', (int) $orderId))
            ->when($request->query('customer_id'), fn ($q, $customerId) => $q->where('customer_id', (int) $customerId))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search): void {
                $pattern = SearchTerm::pattern($search);
                $w->where('return_number', 'ilike', $pattern)
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'ilike', $pattern));
            }))
            ->latest('id')
            ->paginate(20);

        return ReturnRequestResource::collection($returns);
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));
        $return = $this->returns->createReturnRequest($order, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return)->response()->setStatusCode(201);
    }

    public function show(ReturnRequest $returnRequest): ReturnRequestResource
    {
        $returnRequest->load(['items.orderItem', 'order', 'customer', 'branch']);

        return ReturnRequestResource::make($returnRequest);
    }

    public function update(UpdateReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->updateDetails($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    public function destroy(ReturnRequest $returnRequest): JsonResponse
    {
        // مرتجع وصل لمرحلة الاستلام/الرد له آثار مخزون ومال — سجله لا يُحذف
        if (in_array($returnRequest->status, [ReturnStatus::Received, ReturnStatus::Refunded], true)) {
            throw new BusinessException('لا يمكن حذف مرتجع تم استلامه أو اكتمل رده؛ أبقِ سجله للتدقيق.');
        }
        $returnRequest->delete();

        return response()->json(['message' => 'تم حذف طلب المرتجع']);
    }

    public function approve(ApproveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->approve($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    public function receive(ReceiveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->receive($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    public function refund(RefundReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->refund($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }
}
