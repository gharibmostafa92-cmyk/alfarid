<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Enums\Orders\ShipmentStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReturnService;
use App\Services\Orders\ShipmentService;
use App\Services\Pos\IncomingOrderEvents;
use App\Services\Pos\PosService;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\SearchTerm;
use App\Support\ArabicDigits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PosIncomingOrderController extends Controller
{
    private const ACTIONS = ['pending' => 'confirmed', 'confirmed' => 'processing', 'shipped' => 'delivered'];

    public function __construct(private readonly IncomingOrderEvents $feed) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['cursor' => ['nullable', 'integer', 'min:1']]);
        $branch = $this->branch($request);
        // Read the watermark first. A concurrent commit may appear in both this
        // page and the next feed, but it cannot fall between them.
        $position = $this->feed->position($branch);
        $orders = $this->query($branch)->whereIn('status', IncomingOrderEvents::OPEN_STATUSES);
        $total = (clone $orders)->count();
        $page = $orders->when(isset($data['cursor']), fn ($query) => $query->where('id', '<', $data['cursor']))
            ->latest('id')->limit(51)->get(['id', 'order_number', 'status', 'channel', 'prepared_at']);

        return response()->json([
            'data' => $page->take(50)->map(fn (Order $order) => $this->summary($order))->values(),
            'total' => $total,
            'next_cursor' => $page->count() > 50 ? $page[49]->id : null,
            'position' => $position,
            'catching_up' => $this->feed->hasPending($branch),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => ['required', 'integer', 'min:0']]);
        $after = (int) $data['after'];
        $branch = $this->branch($request);
        $head = $this->feed->position($branch);
        $events = DB::table('pos_order_events')->where('branch_id', $branch)
            ->where('position', '>', $after)->where('position', '<=', $head)
            ->orderBy('position')->limit(101)->get(['position', 'order_id', 'kind']);
        $last = (int) ($events->last()->position ?? $after);
        $reset = $after > $head || $last - $after !== $events->count()
            || ($events->count() <= 100 && $last !== $head);
        $page = $reset ? collect() : $events->take(100);

        return response()->json([
            'data' => $page->values(),
            'position' => $reset ? $head : (int) ($page->last()->position ?? $after),
            'more' => ! $reset && $events->count() > 100,
            'reset' => $reset,
        ]);
    }

    /**
     * Lists completed/closed orders (POS, Store, Wholesale) wholly belonging to the cashier's branch.
     */
    public function completed(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', Rule::in(['all', 'pos', 'store', 'wholesale'])],
            'status' => ['nullable', Rule::in(['delivered', 'returned', 'cancelled'])],
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $branch = $this->branch($request);
        $channel = $filters['channel'] ?? 'all';
        $statuses = isset($filters['status'])
            ? [OrderStatus::from($filters['status'])]
            : [OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Cancelled];
        $rawQuery = trim((string) ($filters['q'] ?? ''));
        $normalizedQuery = ArabicDigits::normalize($rawQuery);

        $query = Order::query()
            ->whereIn('status', $statuses)
            ->whereNull('return_of_order_id')
            ->where(function (Builder $scope) use ($branch, $channel): void {
                $applyPosScope = fn (Builder $pos) => $pos
                    ->where('channel', 'pos')
                    ->where(fn (Builder $b) => $b->where('allocated_branch_id', $branch)
                        ->orWhereHas('posShift', fn (Builder $s) => $s->where('branch_id', $branch)));

                $applyRemoteScope = fn (Builder $remote) => $remote
                    ->whereIn('channel', $channel === 'all' ? ['store', 'wholesale'] : [$channel])
                    ->where(fn (Builder $b) => $b->whereNull('allocated_branch_id')->orWhere('allocated_branch_id', $branch))
                    ->whereHas('items', fn (Builder $items) => $items->where('allocated_branch_id', $branch))
                    ->whereDoesntHave('items', fn (Builder $items) => $items
                        ->whereNull('allocated_branch_id')
                        ->orWhere('allocated_branch_id', '!=', $branch));

                if ($channel === 'pos') {
                    $applyPosScope($scope);
                } elseif (in_array($channel, ['store', 'wholesale'], true)) {
                    $applyRemoteScope($scope);
                } else {
                    $scope->where($applyPosScope)->orWhere($applyRemoteScope);
                }
            })
            ->with(['customer:id,name,phone'])
            ->when($normalizedQuery !== '', function (Builder $q) use ($normalizedQuery): void {
                $pattern = SearchTerm::pattern($normalizedQuery);
                $prefix = SearchTerm::prefix($normalizedQuery);
                $phoneNormalized = Customer::normalizePhone($normalizedQuery);
                $phonePrefix = SearchTerm::prefix($phoneNormalized);

                $q->where(function (Builder $inner) use ($pattern, $prefix, $phoneNormalized, $phonePrefix): void {
                    $inner->whereLike('order_number', $prefix, caseSensitive: false)
                        ->orWhereLike('order_number', $pattern, caseSensitive: false)
                        ->orWhereLike('guest_name', $pattern, caseSensitive: false)
                        ->orWhere('guest_phone', 'like', $prefix)
                        ->orWhere('guest_phone_normalized', 'like', $prefix)
                        ->orWhereHas('customer', function (Builder $cq) use ($pattern, $prefix, $phoneNormalized, $phonePrefix): void {
                            $cq->whereLike('name', $pattern, caseSensitive: false)
                                ->orWhere('phone', 'like', $prefix);
                            if ($phoneNormalized !== '' && $phonePrefix !== $prefix) {
                                $cq->orWhere('phone', 'like', $phonePrefix);
                            }
                        })
                        ->orWhereExists(function ($sub) use ($pattern, $prefix): void {
                            $sub->selectRaw('1')
                                ->from('order_items')
                                ->whereColumn('order_items.order_id', 'orders.id')
                                ->where(function ($itemsQ) use ($pattern, $prefix): void {
                                    $itemsQ->whereLike('order_items.sku_snapshot', $prefix, caseSensitive: false)
                                        ->orWhereLike('order_items.product_name_snapshot', $pattern, caseSensitive: false);
                                });
                        });
                    if ($phoneNormalized !== '' && $phonePrefix !== $prefix) {
                        $inner->orWhere('guest_phone', 'like', $phonePrefix)
                            ->orWhere('guest_phone_normalized', 'like', $phonePrefix);
                    }
                });
            })
            ->when(isset($filters['cursor']), fn (Builder $q) => $q->where('id', '<', (int) $filters['cursor']))
            ->orderByDesc('id')
            ->limit(31);

        $rows = $query->get();
        $hasMore = $rows->count() > 30;
        $page = $rows->take(30);

        $itemStats = $page->isEmpty()
            ? collect()
            : DB::table('order_items')
                ->whereIn('order_id', $page->pluck('id')->all())
                ->groupBy('order_id')
                ->selectRaw('order_id, COUNT(*) AS items_count, COALESCE(SUM(qty), 0) AS total_qty, COALESCE(SUM(GREATEST(qty - returned_qty, 0)), 0) AS returnable_qty')
                ->get()
                ->keyBy('order_id');

        $data = $page->map(function (Order $order) use ($itemStats): array {
            $stats = $itemStats->get($order->id);
            $totalQty = (float) ($stats->total_qty ?? 0);
            $returnableQty = (float) ($stats->returnable_qty ?? 0);

            return [
                'id' => $order->id,
                'number' => $order->order_number,
                'order_number' => $order->order_number,
                'channel' => $order->channel,
                'status' => $order->status->value,
                'prepared_at' => $order->prepared_at,
                'created_at' => $order->created_at,
                'delivered_at' => $order->delivered_at ?? ($order->status === OrderStatus::Delivered ? $order->updated_at : null),
                'grand_total' => number_format((float) $order->grand_total, 2, '.', ''),
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'customer_name' => $order->guest_name ?? $order->customer?->name,
                'customer_phone' => $order->guest_phone ?? $order->customer?->phone,
                'items_count' => (int) ($stats->items_count ?? 0),
                'total_qty' => floor($totalQty) === $totalQty ? (int) $totalQty : $totalQty,
                'returnable_qty' => floor($returnableQty) === $returnableQty ? (int) $returnableQty : $returnableQty,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'next_cursor' => $hasMore ? $page->last()?->id : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $branch = $this->branch($request);
        $order = $this->query($branch)
            ->with([
                'shipments' => fn ($q) => $q->latest('id')->with('company:id,name,code,tracking_url_template'),
                'returnRequests' => fn ($q) => $q->latest('id'),
            ])
            ->findOrFail($id);
        $items = $order->items()
            ->where('allocated_branch_id', $branch)
            ->with('variant:id,sku,barcode,color_name_ar,color_name_en')
            ->get(['id', 'product_variant_id', 'product_name_snapshot', 'sku_snapshot', 'qty', 'returned_qty']);
        $ownsAllItems = $this->ownsAllItems($order, $branch);
        $actor = $request->user('staff');
        $isOpen = in_array($order->status->value, ['pending', 'confirmed', 'processing'], true);
        $hasActiveShipment = $order->shipments->contains(
            fn (Shipment $s): bool => ! in_array($s->status, [ShipmentStatus::Failed, ShipmentStatus::Returned], true)
        );

        $reservedByItem = $items->isEmpty()
            ? collect()
            : ReturnRequestItem::query()
                ->whereIn('order_item_id', $items->pluck('id')->all())
                ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
                ->whereNotIn('return_requests.status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value])
                ->selectRaw("order_item_id, COALESCE(SUM(CASE WHEN return_requests.status IN ('received', 'refunded') THEN received_good_qty + received_damaged_qty WHEN return_requests.status = 'approved' THEN approved_qty ELSE requested_qty END), 0) AS total")
                ->groupBy('order_item_id')
                ->pluck('total', 'order_item_id');

        $mappedItems = $items->map(function (OrderItem $item) use ($reservedByItem): array {
            $qty = (float) $item->qty;
            $returnedOrReserved = max((float) $item->returned_qty, (float) ($reservedByItem[$item->id] ?? 0));
            $returnableQty = max(0.0, round($qty - $returnedOrReserved, 4));

            return [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'name' => $item->product_name_snapshot,
                'variant_name' => $item->variant?->color_name_ar ?? $item->variant?->color_name_en,
                'sku' => $item->sku_snapshot,
                'barcode' => $item->variant?->barcode,
                'qty' => $item->qty,
                'returned_qty' => $item->returned_qty,
                'returnable_qty' => floor($returnableQty) === $returnableQty ? (int) $returnableQty : $returnableQty,
            ];
        });
        $totalReturnable = $mappedItems->sum('returnable_qty');

        return response()->json(['data' => [
            ...$this->summary($order),
            'created_at' => $order->created_at,
            'prepared_at' => $order->prepared_at,
            'grand_total' => $ownsAllItems ? number_format((float) $order->grand_total, 2, '.', '') : null,
            'delivery' => $ownsAllItems ? [
                'name' => $order->guest_name ?? $order->customer?->name,
                'phone' => $order->guest_phone ?? $order->customer?->phone,
                'city' => $order->city?->name_ar,
                'address' => $order->shipping_address_line,
                'notes' => $order->shipping_notes,
            ] : null,
            'payment_method' => $ownsAllItems ? $order->payment_method : null,
            'payment_status' => $ownsAllItems ? $order->payment_status : null,
            'items' => $mappedItems->values(),
            'shipments' => $ownsAllItems ? $order->shipments->map(fn (Shipment $s): array => [
                'id' => $s->id,
                'awb_number' => $s->awb_number,
                'status' => $s->status->value,
                'company_name' => $s->company?->name,
                'shipping_cost' => number_format((float) $s->shipping_cost, 2, '.', ''),
                'cod_amount' => number_format((float) $s->cod_amount, 2, '.', ''),
                'tracking_url' => $s->trackingUrl(),
                'created_at' => $s->created_at,
            ])->values()->all() : [],
            'returns' => $ownsAllItems ? $order->returnRequests->map(fn (ReturnRequest $r): array => [
                'id' => $r->id,
                'return_number' => $r->return_number,
                'status' => $r->status->value,
                'total_refund_amount' => number_format((float) $r->total_refund_amount, 2, '.', ''),
                'created_at' => $r->created_at,
            ])->values()->all() : [],
            'next_status' => $actor->can('orders.update') && $ownsAllItems
                ? (self::ACTIONS[$order->status->value] ?? null) : null,
            'can_prepare' => $ownsAllItems && $actor->can('orders.update') && $isOpen && $order->prepared_at === null,
            'can_ship' => $ownsAllItems
                && $actor->can('orders.update')
                && in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)
                && ! $hasActiveShipment
                && ! $order->requires_refund,
            'can_cancel' => $ownsAllItems && $actor->can('pos.orders.cancel') && $isOpen,
            'can_return' => $ownsAllItems
                && $order->status === OrderStatus::Delivered
                && ($actor->can('pos.sales.refund') || $actor->can('returns.create'))
                && $totalReturnable > 0,
        ]]);
    }

    public function shippingCompanies(Request $request): JsonResponse
    {
        $this->branch($request);

        return response()->json([
            'data' => ShippingCompany::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function createShipment(
        Request $request,
        string $id,
        ShipmentService $shipments,
        OperationIdempotencyService $idempotency,
    ): JsonResponse {
        $data = $request->validate([
            'shipping_company_id' => ['required', 'integer', 'min:1'],
            'awb_number' => ['nullable', 'string', 'max:100'],
        ]);

        $branch = $this->branch($request);
        /** @var User $actor */
        $actor = $request->user('staff');
        $order = $this->query($branch)->findOrFail($id);
        $awb = isset($data['awb_number']) && trim($data['awb_number']) !== ''
            ? trim($data['awb_number'])
            : null;
        $replayed = false;

        try {
            /** @var Shipment $shipment */
            $shipment = $idempotency->run(
                'pos_incoming_order_shipment',
                $actor,
                $this->requiredIdempotencyKey($request),
                [
                    'order_id' => $order->id,
                    'branch_id' => $branch,
                    'shipping_company_id' => (int) $data['shipping_company_id'],
                    'awb_number' => $awb,
                ],
                function () use ($order, $branch, $actor, $data, $awb, $shipments): Shipment {
                    $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                    abort_unless($this->query($branch)->whereKey($locked->id)->exists() && $this->ownsAllItems($locked, $branch), 404);

                    $created = $shipments->createForOrder($locked, (int) $data['shipping_company_id'], $awb, $actor);
                    PosService::invalidateCatalogVersionCache($branch);
                    $this->auditOrderAction($actor, $locked, 'pos_order.shipment_create', [
                        'branch_id' => $branch,
                        'shipment_id' => $created->id,
                        'awb_number' => $created->awb_number,
                    ]);

                    return $created;
                },
                $replayed,
            );
        } catch (QueryException $exception) {
            if (str_contains($exception->errorInfo[2] ?? '', 'shipments_awb_number_unique')) {
                throw new BusinessException('رقم البوليصة مستخدم بالفعل لطلب آخر.', [], 422);
            }
            throw $exception;
        }

        $shipment->loadMissing('company:id,name,code,tracking_url_template');

        return response()->json([
            'message' => 'تم إصدار بوليصة الشحن بنجاح.',
            'data' => [
                'id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'awb_number' => $shipment->awb_number,
                'status' => $shipment->status->value,
                'company_name' => $shipment->company?->name,
                'tracking_url' => $shipment->trackingUrl(),
            ],
        ], $replayed ? 200 : 201);
    }

    public function update(Request $request, string $id, OrderService $orders, OperationIdempotencyService $idempotency): JsonResponse
    {
        $data = $request->validate([
            'expected_status' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'status' => ['required', Rule::in(array_values(self::ACTIONS))],
        ]);
        abort_unless(self::ACTIONS[$data['expected_status']] === $data['status'], 422);
        $branch = $this->branch($request);
        $actor = $request->user('staff');
        $order = $this->query($branch)->findOrFail($id);
        $result = $idempotency->run('pos_incoming_order', $actor, $this->requiredIdempotencyKey($request), [
            ...$data, 'order_id' => $order->id, 'branch_id' => $branch,
        ], function () use ($order, $actor, $branch, $data, $orders): Order {
            if ($order->customer_id !== null && $order->payment_method === 'credit') {
                Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
            }
            // Allocation and status changes serialize on this row.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($this->query($branch)->whereKey($locked->id)->exists() && $this->ownsAllItems($locked, $branch), 404);
            if ($locked->status->value !== $data['expected_status']) {
                throw new BusinessException('تغيّرت حالة الطلب.', ['status' => $locked->status->value], 409, 'order_status_changed');
            }

            return $orders->changeStatus($locked, $data['status'], $actor, expectedFrom: [OrderStatus::from($data['expected_status'])]);
        });

        // A replay may race a reassignment since the original command.
        $visible = $this->query($branch)->findOrFail($result->getKey());

        return response()->json(['data' => $this->summary($visible)]);
    }

    public function cancel(Request $request, string $id, OrderService $orders, OperationIdempotencyService $idempotency): JsonResponse
    {
        $data = $request->validate([
            'expected_status' => ['required', Rule::in(['pending', 'confirmed', 'processing'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $branch = $this->branch($request);
        /** @var User $actor */
        $actor = $request->user('staff');
        $reason = trim($data['reason']);
        $order = $this->query($branch)->findOrFail($id);

        $result = $idempotency->run('pos_incoming_order_cancel', $actor, $this->requiredIdempotencyKey($request), [
            'order_id' => $order->id,
            'branch_id' => $branch,
            'expected_status' => $data['expected_status'],
            'reason' => $reason,
        ], function () use ($order, $actor, $branch, $data, $reason, $orders): Order {
            if ($order->customer_id !== null && $order->payment_method === 'credit') {
                Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
            }

            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($this->query($branch)->whereKey($locked->id)->exists() && $this->ownsAllItems($locked, $branch), 404);

            if (! in_array($locked->status, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                throw new BusinessException('لا يمكن إلغاء الطلب في حالته الحالية.');
            }
            if ($locked->status->value !== $data['expected_status']) {
                throw new BusinessException('تغيّرت حالة الطلب.', ['status' => $locked->status->value], 409, 'order_status_changed');
            }
            if ($locked->shipments()->exists()) {
                throw new BusinessException('لا يمكن إلغاء طلب صدرت له بوليصة شحن.');
            }

            $fromStatus = $locked->status->value;
            $updated = $orders->changeStatus(
                $locked,
                OrderStatus::Cancelled,
                $actor,
                'إلغاء من نقطة البيع: '.$reason,
                expectedFrom: [OrderStatus::from($data['expected_status'])],
            );

            $this->auditOrderAction($actor, $updated, 'pos_order.cancel', [
                'branch_id' => $branch,
                'from_status' => $fromStatus,
                'reason' => $reason,
            ]);
            PosService::invalidateCatalogVersionCache($branch);

            return $updated;
        });

        $visible = $this->query($branch)->findOrFail($result->getKey());

        return response()->json(['data' => $this->summary($visible)]);
    }

    /**
     * Creates, approves, and physically receives a branch return for a delivered store/wholesale order
     * without touching the POS cash drawer (financial payout remains with Backoffice Finance).
     */
    public function createReturn(Request $request, string $id, ReturnService $returns, OperationIdempotencyService $idempotency): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'reason_type' => ['nullable', Rule::in(['change_of_mind', 'defect_or_mismatch'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.return_to' => ['nullable', Rule::in(['on_hand', 'damaged'])],
        ]);

        $branch = $this->branch($request);
        /** @var User $actor */
        $actor = $request->user('staff');
        $order = $this->query($branch)->findOrFail($id);
        $reason = trim((string) ($data['reason'] ?? '')) ?: 'مرتجع فرع من نقطة البيع';
        $reasonType = $data['reason_type'] ?? 'change_of_mind';
        $replayed = false;

        /** @var ReturnRequest $returnRequest */
        $returnRequest = $idempotency->run(
            'pos_incoming_order_return',
            $actor,
            $this->requiredIdempotencyKey($request),
            ['order_id' => $order->id, 'branch_id' => $branch, 'reason' => $reason, 'reason_type' => $reasonType, 'items' => $data['items']],
            function () use ($order, $branch, $actor, $reason, $reasonType, $data, $returns): ReturnRequest {
                $locked = Order::query()->lockForUpdate()->with('items')->findOrFail($order->id);
                abort_unless($this->query($branch)->whereKey($locked->id)->exists() && $this->ownsAllItems($locked, $branch), 404);

                if ($locked->status !== OrderStatus::Delivered) {
                    throw new BusinessException('يمكن إرجاع الطلبات المسلّمة فقط.');
                }

                $returnToByOrderItem = [];
                foreach ($data['items'] as $line) {
                    $returnToByOrderItem[(int) $line['order_item_id']] = $line['return_to'] ?? 'on_hand';
                }

                $created = $returns->createReturnRequest($locked, [
                    'reason' => $reason,
                    'reason_type' => $reasonType,
                    'items' => array_map(fn (array $line): array => [
                        'order_item_id' => (int) $line['order_item_id'],
                        'requested_qty' => (int) $line['qty'],
                    ], $data['items']),
                ]);

                $approved = $returns->approve($created, $actor, [
                    'branch_id' => $branch,
                    'admin_notes' => 'اعتماد واستلام مرتجع فرع عبر نقطة البيع',
                ]);

                $receivePayload = $approved->items->map(function (ReturnRequestItem $item) use ($returnToByOrderItem): array {
                    $qty = (int) round((float) $item->approved_qty);
                    $isDamaged = ($returnToByOrderItem[(int) $item->order_item_id] ?? 'on_hand') === 'damaged';

                    return [
                        'id' => $item->id,
                        'received_good_qty' => $isDamaged ? 0 : $qty,
                        'received_damaged_qty' => $isDamaged ? $qty : 0,
                    ];
                })->values()->all();

                $received = $returns->receive($approved, $actor, ['items' => $receivePayload]);

                PosService::invalidateCatalogVersionCache($branch);
                $this->auditOrderAction($actor, $locked, 'pos_order.return_receive', [
                    'branch_id' => $branch,
                    'return_id' => $received->id,
                    'return_number' => $received->return_number,
                    'total_refund_amount' => (float) $received->total_refund_amount,
                ]);

                return $received;
            },
            $replayed,
        );

        return response()->json([
            'message' => 'تم استلام المرتجع في مخزون الفرع وتسجيله للمراجعة المالية.',
            'data' => [
                'return_id' => $returnRequest->id,
                'return_number' => $returnRequest->return_number,
                'status' => $returnRequest->status->value,
                'total_refund_amount' => (float) $returnRequest->total_refund_amount,
            ],
        ], $replayed ? 200 : 201);
    }

    public function prepare(Request $request, string $id, OrderService $orders, OperationIdempotencyService $idempotency): JsonResponse
    {
        $data = $request->validate([
            'expected_status' => ['required', Rule::in(['pending', 'confirmed', 'processing'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);
        $branch = $this->branch($request);
        /** @var User $actor */
        $actor = $request->user('staff');
        $order = $this->query($branch)->findOrFail($id);

        $result = $idempotency->run('pos_incoming_order_prepare', $actor, $this->requiredIdempotencyKey($request), [
            'order_id' => $order->id,
            'branch_id' => $branch,
            ...$data,
        ], function () use ($order, $actor, $branch, $data, $orders): Order {
            if ($order->customer_id !== null && $order->payment_method === 'credit') {
                Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
            }

            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($this->query($branch)->whereKey($locked->id)->exists() && $this->ownsAllItems($locked, $branch), 404);

            if (! in_array($locked->status, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing], true)) {
                throw new BusinessException('يمكن تجهيز الطلبات المفتوحة فقط.');
            }
            if ($locked->status->value !== $data['expected_status']) {
                throw new BusinessException('تغيّرت حالة الطلب.', ['status' => $locked->status->value], 409, 'order_status_changed');
            }
            if ($locked->prepared_at !== null) {
                throw new BusinessException('تم تجهيز هذا الطلب مسبقًا.', [], 409);
            }

            $expectedByVariant = $locked->items()
                ->where('allocated_branch_id', $branch)
                ->get(['product_variant_id', 'qty'])
                ->groupBy('product_variant_id')
                ->map(fn ($rows): int => (int) round((float) $rows->sum('qty')));

            $submittedByVariant = [];
            foreach ($data['items'] as $line) {
                $submittedByVariant[(int) $line['product_variant_id']] = (int) $line['qty'];
            }

            if (count($expectedByVariant) !== count($submittedByVariant)) {
                throw new BusinessException('الأصناف أو الكميات المجهزة لا تطابق بنود الطلب الأصلي.');
            }
            foreach ($expectedByVariant as $variantId => $expectedQty) {
                if (($submittedByVariant[(int) $variantId] ?? null) !== $expectedQty) {
                    throw new BusinessException('الأصناف أو الكميات المجهزة لا تطابق بنود الطلب الأصلي.');
                }
            }

            if ($locked->status === OrderStatus::Pending) {
                $locked = $orders->changeStatus($locked, OrderStatus::Confirmed, $actor, 'تأكيد تلقائي عند تجهيز السلة', [OrderStatus::Pending]);
                $locked = $orders->changeStatus($locked, OrderStatus::Processing, $actor, 'تم تجهيز ومطابقة أصناف الطلب في سلة نقطة البيع', [OrderStatus::Confirmed]);
            } elseif ($locked->status === OrderStatus::Confirmed) {
                $locked = $orders->changeStatus($locked, OrderStatus::Processing, $actor, 'تم تجهيز ومطابقة أصناف الطلب في سلة نقطة البيع', [OrderStatus::Confirmed]);
            } else {
                $locked->statusHistories()->create([
                    'from_status' => OrderStatus::Processing,
                    'to_status' => OrderStatus::Processing,
                    'changed_by_type' => $actor->getMorphClass(),
                    'changed_by_id' => $actor->getKey(),
                    'notes' => 'تم تجهيز ومطابقة أصناف الطلب في سلة نقطة البيع',
                ]);
            }

            $locked->update([
                'prepared_at' => now(),
                'prepared_by' => $actor->id,
            ]);

            $fresh = $locked->fresh(['items']);
            $this->feed->publish($fresh, 'updated');
            $this->auditOrderAction($actor, $fresh, 'pos_order.prepare', ['branch_id' => $branch]);

            return $fresh;
        });

        $visible = $this->query($branch)->findOrFail($result->getKey());

        return response()->json(['data' => $this->summary($visible)]);
    }

    private function branch(Request $request): int
    {
        $branch = (int) $request->attributes->get('pos_shift')->branch_id;
        abort_unless(Branch::query()->whereKey($branch)->where('is_active', true)->exists(), 403);

        return $branch;
    }

    private function query(int $branch): Builder
    {
        return Order::query()->whereIn('channel', ['store', 'wholesale'])->allocatedToBranch($branch);
    }

    private function ownsAllItems(Order $order, int $branch): bool
    {
        return ($order->allocated_branch_id === null || (int) $order->allocated_branch_id === $branch)
            && $order->items()->where('allocated_branch_id', $branch)->exists()
            && ! $order->items()->where(fn (Builder $items) => $items->whereNull('allocated_branch_id')
                ->orWhere('allocated_branch_id', '!=', $branch))->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function auditOrderAction(User $actor, Order $order, string $action, array $metadata): void
    {
        DB::table('staff_audit_logs')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => Order::class,
            'target_id' => $order->id,
            'metadata' => json_encode($metadata + [
                'order_number' => $order->order_number,
                'ip' => request()->ip(),
                'method' => request()->method(),
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(),
        ]);
    }

    private function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->order_number,
            'channel' => $order->channel,
            'status' => $order->status->value,
            'prepared_at' => $order->prepared_at,
        ];
    }
}
