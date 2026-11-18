<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Orders;

use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Orders\ReviewPaymentNoticeRequest;
use App\Http\Resources\Wholesale\WholesalePaymentNoticeResource;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
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
        private readonly OperationIdempotencyService $idempotency,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:payment_notices.view', only: ['index', 'show']),
            new Middleware('can:payment_notices.approve', only: ['approve', 'reverse']),
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
            ->when($this->branchAccess->restrictedBranch($request->user()), fn ($query, $branchId) => $query
                ->whereHas('order', fn ($order) => $order->where('allocated_branch_id', $branchId)))
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
    public function show(Request $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()
            ->with(['customer', 'order', 'treasury'])
            ->findOrFail($paymentNotice);
        $this->assertNoticeAccess($request->user(), $notice);

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
        $notice = WholesalePaymentNotice::query()->with('order')->findOrFail($paymentNotice);
        $this->assertNoticeAccess($request->user(), $notice);
        $data = $request->validated();
        if (isset($data['treasury_id'])) {
            $treasury = Treasury::query()->findOrFail((int) $data['treasury_id']);
            $this->branchAccess->assertTreasury($request->user()->id, $treasury);
            if (! $treasury->is_active) {
                throw new BusinessException('الخزينة غير مفعّلة.');
            }
        } else {
            $this->branchAccess->assertCentralAccess($request->user());
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        $treasuryId = isset($data['treasury_id']) ? (int) $data['treasury_id'] : null;
        $notes = $data['admin_notes'] ?? null;
        $approved = $idempotencyKey !== null
            ? $this->idempotency->run(
                'payment_notice_approve',
                $request->user(),
                $idempotencyKey,
                ['notice_id' => $notice->id, 'treasury_id' => $treasuryId, 'admin_notes' => $notes],
                fn () => $this->service->approve($notice, (int) $request->user()->id, $treasuryId, $notes),
            )
            : $this->service->approve($notice, (int) $request->user()->id, $treasuryId, $notes);

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
        $notice = WholesalePaymentNotice::query()->with('order')->findOrFail($paymentNotice);
        $this->assertNoticeAccess($request->user(), $notice);

        $notes = $request->validated('admin_notes');
        $idempotencyKey = $request->header('Idempotency-Key');
        $execute = fn (): WholesalePaymentNotice => $this->service->reject($notice, (int) $request->user()->id, $notes);
        $rejected = $idempotencyKey !== null
            ? $this->idempotency->run(
                'payment_notice_reject',
                $request->user(),
                $idempotencyKey,
                ['notice_id' => $notice->id, 'admin_notes' => $notes],
                $execute,
            )
            : $execute();

        return WholesalePaymentNoticeResource::make($rejected)->response();
    }

    /**
     * Reverses an approved wholesale payment notice with reason and treasury reversal.
     *
     * @param Request $request Reverse request containing reason
     * @param int $paymentNotice Notice ID to reverse
     * @return JsonResponse Reversed notice response
     */
    public function reverse(Request $request, int $paymentNotice): JsonResponse
    {
        $notice = WholesalePaymentNotice::query()->with(['order', 'treasury'])->findOrFail($paymentNotice);
        $this->assertNoticeAccess($request->user(), $notice);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($notice->treasury !== null) {
            $this->branchAccess->assertTreasury($request->user()->id, $notice->treasury);
        } else {
            $this->branchAccess->assertCentralAccess($request->user());
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        $execute = fn (): WholesalePaymentNotice => $this->service->reverse($notice, (int) $request->user()->id, $data['reason']);
        $reversed = $idempotencyKey !== null
            ? $this->idempotency->run(
                'payment_notice_reverse',
                $request->user(),
                $idempotencyKey,
                ['notice_id' => $notice->id, ...$data],
                $execute,
            )
            : $execute();

        return WholesalePaymentNoticeResource::make($reversed)->response();
    }

    private function assertNoticeAccess(User $user, WholesalePaymentNotice $notice): void
    {
        $branchId = $notice->order?->allocated_branch_id;
        if ($branchId === null) {
            $this->branchAccess->assertCentralAccess($user);
        } else {
            $this->branchAccess->assertBranch($user->id, (int) $branchId);
        }
    }
}
