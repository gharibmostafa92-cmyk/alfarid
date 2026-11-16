<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Supplier\PaymentResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Models\SupplierUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supplier portal dashboard metrics: order counts, outstanding balance, and recent payments.
 */
class DashboardController extends Controller
{
    /**
     * Retrieve aggregated metrics and recent payments for the authenticated supplier.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $supplier = $user->supplier;
        abort_if($supplier === null, 404);
        $counts = PurchaseOrder::query()
            ->where('supplier_id', $user->supplier_id)
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_orders_count,
                 SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS in_progress_count',
                [
                    PurchaseOrderStatus::SentToSupplier->value,
                    PurchaseOrderStatus::SupplierAccepted->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ],
            )
            ->toBase()
            ->first();

        return response()->json(['data' => [
            'new_orders_count' => (int) ($counts->new_orders_count ?? 0),
            'in_progress_count' => (int) ($counts->in_progress_count ?? 0),
            'current_balance' => $supplier->current_balance,
            'last_payments' => PaymentResource::collection(
                SupplierPayment::query()
                    ->with('invoice:id,invoice_number')
                    ->where('supplier_id', $user->supplier_id)
                    ->whereNull('voided_at')
                    ->latest('payment_date')->latest('id')
                    ->limit(5)
                    ->get()
            ),
        ]]);
    }
}
