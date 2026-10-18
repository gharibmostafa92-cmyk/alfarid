<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
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
 * أوامر الشراء للموردين: إنشاء/اعتماد/إرسال/إلغاء + استلام مخزني
 * يحدّث متوسط التكلفة المرجح (ProcurementService::receivePurchaseOrder).
 */
class PurchaseOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:purchases.create', only: ['store']),
            new Middleware('can:purchases.update', only: ['update', 'approve', 'send', 'cancel', 'receive']),
            new Middleware('can:purchases.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected ProcurementService $procurement) {}

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

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $po = $this->procurement->createPurchaseOrder($request->validated(), $admin);
        $po->load(['supplier:id,code,name', 'branch:id,name', 'items.variant']);

        return PurchaseOrderResource::make($po)->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($purchaseOrder->load([
            'supplier:id,code,name', 'branch:id,name', 'items.variant', 'deliveries.items.variant',
        ]));
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $po = $this->procurement->updatePurchaseOrder($purchaseOrder, $request->validated());
        $po->load(['supplier:id,code,name', 'branch:id,name', 'items.variant']);

        return PurchaseOrderResource::make($po);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (! in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], true)
            || $purchaseOrder->items()->where('received_qty', '>', 0)->exists()) {
            throw new BusinessException('لا يمكن حذف أمر شراء إلا وهو مسودة أو ملغي وبلا أي استلام.');
        }
        $purchaseOrder->delete();

        return response()->json(['message' => 'تم حذف أمر الشراء.']);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return PurchaseOrderResource::make($this->procurement->approvePurchaseOrder($purchaseOrder, $admin));
    }

    public function send(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->procurement->sendPurchaseOrder($purchaseOrder));
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->procurement->cancelPurchaseOrder($purchaseOrder));
    }

    /** استلام بضاعة (كلي/جزئي) — يحدّث متوسط التكلفة ويغذي المخزون. */
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
        );
        $po->load(['supplier:id,code,name', 'branch:id,name', 'items.variant']);

        return PurchaseOrderResource::make($po);
    }
}
