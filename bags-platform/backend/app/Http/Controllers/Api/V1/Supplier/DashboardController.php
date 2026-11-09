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
        $supplier = $user->supplier()->firstOrFail();
        $orders = PurchaseOrder::query()->where('supplier_id', $user->supplier_id);

        return response()->json(['data' => [
            'new_orders_count' => (clone $orders)->where('status', PurchaseOrderStatus::SentToSupplier)->count(),
            'in_progress_count' => (clone $orders)->whereIn('status', [
                PurchaseOrderStatus::SupplierAccepted, PurchaseOrderStatus::PartiallyReceived,
            ])->count(),
            'current_balance' => $supplier->current_balance,
            'last_payments' => PaymentResource::collection(
                SupplierPayment::query()
                    ->with('invoice:id,invoice_number')
                    ->where('supplier_id', $user->supplier_id)
                    ->latest('payment_date')->latest('id')
                    ->limit(5)
                    ->get()
            ),
        ]]);
    }
}
