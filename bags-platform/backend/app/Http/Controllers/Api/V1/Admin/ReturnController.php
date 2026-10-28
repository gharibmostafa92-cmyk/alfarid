<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReturnStatus;
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
use App\Services\BranchAccess;
use App\Services\ReturnService;
use App\Services\SearchTerm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Manages customer return request lifecycle including approvals, receiving, and refunds.
 */
class ReturnController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:returns.view', only: ['index', 'show']),
            new Middleware('can:returns.create', only: ['store']),
            new Middleware('can:returns.update', only: ['update']),
            new Middleware('can:returns.approve', only: ['approve']),
            new Middleware('can:returns.receive', only: ['receive']),
            new Middleware('can:returns.refund', only: ['refund']),
            new Middleware('can:returns.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected ReturnService $returns) {}

    /**
     * Lists paginated return requests scoped by branch access and status filters.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated return requests
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_column(ReturnStatus::cases(), 'value'))],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) $request->query('search', ''));
        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        $returns = ReturnRequest::query()->when($branch !== null, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branch)->orWhere(fn ($q) => $q->whereNull('branch_id')->whereHas('order', fn ($q) => $q->where('allocated_branch_id', $branch)))))
            ->with(['order:id,order_number', 'customer:id,name', 'items.orderItem'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
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

    /**
     * Submits a manual return request for an order on behalf of a customer.
     *
     * @param StoreReturnRequest $request Creation request
     * @return JsonResponse Created return request response
     */
    public function store(StoreReturnRequest $request): JsonResponse
    {
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));
        if ($order->allocated_branch_id !== null) {
            app(BranchAccess::class)->assertBranch($request->user()->id, (int) $order->allocated_branch_id);
        } else {
            app(BranchAccess::class)->assertCentralAccess($request->user());
        }
        $return = $this->returns->createReturnRequest($order, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return)->response()->setStatusCode(201);
    }

    /**
     * Retrieves details of a specific return request.
     *
     * @param Request $request Incoming request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Return request resource
     */
    public function show(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        $returnRequest->load(['items.orderItem', 'order', 'customer', 'branch']);

        return ReturnRequestResource::make($returnRequest);
    }

    /**
     * Updates return request attributes and status.
     *
     * @param UpdateReturnRequest $request Update request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Updated return request resource
     */
    public function update(UpdateReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        // Rejecting a return is an approval-tier decision requiring 'returns.approve' capability.
        if (($request->validated()['status'] ?? null) === 'rejected') {
            abort_unless($request->user()->can('returns.approve'), 403);
        }
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->updateDetails($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    /**
     * Deletes a pending return request.
     *
     * @param Request $request Deletion request
     * @param ReturnRequest $returnRequest Target return request
     * @return JsonResponse Deletion confirmation
     */
    public function destroy(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeReturn($request, $returnRequest);
        $this->returns->delete($returnRequest);

        return response()->json(['message' => 'تم حذف طلب المرتجع']);
    }

    /**
     * Approves a return request and assigns the receiving branch.
     *
     * @param ApproveReturnRequest $request Approval request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Approved return resource
     */
    public function approve(ApproveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        app(BranchAccess::class)->assertBranch($request->user()->id, (int) $request->validated()['branch_id']);
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->approve($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    /**
     * Confirms receipt and inspection of returned merchandise at the branch.
     *
     * @param ReceiveReturnRequest $request Receiving request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Received return resource
     */
    public function receive(ReceiveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->receive($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    /**
     * Issues financial refund for received return items.
     *
     * @param RefundReturnRequest $request Refund request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Refunded return resource
     */
    public function refund(RefundReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->refund($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    private function authorizeReturn(Request $request, ReturnRequest $returnRequest): void
    {
        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        if ($branch === null) {
            return;
        }
        $returnRequest->loadMissing('order:id,allocated_branch_id');
        $returnBranch = $returnRequest->branch_id !== null ? (int) $returnRequest->branch_id : null;
        $orderBranch = $returnRequest->order?->allocated_branch_id !== null ? (int) $returnRequest->order->allocated_branch_id : null;
        if (($returnBranch ?? $orderBranch) !== $branch) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا المرتجع.');
        }
    }
}
