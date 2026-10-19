<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\SupplierInvoiceRequest;
use App\Http\Resources\Admin\SupplierInvoiceResource;
use App\Models\SupplierInvoice;
use App\Services\SupplierFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * فواتير الموردين: تسجيل مسودة ثم اعتماد (post) يثبت الاستحقاق
 * في رصيد المورد — الآثار المالية كلها في SupplierFinanceService.
 */
class SupplierInvoiceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:purchases.create', only: ['store']),
            new Middleware('can:purchases.update', only: ['update']),
            new Middleware('can:supplier_invoices.post', only: ['post']),
            new Middleware('can:supplier_invoices.cancel', only: ['cancel']),
            new Middleware('can:purchases.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected SupplierFinanceService $finance) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = SupplierInvoice::query()
            ->with(['supplier:id,code,name', 'purchaseOrder:id,po_number'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($request->query('purchase_order_id'), fn ($q, $id) => $q->where('purchase_order_id', (int) $id))
            ->latest('id')
            ->paginate(20);

        return SupplierInvoiceResource::collection($invoices);
    }

    public function store(SupplierInvoiceRequest $request): JsonResponse
    {
        $data = $request->safe()->except('attachment');
        $invoice = $this->finance->createInvoice($data, $request->file('attachment'));
        $invoice->load(['supplier:id,code,name', 'purchaseOrder:id,po_number']);

        return SupplierInvoiceResource::make($invoice)->response()->setStatusCode(201);
    }

    public function show(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return SupplierInvoiceResource::make($supplierInvoice->load(['supplier:id,code,name', 'purchaseOrder:id,po_number']));
    }

    public function update(SupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        $data = $request->safe()->except('attachment');
        $invoice = $this->finance->updateInvoice($supplierInvoice, $data, $request->file('attachment'));
        $invoice->load(['supplier:id,code,name', 'purchaseOrder:id,po_number']);

        return SupplierInvoiceResource::make($invoice);
    }

    public function post(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return SupplierInvoiceResource::make($this->finance->postInvoice($supplierInvoice));
    }

    public function cancel(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return SupplierInvoiceResource::make($this->finance->cancelInvoice($supplierInvoice));
    }

    public function destroy(SupplierInvoice $supplierInvoice): JsonResponse
    {
        $this->finance->deleteInvoice($supplierInvoice);

        return response()->json(['message' => 'تم حذف فاتورة المورد.']);
    }
}
