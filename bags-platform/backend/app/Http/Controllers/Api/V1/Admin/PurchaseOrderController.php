<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\PurchaseOrderRequest;
use App\Http\Requests\Admin\Procurement\ReceivePurchaseOrderRequest;
use App\Http\Resources\Admin\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ProcurementService;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Supplier purchase order lifecycle: drafting, approval, transmission, cancellation, and receipt with cost revaluation.
 */
class PurchaseOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:purchases.create', only: ['store']),
            new Middleware('can:purchases.update', only: ['update', 'send', 'cancel']),
            new Middleware('can:purchases.approve', only: ['approve']),
            new Middleware('can:purchases.receive', only: ['receive', 'closeShort']),
            new Middleware('can:purchases.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected ProcurementService $procurement) {}

    /**
     * List paginated purchase orders with supplier and branch filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));
        $orders = PurchaseOrder::query()
            ->with(['supplier:id,code,name', 'branch:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where('branch_id', (int) $id))
            ->when($search !== '', fn ($q) => $q->where('po_number', 'ilike', SearchTerm::pattern($search)))
            ->latest('id')
            ->paginate(20);

        return PurchaseOrderResource::collection($orders);
    }

    /**
     * Create a new draft purchase order.
     */
    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $po = $this->procurement->createPurchaseOrder($request->validated(), $admin);

        return $this->orderResource($po)->response()->setStatusCode(201);
    }

    /**
     * Retrieve details of a purchase order including item lines and delivery status.
     */
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return $this->orderResource($purchaseOrder, includeDeliveries: true);
    }

    /**
     * Update details of an existing draft purchase order.
     */
    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $po = $this->procurement->updatePurchaseOrder($purchaseOrder, $request->validated());

        return $this->orderResource($po);
    }

    /**
     * Delete an unapproved draft purchase order.
     */
    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->procurement->deletePurchaseOrder($purchaseOrder);

        return response()->json(['message' => 'تم حذف أمر الشراء.']);
    }

    /**
     * Approve a purchase order for submission to the supplier.
     */
    public function approve(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return PurchaseOrderResource::make($this->procurement->approvePurchaseOrder($purchaseOrder, $admin));
    }

    /**
     * Mark a purchase order as sent to the supplier.
     */
    public function send(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->procurement->sendPurchaseOrder($purchaseOrder));
    }

    /**
     * Cancel a purchase order before fulfillment.
     */
    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->procurement->cancelPurchaseOrder($purchaseOrder));
    }

    /**
     * Close a purchase order short when remaining quantities will not be fulfilled.
     */
    public function closeShort(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->procurement->closeShort($purchaseOrder));
    }

    /**
     * Receive goods against a purchase order and update weighted average inventory costs.
     */
    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        /** @var User $admin */
        $admin = $request->user();
        $po = $this->procurement->receivePurchaseOrder(
            $purchaseOrder,
            $request->validated('items'),
            $request->validated('location_id') !== null ? (int) $request->validated('location_id') : null,
            $request->validated('supplier_delivery_id') !== null ? (int) $request->validated('supplier_delivery_id') : null,
            $admin->id,
            $request->header('Idempotency-Key'),
        );

        return $this->orderResource($po);
    }

    /**
     * Wrap purchase order with related models in an API resource.
     *
     * @param  PurchaseOrder  $order  Target purchase order
     * @param  bool  $includeDeliveries  Whether to include supplier deliveries
     * @return PurchaseOrderResource Formatted resource
     */
    private function orderResource(PurchaseOrder $order, bool $includeDeliveries = false): PurchaseOrderResource
    {
        $relations = ['supplier:id,code,name', 'branch:id,name', 'items.variant'];
        if ($includeDeliveries) {
            $relations[] = 'deliveries.items.variant';
        }

        return PurchaseOrderResource::make($order->loadMissing($relations));
    }
}
