<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wholesale\StorePaymentNoticeRequest;
use App\Http\Resources\Wholesale\WholesalePaymentNoticeResource;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use App\Services\WholesalePaymentNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentNoticeController extends Controller
{
    public function __construct(private readonly WholesalePaymentNoticeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(WholesalePaymentNoticeStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $notices = WholesalePaymentNotice::query()
            ->where('customer_id', $request->user()->id)
            ->with('order')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return WholesalePaymentNoticeResource::collection($notices)->response();
    }

    public function store(StorePaymentNoticeRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $notice = $this->service->create($customer, $request->validated(), $request->file('receipt'));

        return (new WholesalePaymentNoticeResource($notice->load('order')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()
            ->where('customer_id', $request->user()->id)
            ->with('order')
            ->findOrFail($paymentNotice);

        return (new WholesalePaymentNoticeResource($notice))->response();
    }

    /** المديونية الآجلة الحالية للتاجر بعد خصم الإشعارات المعتمدة. */
    public function outstanding(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json([
            'data' => [
                'outstanding_balance' => number_format($this->service->outstandingBalance($customer), 2, '.', ''),
                'credit_limit' => $customer->credit_limit,
            ],
        ]);
    }
}
