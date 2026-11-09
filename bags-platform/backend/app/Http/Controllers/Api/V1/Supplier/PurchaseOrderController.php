<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\PurchaseOrder\RequestChangeRequest;
use App\Http\Resources\Supplier\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierUser;
use App\Services\Procurement\ProcurementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Supplier portal purchase orders: strictly scoped to the authenticated supplier ID to prevent cross-tenant enumeration.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(protected ProcurementService $procurement) {}

    /**
     * List paginated purchase orders issued to the authenticated supplier.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $orders = $this->scoped($user)
            ->with(['branch:id,name', 'items.variant'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return PurchaseOrderResource::collection($orders);
    }

    /**
     * Retrieve details of a specific purchase order including deliveries and items.
     */
    public function show(Request $request, int $purchaseOrder): PurchaseOrderResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = $this->scoped($user)
            ->with(['branch:id,name', 'items.variant', 'deliveries.items.variant'])
            ->findOrFail($purchaseOrder);

        return PurchaseOrderResource::make($order);
    }

    /**
     * Accept purchase order and confirm fulfillment commitment (sent_to_supplier -> supplier_accepted).
     */
    public function accept(Request $request, int $purchaseOrder): PurchaseOrderResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = $this->scoped($user)->findOrFail($purchaseOrder);

        return PurchaseOrderResource::make($this->procurement->supplierAccept($order, $user));
    }

    /**
     * Request order modifications with explanatory notes for administrative review (-> change_requested).
     */
    public function requestChange(RequestChangeRequest $request, int $purchaseOrder): PurchaseOrderResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = $this->scoped($user)->findOrFail($purchaseOrder);

        return PurchaseOrderResource::make(
            $this->procurement->supplierRequestChange($order, $user, $request->validated('notes'))
        );
    }

    /**
     * Scope query to non-draft purchase orders assigned to the authenticated supplier.
     */
    private function scoped(SupplierUser $user): Builder
    {
        return PurchaseOrder::query()->where('supplier_id', $user->supplier_id)
            ->where('status', '!=', 'draft');
    }
}
