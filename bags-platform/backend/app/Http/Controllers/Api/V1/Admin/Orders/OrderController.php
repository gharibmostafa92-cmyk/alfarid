<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Orders;

use App\Enums\Orders\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Orders\AllocateOrderRequest;
use App\Http\Requests\Admin\Orders\CreateShipmentRequest;
use App\Http\Requests\Admin\Orders\UpdateOrderPaymentRequest;
use App\Http\Requests\Admin\Orders\UpdateOrderStatusRequest;
use App\Http\Resources\Admin\Orders\AdminOrderResource;
use App\Http\Resources\Admin\Orders\ShipmentResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Inventory\BranchAccess;
use App\Services\Orders\OrderAllocationService;
use App\Services\Orders\OrderService;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\SearchTerm;
use App\Services\Orders\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        'partially_refunded' => ['refunded'],
        'refund_pending' => [],
        'refunded' => [],
    ];

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderAllocationService $allocation,
        private readonly ShipmentService $shipments,
        private readonly BranchAccess $branchAccess,
        private readonly OperationIdempotencyService $idempotency,
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
     * @param  Request  $request  Filter query request
     * @return AnonymousResourceCollection Paginated order resources
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'allocated_branch_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', Rule::in(['cod', 'card', 'wallet', 'bank_transfer', 'credit'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'needs_attention' => ['nullable', 'boolean'],
            'requires_refund' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branchAccess->restrictedBranch($request->user());
        if ($branch !== null && isset($filters['allocated_branch_id']) && (int) $filters['allocated_branch_id'] !== $branch) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }

        $orders = Order::query()
            ->when($branch ?? $filters['allocated_branch_id'] ?? null, fn ($q, $branchId) => $q->allocatedToBranch((int) $branchId))
            ->with(['customer', 'allocatedBranch'])
            ->withCount('items')
            ->when($filters['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when(array_key_exists('needs_attention', $filters) && $filters['needs_attention'] !== null,
                fn ($q) => $q->where('needs_attention', (bool) $filters['needs_attention']))
            ->when(array_key_exists('requires_refund', $filters) && $filters['requires_refund'] !== null,
                fn ($q) => $q->where('requires_refund', (bool) $filters['requires_refund']))
            // Range comparison leverages created_at index while converting business timezone to UTC.
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=',
                $this->businessDayStart((string) $date)))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('created_at', '<',
                $this->businessDayStart((string) $date, daysAfter: 1)))
            // Route numeric queries to indexed prefix lookups and text queries to trigram name searches.
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $prefix = SearchTerm::prefix((string) $term);
                $normalizedPhone = Customer::normalizePhone((string) $term);
                $phonePrefix = SearchTerm::prefix($normalizedPhone);
                $numeric = preg_match('/^\s*[0-9+\-\s()]+\s*$/', (string) $term) === 1;
                $inner->where('order_number', 'like', $prefix)
                    ->orWhere('guest_phone', 'like', $prefix)
                    ->orWhere('guest_phone_normalized', 'like', $prefix);
                if ($phonePrefix !== $prefix) {
                    $inner->orWhere('guest_phone_normalized', 'like', $phonePrefix)
                        ->orWhereIn('customer_id', Customer::query()->select('id')->where('phone', 'like', $phonePrefix));
                }
                if (! $numeric) {
                    $pattern = SearchTerm::pattern((string) $term);
                    $inner->orWhere('guest_name', 'ilike', $pattern)
                        ->orWhereIn('customer_id', Customer::query()->select('id')->where('name', 'ilike', $pattern));
                }
            }))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return AdminOrderResource::collection($orders);
    }

    /**
     * Aggregates order queue counts in a single query.
     *
     * @param  Request  $request  Authenticated admin request
     * @return JsonResponse Aggregated queue statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $branch = $this->branchAccess->restrictedBranch($request->user());

        $row = Order::query()
            ->when($branch !== null, fn ($q) => $q->allocatedToBranch($branch))
            ->where(static function ($q): void {
                $q->whereIn('status', ['pending', 'processing'])
                    ->orWhere('needs_attention', true)
                    ->orWhere('requires_refund', true);
            })
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
     * @param  Request  $request  Incoming request
     * @param  Order  $order  Target order model
     * @return AdminOrderResource Order resource
     */
    public function show(Request $request, Order $order): AdminOrderResource
    {
        $branch = $this->branchAccess->restrictedBranch($request->user());
        if ($branch !== null && ! Order::query()->whereKey($order->id)->allocatedToBranch($branch)->exists()) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الطلب.');
        }

        return $this->orderResource($order);
    }

    /**
     * Transitions an order to a new operational status.
     *
     * @param  UpdateOrderStatusRequest  $request  Validated status request
     * @param  Order  $order  Target order
     * @return AdminOrderResource Updated order resource
     *
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
     * @param  UpdateOrderPaymentRequest  $request  Payment status request
     * @param  Order  $order  Target order
     * @return AdminOrderResource Updated order resource
     *
     * @throws BusinessException If transition is invalid or refund reference is missing
     */
    public function updatePayment(UpdateOrderPaymentRequest $request, Order $order): AdminOrderResource
    {
        $data = $request->validated();
        $to = (string) $data['payment_status'];

        $execute = fn (): Order => DB::transaction(function () use ($order, $to, $data, $request): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorizeOrder($request, $order);
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
                if (in_array($order->payment_method, ['wallet', 'credit'], true) || $order->channel === 'pos') {
                    throw new BusinessException('استرداد طلبات المحفظة أو الآجل أو نقطة البيع يتم عبر مسار الإلغاء أو المرتجعات لتسجيل القيد المحاسبي.');
                }
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
            $reference = $to === 'refunded' ? ' [مرجع الاسترداد: '.$data['refund_reference'].']' : '';
            $order->statusHistories()->create([
                'from_status' => $order->status,
                'to_status' => $order->status,
                'changed_by_type' => $actor?->getMorphClass(),
                'changed_by_id' => $actor?->getKey(),
                'notes' => mb_substr($notes, 0, max(0, 500 - mb_strlen($reference))).$reference,
            ]);

            return $order;
        });
        $idempotencyKey = $request->header('Idempotency-Key');
        $order = $idempotencyKey !== null
            ? $this->idempotency->run(
                'admin_order_payment_update',
                $request->user(),
                $idempotencyKey,
                ['order_id' => $order->id, ...$data],
                $execute,
            )
            : $execute();

        return $this->orderResource($order);
    }

    /**
     * Generates a courier shipment and transitions the order to Shipped.
     *
     * @param  CreateShipmentRequest  $request  Shipment creation request
     * @param  Order  $order  Target order
     * @return ShipmentResource Created shipment resource
     */
    public function createShipment(CreateShipmentRequest $request, Order $order): ShipmentResource
    {
        $this->authorizeOrder($request, $order);
        $validated = $request->validated();
        $idempotencyKey = $request->header('Idempotency-Key');
        try {
            $run = fn () => $this->shipments->createForOrder(
                $order,
                (int) $validated['shipping_company_id'],
                $validated['awb_number'] ?? null,
                $request->user(),
            );
            $shipment = $idempotencyKey !== null
                ? $this->idempotency->run(
                    'admin_order_shipment',
                    $request->user(),
                    $idempotencyKey,
                    ['order_id' => $order->id, ...$validated],
                    $run,
                )
                : $run();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->errorInfo[2] ?? '', '"shipments_awb_number_unique"')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'awb_number' => __('validation.unique', ['attribute' => 'awb number']),
            ]);
        }

        return ShipmentResource::make($shipment);
    }

    /**
     * Allocates order items to branch inventory and reserves stock.
     *
     * @param  AllocateOrderRequest  $request  Allocation request
     * @param  Order  $order  Target order
     * @return AdminOrderResource Updated allocated order resource
     *
     * @throws BusinessException If order status is not eligible for allocation
     */
    public function allocate(AllocateOrderRequest $request, Order $order): AdminOrderResource
    {
        $data = $request->validated();
        $execute = fn (): Order => DB::transaction(function () use ($request, $order, $data): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->allocated_branch_id !== null || $this->allocation->isAllocated($locked)) {
                $this->authorizeOrder($request, $locked);
            } else {
                $this->branchAccess->assertCentralAccess($request->user());
            }
            if (! in_array($locked->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                throw new BusinessException('التوزيع متاح فقط لطلب مؤكد أو قيد التجهيز.');
            }

            return $this->allocation->allocate(
                $locked,
                $data['branch_id'] ?? null,
                (int) $request->user()->id,
            );
        });
        $idempotencyKey = $request->header('Idempotency-Key');
        $order = $idempotencyKey !== null
            ? $this->idempotency->run(
                'admin_order_allocate',
                $request->user(),
                $idempotencyKey,
                ['order_id' => $order->id, ...$data],
                $execute,
            )
            : $execute();

        return $this->orderResource($order);
    }

    /**
     * Releases inventory reservations tied to an allocated order and clears allocation state.
     *
     * @param  Request  $request  Deallocation request
     * @param  Order  $order  Target order
     * @return AdminOrderResource Deallocated order resource
     *
     * @throws BusinessException If order status is not eligible for deallocation
     */
    public function deallocate(Request $request, Order $order): AdminOrderResource
    {
        $execute = fn (): Order => DB::transaction(function () use ($request, $order): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorizeOrder($request, $locked);
            if (! in_array($locked->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                throw new BusinessException('فك التوزيع متاح فقط لطلب مؤكد أو قيد التجهيز.');
            }

            return $this->allocation->deallocate($locked, (int) $request->user()->id);
        });
        $idempotencyKey = $request->header('Idempotency-Key');
        $order = $idempotencyKey !== null
            ? $this->idempotency->run(
                'admin_order_deallocate',
                $request->user(),
                $idempotencyKey,
                ['order_id' => $order->id],
                $execute,
            )
            : $execute();

        return $this->orderResource($order);
    }

    private function orderResource(Order $order): AdminOrderResource
    {
        return AdminOrderResource::make(
            $order->loadMissing(['items', 'statusHistories', 'customer', 'city', 'allocatedBranch'])
        );
    }

    private function businessDayStart(string $date, int $daysAfter = 0): Carbon
    {
        $start = Carbon::createFromFormat('!Y-m-d', $date, $this->businessTimezone());
        if ($start === false) {
            throw new \InvalidArgumentException('Expected an ISO calendar date.');
        }

        return $start->addDays($daysAfter)->utc();
    }

    private function businessTimezone(): string
    {
        $fallback = config('app.timezone', 'UTC');
        $timezone = config('app.business_timezone', $fallback);
        if (! is_string($timezone) || $timezone === '') {
            return is_string($fallback) && $fallback !== '' ? $fallback : 'UTC';
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            return is_string($fallback) && $fallback !== '' ? $fallback : 'UTC';
        }

        return $timezone;
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
