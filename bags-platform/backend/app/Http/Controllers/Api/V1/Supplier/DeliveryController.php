<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\Delivery\StoreDeliveryRequest;
use App\Http\Resources\Supplier\DeliveryResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierDelivery;
use App\Models\SupplierUser;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * إشعارات توريد المورد (ASN): تسجيل شحنة جزئية/كلية مقابل أمر شرائه
 * مع فاتورة مبدئية — ويستلمها المخزن لاحقًا من إدارة أوامر الشراء.
 */
class DeliveryController extends Controller
{
    public function __construct(protected ProcurementService $procurement) {}

    public function store(StoreDeliveryRequest $request, int $purchaseOrder): JsonResponse
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = PurchaseOrder::query()
            ->where('supplier_id', $user->supplier_id)
            ->findOrFail($purchaseOrder);

        $delivery = $this->procurement->createSupplierDelivery(
            $order,
            $user,
            $request->safe()->except('invoice_file'),
            $request->file('invoice_file'),
            $request->header('Idempotency-Key'),
        );
        $delivery->load(['items.variant']);

        return DeliveryResource::make($delivery)->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $deliveries = SupplierDelivery::query()
            ->where('supplier_id', $user->supplier_id)
            ->with(['items.variant'])
            ->when($request->query('purchase_order_id'), fn ($q, $id) => $q->where('purchase_order_id', (int) $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return DeliveryResource::collection($deliveries);
    }

    public function show(Request $request, int $delivery): DeliveryResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $record = SupplierDelivery::query()
            ->where('supplier_id', $user->supplier_id)
            ->with(['items.variant'])
            ->findOrFail($delivery);

        return DeliveryResource::make($record);
    }
}
