<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PurchaseRequestStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\PurchaseRequestRequest;
use App\Http\Resources\Admin\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * طلبات الشراء الداخلية: إنشاء فروع لنواقصها واعتماد إدارة المشتريات،
 * ثم التحويل لأمر شراء من متحكم أوامر الشراء. الدورة في ProcurementService.
 */
class PurchaseRequestController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:purchases.create', only: ['store']),
            new Middleware('can:purchases.update', only: ['update', 'submit', 'approve', 'reject']),
            new Middleware('can:purchases.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected ProcurementService $procurement) {}

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

    public function store(PurchaseRequestRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $pr = $this->procurement->createPurchaseRequest($request->validated(), $admin);
        $pr->load(['branch:id,name', 'items.variant']);

        return PurchaseRequestResource::make($pr)->response()->setStatusCode(201);
    }

    public function show(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($purchaseRequest->load(['branch:id,name', 'items.variant']));
    }

    public function update(PurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $pr = $this->procurement->updatePurchaseRequest($purchaseRequest, $request->validated());
        $pr->load(['branch:id,name', 'items.variant']);

        return PurchaseRequestResource::make($pr);
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::Draft) {
            throw new BusinessException('لا يمكن حذف طلب شراء إلا وهو مسودة.');
        }
        $purchaseRequest->delete();

        return response()->json(['message' => 'تم حذف طلب الشراء.']);
    }

    public function submit(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->submitPurchaseRequest($purchaseRequest));
    }

    public function approve(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->approvePurchaseRequest($purchaseRequest));
    }

    public function reject(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        return PurchaseRequestResource::make($this->procurement->rejectPurchaseRequest($purchaseRequest));
    }
}
