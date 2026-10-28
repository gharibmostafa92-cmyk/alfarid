<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewPaymentNoticeRequest;
use App\Http\Resources\Wholesale\WholesalePaymentNoticeResource;
use App\Models\Treasury;
use App\Models\WholesalePaymentNotice;
use App\Services\BranchAccess;
use App\Services\WholesalePaymentNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Admin controller for reviewing, approving, and rejecting wholesale customer payment notices.
 */
class WholesalePaymentNoticeController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly WholesalePaymentNoticeService $service,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:payment_notices.view', only: ['index', 'show']),
            new Middleware('can:payment_notices.approve', only: ['approve']),
            new Middleware('can:payment_notices.reject', only: ['reject']),
        ];
    }

    /**
     * Lists paginated wholesale payment notices with status and customer filtering.
     *
     * @param Request $request Filter query request
     * @return JsonResponse Paginated notices resource collection
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(WholesalePaymentNoticeStatus::class)],
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

    /**
     * Retrieves details of a specific payment notice with customer, order, and treasury relations.
     *
     * @param int $paymentNotice Notice ID
     * @return JsonResponse Notice details response
     */
    public function show(int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()
            ->with(['customer', 'order', 'treasury'])
            ->findOrFail($paymentNotice);

        return WholesalePaymentNoticeResource::make($notice)->response();
    }

    /**
     * Approves a wholesale payment notice, crediting the treasury and reconciling orders.
     *
     * @param ReviewPaymentNoticeRequest $request Review payload
     * @param int $paymentNotice Notice ID to approve
     * @return JsonResponse Approved notice response
     */
    public function approve(ReviewPaymentNoticeRequest $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()->findOrFail($paymentNotice);
        $data = $request->validated();
        if (isset($data['treasury_id'])) {
            $treasury = Treasury::query()->findOrFail((int) $data['treasury_id']);
            $this->branchAccess->assertTreasury($request->user()->id, $treasury);
            if (! $treasury->is_active) {
                throw new BusinessException('الخزينة غير مفعّلة.');
            }
        }

        $approved = $this->service->approve(
            $notice,
            (int) $request->user()->id,
            isset($data['treasury_id']) ? (int) $data['treasury_id'] : null,
            $data['admin_notes'] ?? null,
        );

        return WholesalePaymentNoticeResource::make($approved)->response();
    }

    /**
     * Rejects a wholesale payment notice with reviewer notes.
     *
     * @param ReviewPaymentNoticeRequest $request Review payload
     * @param int $paymentNotice Notice ID to reject
     * @return JsonResponse Rejected notice response
     */
    public function reject(ReviewPaymentNoticeRequest $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()->findOrFail($paymentNotice);

        $rejected = $this->service->reject($notice, (int) $request->user()->id, $request->validated('admin_notes'));

        return WholesalePaymentNoticeResource::make($rejected)->response();
    }
}
