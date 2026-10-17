<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\AllocateOrderRequest;
use App\Http\Requests\Admin\Order\CreateShipmentRequest;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\ShipmentResource;
use App\Models\Order;
use App\Services\OrderAllocationService;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** إدارة الطلبات: عرض وفلترة، تغيير الحالة عبر OrderService، وتوزيع يدوي/مُجبر. */
class OrderController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderAllocationService $allocation,
        private readonly ShipmentService $shipments,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.view', only: ['index', 'show']),
            new Middleware('can:orders.update', only: ['updateStatus', 'createShipment']),
            new Middleware('can:orders.allocate', only: ['allocate']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'allocated_branch_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', Rule::in(['cod', 'card', 'wallet', 'bank_transfer', 'credit'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['customer', 'allocatedBranch'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['allocated_branch_id'] ?? null, fn ($q, $branchId) => $q->where('allocated_branch_id', $branchId))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            // نطاق زمني بدل whereDate حتى يعمل فهرس created_at (M16)،
            // بمنطقة العمل ثم يُخزّن/يُقارن بـ UTC
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=',
                Carbon::parse($date, (string) config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('created_at', '<',
                Carbon::parse($date, (string) config('app.business_timezone'))->startOfDay()->addDay()->utc()))
            // بحث: رقم الطلب والهاتف بالبادئة (يستخدم الفهارس)، والاسم
            // بالاحتواء مع تهريب محارف LIKE من مدخل المستخدم (M16)
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term);
                $inner->where('order_number', 'like', $escaped.'%')
                    ->orWhere('guest_phone', 'like', $escaped.'%')
                    ->orWhere('guest_name', 'like', '%'.$escaped.'%');
            }))
            ->latest('id')
            ->paginate(20);

        return AdminOrderResource::collection($orders);
    }

    public function show(Order $order): AdminOrderResource
    {
        return AdminOrderResource::make(
            $order->load(['items', 'statusHistories', 'customer', 'city', 'allocatedBranch'])
        );
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): AdminOrderResource
    {
        // الشحن له بوليصة ومسار مخزون: من إنشاء البوليصة فقط (L3) —
        // وضع Shipped يدويًا يترك الطلب بلا بوليصة فلا يصله ويب هوك تسليم
        if (($request->validated()['status'] ?? null) === OrderStatus::Shipped->value) {
            throw new BusinessException('حالة الشحن تتم فقط عبر إنشاء بوليصة شحن للطلب.');
        }

        $order = $this->orders->changeStatus(
            $order,
            (string) $request->validated()['status'],
            $request->user(),
            $request->validated()['notes'] ?? null,
        );

        return AdminOrderResource::make(
            $order->load(['items', 'statusHistories', 'customer', 'city', 'allocatedBranch'])
        );
    }

    /** إنشاء بوليصة شحن للطلب وتحويله إلى SHIPPED عبر OrderService. */
    public function createShipment(CreateShipmentRequest $request, Order $order): ShipmentResource
    {
        $shipment = $this->shipments->createForOrder(
            $order,
            (int) $request->validated()['shipping_company_id'],
            $request->validated()['awb_number'] ?? null,
            $request->user(),
        );

        return ShipmentResource::make($shipment);
    }

    public function allocate(AllocateOrderRequest $request, Order $order): AdminOrderResource
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new BusinessException('التوزيع متاح فقط لطلب مؤكد أو قيد التجهيز.');
        }

        $order = $this->allocation->allocate(
            $order,
            $request->validated()['branch_id'] ?? null,
            (int) $request->user()->id,
        );

        return AdminOrderResource::make(
            $order->load(['items', 'statusHistories', 'customer', 'city', 'allocatedBranch'])
        );
    }
}
