<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Procurement;

use App\Enums\Procurement\PurchaseRequestStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\PurchaseRequestRequest;
use App\Http\Resources\Admin\Procurement\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Procurement\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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

    public function __construct(protected ProcurementService $procurement) {}

    /**
     * List paginated purchase requisitions with branch and status filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = PurchaseRequest::query()
            ->with(['branch:id,name', 'items.variant'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('branch_id'), fn ($q, $branchId) => $q->where('branch_id', (int) $branchId))
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
    public function show(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return $this->requestResource($purchaseRequest);
    }

    /**
     * Update details of an existing draft purchase requisition.
     */
    public function update(PurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $pr = $this->procurement->updatePurchaseRequest($purchaseRequest, $request->validated());

        return $this->requestResource($pr);
    }

    /**
     * Delete a draft purchase requisition.
     */
    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessException('لا يمكن حذف طلب شراء إلا وهو مسودة.');
        }
        $purchaseRequest->delete();

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
    public function submit(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->submitPurchaseRequest($purchaseRequest));
    }

    /**
     * Approve a submitted purchase requisition.
     */
    public function approve(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->approvePurchaseRequest($purchaseRequest));
    }

    /**
     * Reject a submitted purchase requisition.
     */
    public function reject(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->rejectPurchaseRequest($purchaseRequest));
    }
}
