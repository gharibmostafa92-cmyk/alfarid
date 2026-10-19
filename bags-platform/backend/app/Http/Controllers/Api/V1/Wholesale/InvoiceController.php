<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\SettingService;
use App\Services\WholesalePaymentNoticeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly WholesalePaymentNoticeService $notices,
        private readonly SettingService $settings,
    ) {}

    /** فواتير التاجر: طلباته المسددة والآجلة معًا. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = Order::query()
            ->where('customer_id', $request->user()->id)
            ->where(fn ($q) => $q->where('payment_method', 'credit')->orWhere('payment_status', 'paid'))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        $data = $invoices->getCollection()->map(fn (Order $order) => [
            'id' => $order->id,
            'invoice_number' => $order->order_number,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'shipping_cost' => $order->shipping_cost,
            'tax_amount' => $order->tax_amount,
            'grand_total' => $order->grand_total,
            'created_at' => $order->created_at?->toIso8601String(),
            'pdf_url' => url("/api/v1/wholesale/invoices/{$order->id}/pdf"),
        ])->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /** تنزيل فاتورة طلب التاجر كـ PDF (طلب الغير = 404). */
    public function pdf(Request $request, int $order): Response
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $model = Order::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->findOrFail($order);

        return Pdf::loadView('pdfs.wholesale_invoice', [
            'company_name' => (string) $this->settings->get('company_name', 'Alfarid'),
            'customer' => $customer,
            'order' => $model,
        ])->download("invoice-{$model->order_number}.pdf");
    }

    /** كشف حساب مالي تفصيلي: آجل مدين، سداد ومرتجعات دائنة، رصيد تراكمي. */
    public function statement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $statement = $this->notices->statement($customer, $validated['from'] ?? null, $validated['to'] ?? null);

        return response()->json([
            'data' => [
                'opening_balance' => number_format($statement['opening_balance'], 2, '.', ''),
                'closing_balance' => number_format($statement['closing_balance'], 2, '.', ''),
                'entries' => $statement['entries'],
            ],
        ]);
    }
}
