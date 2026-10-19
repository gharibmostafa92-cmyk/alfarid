<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\WholesalePaymentNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly WholesalePaymentNoticeService $notices) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $usedCredit = $this->notices->outstandingBalance($customer);
        $creditLimit = (float) $customer->credit_limit;

        $inProgress = Order::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Confirmed->value,
                OrderStatus::Processing->value,
                OrderStatus::Shipped->value,
            ])
            ->count();

        $latestInvoices = Order::query()
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('payment_method', 'credit')->orWhere('payment_status', 'paid'))
            ->latest('id')
            ->limit(5)
            ->get(['id', 'order_number', 'status', 'payment_method', 'payment_status', 'grand_total', 'created_at'])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'grand_total' => $order->grand_total,
                'created_at' => $order->created_at?->toIso8601String(),
            ]);

        $latestQuotations = Quotation::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(5)
            ->get(['id', 'quotation_number', 'status', 'grand_total', 'valid_until', 'created_at'])
            ->map(fn (Quotation $quotation) => [
                'id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'status' => $quotation->status->value,
                'grand_total' => $quotation->grand_total,
                'valid_until' => $quotation->valid_until?->toDateString(),
                'created_at' => $quotation->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'credit_limit' => number_format($creditLimit, 2, '.', ''),
                'used_credit' => number_format($usedCredit, 2, '.', ''),
                'available_credit' => number_format(max(0.0, $creditLimit - $usedCredit), 2, '.', ''),
                'in_progress_orders_count' => $inProgress,
                'latest_invoices' => $latestInvoices,
                'latest_quotations' => $latestQuotations,
            ],
        ]);
    }
}
