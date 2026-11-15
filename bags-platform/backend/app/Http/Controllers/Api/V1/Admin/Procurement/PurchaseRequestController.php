<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Procurement;

use App\Enums\Procurement\PurchaseRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\PurchaseRequestRequest;
use App\Http\Resources\Admin\Procurement\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Procurement\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Internal branch purchase requisitions: submission, approval, and downstream conversion into purchase orders.
 */
class PurchaseRequestController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:purchases.create', only: ['store']),
            new Middleware('can:purchases.update', only: ['update', 'submit']),
            new Middleware('can:purchase_requests.approve', only: ['approve', 'reject']),
            new Middleware('can:purchases.delete', only: ['destroy']),
        ];
    }

    public function __construct(
        protected ProcurementService $procurement,
        protected BranchAccess $branchAccess,
    ) {}

    /**
     * List paginated purchase requisitions with branch and status filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PurchaseRequestStatus::class)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $restrictedBranch = $this->branchAccess->restrictedBranch($request->user());
        $requests = PurchaseRequest::query()
            ->with(['branch:id,name', 'items.variant'])
            ->when($restrictedBranch !== null, fn ($q) => $q->where('branch_id', $restrictedBranch))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', (int) $branchId))
            ->latest('id')
            ->paginate(20);

        return PurchaseRequestResource::collection($requests);
    }

    /**
     * Store a newly created draft purchase requisition.
     */
    public function store(PurchaseRequestRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $pr = $this->procurement->createPurchaseRequest($request->validated(), $admin);

        return $this->requestResource($pr)->response()->setStatusCode(201);
    }

    /**
     * Retrieve details of a specific purchase requisition.
     */
    public function show(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $this->branchAccess->assertBranch($request->user()->id, (int) $purchaseRequest->branch_id);

        return $this->requestResource($purchaseRequest);
    }

    /**
     * Update details of an existing draft purchase requisition.
     */
    public function update(PurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $pr = $this->procurement->updatePurchaseRequest($purchaseRequest, $request->validated(), $request->user());

        return $this->requestResource($pr);
    }

    /**
     * Delete a draft purchase requisition.
     */
    public function destroy(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->procurement->deletePurchaseRequest($purchaseRequest, $request->user());

        return response()->json(['message' => 'تم حذف طلب الشراء.']);
    }

    /**
     * Wrap purchase requisition with branch and variant lines in an API resource.
     *
     * @param  PurchaseRequest  $request  Target purchase requisition
     * @return PurchaseRequestResource Formatted resource
     */
    private function requestResource(PurchaseRequest $request): PurchaseRequestResource
    {
        return PurchaseRequestResource::make(
            $request->loadMissing(['branch:id,name', 'items.variant'])
        );
    }

    /**
     * Submit a draft purchase requisition for review.
     */
    public function submit(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->submitPurchaseRequest($purchaseRequest, $request->user()));
    }

    /**
     * Approve a submitted purchase requisition.
     */
    public function approve(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->approvePurchaseRequest($purchaseRequest, $request->user()));
    }

    /**
     * Reject a submitted purchase requisition.
     */
    public function reject(Request $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->rejectPurchaseRequest($purchaseRequest, $request->user()));
    }
}
