<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\SupplierPaymentRequest;
use App\Http\Requests\Admin\Procurement\UpdateSupplierPaymentRequest;
use App\Http\Resources\Admin\SupplierPaymentResource;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SupplierFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Manages supplier procurement payments, invoice allocations, and voiding reversals.
 */
class SupplierPaymentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:supplier_payments.create', only: ['store']),
            new Middleware('can:supplier_payments.update', only: ['update', 'allocate']),
            new Middleware('can:supplier_payments.delete', only: ['destroy', 'void']),
        ];
    }

    public function __construct(protected SupplierFinanceService $finance) {}

    /**
     * Lists paginated supplier payments filtered by supplier, invoice, or date range.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated resource collection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'supplier_invoice_id' => ['nullable', 'integer', 'min:1'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
        $payments = SupplierPayment::query()
            ->with(['invoice:id,invoice_number,supplier_invoice_no'])
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($filters['supplier_invoice_id'] ?? null, fn ($q, $id) => $q->where('supplier_invoice_id', (int) $id))
            ->when($filters['from_date'] ?? null, fn ($q, $date) => $q->where('payment_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn ($q, $date) => $q->where('payment_date', '<=', $date))
            ->latest('id')
            ->paginate(20);

        return SupplierPaymentResource::collection($payments);
    }

    /**
     * Records a new payment to a supplier, updating treasury balances and invoice statuses.
     *
     * @param SupplierPaymentRequest $request Validated payment request
     * @return JsonResponse Created payment resource response
     */
    public function store(SupplierPaymentRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $payment = $this->finance->createPayment($request->validated(), $admin, $request->header('Idempotency-Key'));
        $payment->load(['invoice:id,invoice_number,supplier_invoice_no']);

        return SupplierPaymentResource::make($payment)->response()->setStatusCode(201);
    }

    /**
     * Retrieves details of a specific supplier payment.
     *
     * @param SupplierPayment $supplierPayment Target payment
     * @return SupplierPaymentResource Formatted payment resource
     */
    public function show(SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        return SupplierPaymentResource::make($supplierPayment->load(['invoice:id,invoice_number,supplier_invoice_no']));
    }

    /**
     * Updates non-financial metadata on a supplier payment.
     *
     * @param UpdateSupplierPaymentRequest $request Validated update payload
     * @param SupplierPayment $supplierPayment Target payment
     * @return SupplierPaymentResource Updated payment resource
     */
    public function update(UpdateSupplierPaymentRequest $request, SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $supplierPayment = $this->finance->updatePayment($supplierPayment, $request->validated());

        return SupplierPaymentResource::make($supplierPayment->fresh(['invoice:id,invoice_number,supplier_invoice_no']));
    }

    /**
     * Deletes a supplier payment by delegating to void with an audit reason.
     *
     * @param Request $request Request containing void_reason
     * @param SupplierPayment $supplierPayment Payment to void
     * @return JsonResponse Void confirmation
     */
    public function destroy(Request $request, SupplierPayment $supplierPayment): JsonResponse
    {
        return $this->void($request, $supplierPayment);
    }

    /**
     * Voids a supplier payment and reverses its financial impact across treasury and ledger.
     *
     * @param Request $request Request containing void_reason
     * @param SupplierPayment $supplierPayment Payment to void
     * @return JsonResponse Reversal confirmation
     */
    public function void(Request $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $this->finance->voidPayment($supplierPayment, $admin, $validated['void_reason']);

        return response()->json(['message' => 'تم إلغاء الدفعة وعكس أثرها على الرصيد والفاتورة.']);
    }

    /**
     * Allocates an unlinked payment to a specific posted supplier invoice.
     *
     * @param Request $request Request containing supplier_invoice_id
     * @param SupplierPayment $supplierPayment Payment to allocate
     * @return SupplierPaymentResource Updated payment resource
     */
    public function allocate(Request $request, SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $data = $request->validate([
            'supplier_invoice_id' => ['required', 'integer', 'exists:supplier_invoices,id'],
        ]);
        $payment = $this->finance->allocatePayment($supplierPayment, (int) $data['supplier_invoice_id']);

        return SupplierPaymentResource::make($payment->load(['invoice:id,invoice_number,supplier_invoice_no']));
    }
}
