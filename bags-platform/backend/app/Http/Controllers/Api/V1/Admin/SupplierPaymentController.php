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
 * دفعات الموردين: إنشاؤها يخفض رصيد المورد ويحدّث فاتورتها، وحذفها
 * يعكس الأثر كاملًا — عبر SupplierFinanceService فقط.
 */
class SupplierPaymentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.view', only: ['index', 'show']),
            new Middleware('can:supplier_payments.create', only: ['store']),
            new Middleware('can:supplier_payments.update', only: ['update']),
            new Middleware('can:supplier_payments.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected SupplierFinanceService $finance) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = SupplierPayment::query()
            ->with(['invoice:id,invoice_number,supplier_invoice_no'])
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($request->query('supplier_invoice_id'), fn ($q, $id) => $q->where('supplier_invoice_id', (int) $id))
            ->when($request->query('from_date'), fn ($q, $date) => $q->where('payment_date', '>=', $date))
            ->when($request->query('to_date'), fn ($q, $date) => $q->where('payment_date', '<=', $date))
            ->latest('id')
            ->paginate(20);

        return SupplierPaymentResource::collection($payments);
    }

    public function store(SupplierPaymentRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $payment = $this->finance->createPayment($request->validated(), $admin, $request->header('Idempotency-Key'));
        $payment->load(['invoice:id,invoice_number,supplier_invoice_no']);

        return SupplierPaymentResource::make($payment)->response()->setStatusCode(201);
    }

    public function show(SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        return SupplierPaymentResource::make($supplierPayment->load(['invoice:id,invoice_number,supplier_invoice_no']));
    }

    public function update(UpdateSupplierPaymentRequest $request, SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $supplierPayment = $this->finance->updatePayment($supplierPayment, $request->validated());

        return SupplierPaymentResource::make($supplierPayment->fresh(['invoice:id,invoice_number,supplier_invoice_no']));
    }

    public function destroy(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->finance->deletePayment($supplierPayment);

        return response()->json(['message' => 'تم حذف الدفعة وعكس أثرها على الرصيد والفاتورة.']);
    }
}
