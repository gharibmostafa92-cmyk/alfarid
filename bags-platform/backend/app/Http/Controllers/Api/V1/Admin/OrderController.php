<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\AllocateOrderRequest;
use App\Http\Requests\Admin\Order\CreateShipmentRequest;
use App\Http\Requests\Admin\Order\UpdateOrderPaymentRequest;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\ShipmentResource;
use App\Models\Order;
use App\Services\BranchAccess;
use App\Services\OrderAllocationService;
use App\Services\OrderService;
use App\Services\SearchTerm;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin controller for order processing, allocation, status workflows, and payment transitions.
 */
class OrderController extends Controller implements HasMiddleware
{
    /** State transition matrix preventing illogical payment status jumps. */
    private const PAYMENT_TRANSITIONS = [
        'unpaid' => ['paid', 'failed'],
        'failed' => ['paid', 'unpaid'],
        'paid' => ['refunded'],
        'refunded' => [],
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderAllocationService $allocation,
        private readonly ShipmentService $shipments,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.view', only: ['index', 'show', 'stats']),
            new Middleware('can:orders.update', only: ['updateStatus', 'createShipment']),
            // Separate payment authority isolates financial modifications from operational status changes.
            new Middleware('can:orders.payment', only: ['updatePayment']),
            new Middleware('can:orders.allocate', only: ['allocate', 'deallocate']),
        ];
    }

    /**
     * Lists paginated orders with branch access scoping, search, and status filters.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated order resources
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'allocated_branch_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', Rule::in(['cod', 'card', 'wallet', 'bank_transfer', 'credit'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'needs_attention' => ['nullable', 'boolean'],
            'requires_refund' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branchAccess->restrictedBranch($request->user());
        if ($branch !== null && isset($filters['allocated_branch_id']) && (int) $filters['allocated_branch_id'] !== $branch) {
            throw new BusinessException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }

        $orders = Order::query()
            ->when($branch !== null, fn ($q) => $q->where('allocated_branch_id', $branch))
            ->with(['customer', 'allocatedBranch'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['allocated_branch_id'] ?? null, fn ($q, $branchId) => $q->where('allocated_branch_id', $branchId))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when(array_key_exists('needs_attention', $filters) && $filters['needs_attention'] !== null,
                fn ($q) => $q->where('needs_attention', (bool) $filters['needs_attention']))
            ->when(array_key_exists('requires_refund', $filters) && $filters['requires_refund'] !== null,
                fn ($q) => $q->where('requires_refund', (bool) $filters['requires_refund']))
            // Range comparison leverages created_at index while converting business timezone to UTC.
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=',
                Carbon::parse($date, (string) config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('created_at', '<',
                Carbon::parse($date, (string) config('app.business_timezone'))->startOfDay()->addDay()->utc()))
            // Route numeric queries to indexed prefix lookups and text queries to trigram name searches.
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $prefix = SearchTerm::prefix((string) $term);
                $numeric = preg_match('/^\s*[0-9+\-\s()]+\s*$/', (string) $term) === 1;
                $inner->where('order_number', 'like', $prefix)
                    ->orWhere('guest_phone', 'like', $prefix)
                    ->orWhere('guest_phone_normalized', 'like', $prefix);
                if (! $numeric) {
                    $pattern = SearchTerm::pattern((string) $term);
                    $inner->orWhere('guest_name', 'ilike', $pattern)
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'ilike', $pattern));
                }
            }))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return AdminOrderResource::collection($orders);
    }

    /**
     * Aggregates order queue counts in a single query.
     *
     * @param Request $request Authenticated admin request
     * @return JsonResponse Aggregated queue statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $branch = $this->branchAccess->restrictedBranch($request->user());

        $row = Order::query()
            ->when($branch !== null, fn ($q) => $q->where('allocated_branch_id', $branch))
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'pending') AS pending")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'processing') AS processing")
            ->selectRaw('COUNT(*) FILTER (WHERE needs_attention IS TRUE) AS needs_attention')
            ->selectRaw('COUNT(*) FILTER (WHERE requires_refund IS TRUE) AS requires_refund')
            ->first();

        return response()->json(['data' => [
            'pending' => (int) ($row->pending ?? 0),
            'processing' => (int) ($row->processing ?? 0),
            'needs_attention' => (int) ($row->needs_attention ?? 0),
            'requires_refund' => (int) ($row->requires_refund ?? 0),
        ]]);
    }

    /**
     * Retrieves full details and status histories of an order.
     *
     * @param Request $request Incoming request
     * @param Order $order Target order model
     * @return AdminOrderResource Order resource
     */
    public function show(Request $request, Order $order): AdminOrderResource
    {
        $this->authorizeOrder($request, $order);

        return $this->orderResource($order);
    }

    /**
     * Transitions an order to a new operational status.
     *
     * @param UpdateOrderStatusRequest $request Validated status request
     * @param Order $order Target order
     * @return AdminOrderResource Updated order resource
     * @throws BusinessException If manually transitioning to Shipped without an AWB
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): AdminOrderResource
    {
        $this->authorizeOrder($request, $order);
        // Shipping status requires formal courier consignment creation to guarantee tracking webhooks.
        if (($request->validated()['status'] ?? null) === OrderStatus::Shipped->value) {
            throw new BusinessException('حالة الشحن تتم فقط عبر إنشاء بوليصة شحن للطلب.');
        }

        $order = $this->orders->changeStatus(
            $order,
            (string) $request->validated()['status'],
            $request->user(),
            $request->validated()['notes'] ?? null,
        );

        return $this->orderResource($order);
    }

    /**
     * Updates order payment status under row lock and registers audit history.
     *
     * @param UpdateOrderPaymentRequest $request Payment status request
     * @param Order $order Target order
     * @return AdminOrderResource Updated order resource
     * @throws BusinessException If transition is invalid or refund reference is missing
     */
    public function updatePayment(UpdateOrderPaymentRequest $request, Order $order): AdminOrderResource
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validated();
        $to = (string) $data['payment_status'];

        $order = DB::transaction(function () use ($order, $to, $data, $request): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->payment_status;

            if ($from === $to) {
                return $order;
            }
            if (! in_array($to, self::PAYMENT_TRANSITIONS[$from] ?? [], true)) {
                throw new BusinessException("انتقال حالة دفع غير مسموح: من ({$from}) إلى ({$to}).");
            }

            // Marking cancelled/returned orders as paid triggers a refund liability; refunded clears it.
            $requiresRefund = $to === 'paid'
                && in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true);
            if ($to === 'refunded') {
                if (empty($data['refund_reference'])) {
                    throw new BusinessException('الانتقال إلى مسترد يتطلب مرجع استرداد (refund_reference).');
                }
                $requiresRefund = false;
            }

            $order->update([
                'payment_status' => $to,
                'requires_refund' => $requiresRefund,
            ]);

            $actor = $request->user();
            $notes = trim("تحديث يدوي لحالة الدفع: {$from} ← {$to}. ".($data['notes'] ?? ''));
            $order->statusHistories()->create([
                'from_status' => $order->status,
                'to_status' => $order->status,
                'changed_by_type' => $actor?->getMorphClass(),
                'changed_by_id' => $actor?->getKey(),
                'notes' => mb_substr($notes.($to === 'refunded' ? ' [مرجع الاسترداد: '.($data['refund_reference'] ?? '').']' : ''), 0, 500),
            ]);

            return $order;
        });

        return $this->orderResource($order);
    }

    /**
     * Generates a courier shipment and transitions the order to Shipped.
     *
     * @param CreateShipmentRequest $request Shipment creation request
     * @param Order $order Target order
     * @return ShipmentResource Created shipment resource
     */
    public function createShipment(CreateShipmentRequest $request, Order $order): ShipmentResource
    {
        $this->authorizeOrder($request, $order);
        $shipment = $this->shipments->createForOrder(
            $order,
            (int) $request->validated()['shipping_company_id'],
            $request->validated()['awb_number'] ?? null,
            $request->user(),
        );

        return ShipmentResource::make($shipment);
    }

    /**
     * Allocates order items to branch inventory and reserves stock.
     *
     * @param AllocateOrderRequest $request Allocation request
     * @param Order $order Target order
     * @return AdminOrderResource Updated allocated order resource
     * @throws BusinessException If order status is not eligible for allocation
     */
    public function allocate(AllocateOrderRequest $request, Order $order): AdminOrderResource
    {
        if ($order->allocated_branch_id !== null) {
            $this->authorizeOrder($request, $order);
        } else {
            $this->branchAccess->assertCentralAccess($request->user());
        }
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new BusinessException('التوزيع متاح فقط لطلب مؤكد أو قيد التجهيز.');
        }

        $order = $this->allocation->allocate(
            $order,
            $request->validated()['branch_id'] ?? null,
            (int) $request->user()->id,
        );

        return $this->orderResource($order);
    }

    /**
     * Releases inventory reservations tied to an allocated order and clears allocation state.
     *
     * @param Request $request Deallocation request
     * @param Order $order Target order
     * @return AdminOrderResource Deallocated order resource
     * @throws BusinessException If order status is not eligible for deallocation
     */
    public function deallocate(Request $request, Order $order): AdminOrderResource
    {
        $this->authorizeOrder($request, $order);
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new BusinessException('فك التوزيع متاح فقط لطلب مؤكد أو قيد التجهيز.');
        }

        $order = $this->allocation->deallocate($order, (int) $request->user()->id);

        return $this->orderResource($order);
    }

    private function orderResource(Order $order): AdminOrderResource
    {
        return AdminOrderResource::make(
            $order->loadMissing(['items', 'statusHistories', 'customer', 'city', 'allocatedBranch'])
        );
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        if ($order->allocated_branch_id !== null) {
            $this->branchAccess->assertBranch($request->user()->id, (int) $order->allocated_branch_id);
        } else {
            $this->branchAccess->assertCentralAccess($request->user());
        }
    }
}
