<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewPaymentNoticeRequest;
use App\Http\Resources\Wholesale\WholesalePaymentNoticeResource;
use App\Models\WholesalePaymentNotice;
use App\Services\WholesalePaymentNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class WholesalePaymentNoticeController extends Controller implements HasMiddleware
{
    public function __construct(private readonly WholesalePaymentNoticeService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:payment_notices.view', only: ['index', 'show']),
            new Middleware('can:payment_notices.approve', only: ['approve']),
            new Middleware('can:payment_notices.reject', only: ['reject']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(WholesalePaymentNoticeStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $notices = WholesalePaymentNotice::query()
            ->with(['customer', 'order'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return WholesalePaymentNoticeResource::collection($notices)->response();
    }

    public function show(int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()
            ->with(['customer', 'order', 'treasury'])
            ->findOrFail($paymentNotice);

        return (new WholesalePaymentNoticeResource($notice))->response();
    }

    public function approve(ReviewPaymentNoticeRequest $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()->findOrFail($paymentNotice);
        $data = $request->validated();

        $approved = $this->service->approve(
            $notice,
            (int) $request->user()->id,
            isset($data['treasury_id']) ? (int) $data['treasury_id'] : null,
            $data['admin_notes'] ?? null,
        );

        return (new WholesalePaymentNoticeResource($approved))->response();
    }

    public function reject(ReviewPaymentNoticeRequest $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()->findOrFail($paymentNotice);

        $rejected = $this->service->reject($notice, (int) $request->user()->id, $request->validated('admin_notes'));

        return (new WholesalePaymentNoticeResource($rejected))->response();
    }
}
