<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupplierInvoiceStatus;
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
use Illuminate\Validation\Rule;

/**
 * Handles administrative supplier purchase invoices, posting, cancellation, and deletions.
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

    /**
     * Lists paginated supplier invoices filtered by status, supplier, or purchase order.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated invoice resources
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SupplierInvoiceStatus::class)],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'purchase_order_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $invoices = SupplierInvoice::query()
            ->with(['supplier:id,code,name', 'purchaseOrder:id,po_number'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $id) => $q->where('purchase_order_id', (int) $id))
            ->latest('id')
            ->paginate(20);

        return SupplierInvoiceResource::collection($invoices);
    }

    /**
     * Creates a new draft supplier purchase invoice with optional proof attachment.
     *
     * @param SupplierInvoiceRequest $request Creation request
     * @return JsonResponse Created invoice resource response
     */
    public function store(SupplierInvoiceRequest $request): JsonResponse
    {
        $data = $request->safe()->except('attachment');
        $invoice = $this->finance->createInvoice($data, $request->file('attachment'));

        return $this->invoiceResource($invoice)->response()->setStatusCode(201);
    }

    /**
     * Retrieves details of a specific supplier invoice.
     *
     * @param SupplierInvoice $supplierInvoice Target invoice
     * @return SupplierInvoiceResource Invoice resource
     */
    public function show(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return $this->invoiceResource($supplierInvoice);
    }

    /**
     * Updates an editable draft supplier invoice.
     *
     * @param SupplierInvoiceRequest $request Update request
     * @param SupplierInvoice $supplierInvoice Target invoice
     * @return SupplierInvoiceResource Updated invoice resource
     */
    public function update(SupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        $data = $request->safe()->except('attachment');
        $invoice = $this->finance->updateInvoice($supplierInvoice, $data, $request->file('attachment'));

        return $this->invoiceResource($invoice);
    }

    /**
     * Posts a draft supplier invoice, establishing liability on the supplier balance.
     *
     * @param SupplierInvoice $supplierInvoice Invoice to post
     * @return SupplierInvoiceResource Posted invoice resource
     */
    public function post(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return SupplierInvoiceResource::make($this->finance->postInvoice($supplierInvoice));
    }

    /**
     * Cancels an unpaid supplier invoice and reverses accounts payable liability.
     *
     * @param SupplierInvoice $supplierInvoice Invoice to cancel
     * @return SupplierInvoiceResource Cancelled invoice resource
     */
    public function cancel(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return SupplierInvoiceResource::make($this->finance->cancelInvoice($supplierInvoice));
    }

    /**
     * Deletes a draft supplier invoice.
     *
     * @param SupplierInvoice $supplierInvoice Invoice to delete
     * @return JsonResponse Deletion confirmation
     */
    public function destroy(SupplierInvoice $supplierInvoice): JsonResponse
    {
        $this->finance->deleteInvoice($supplierInvoice);

        return response()->json(['message' => 'تم حذف فاتورة المورد.']);
    }

    private function invoiceResource(SupplierInvoice $invoice): SupplierInvoiceResource
    {
        return SupplierInvoiceResource::make(
            $invoice->loadMissing(['supplier:id,code,name', 'purchaseOrder:id,po_number'])
        );
    }
}
