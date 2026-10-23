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
use App\Services\OrderAllocationService;
use App\Services\OrderService;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** إدارة الطلبات: عرض وفلترة، تغيير الحالة عبر OrderService، وتوزيع يدوي/مُجبر. */
class OrderController extends Controller implements HasMiddleware
{
    /**
     * آلة انتقالات حالة الدفع (H4): تمنع الحالات المستحيلة مثل refunded→unpaid
     * وpaid→failed ووضع paid على طلب ملغى بلا التزام استرداد.
     */
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
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.view', only: ['index', 'show']),
            new Middleware('can:orders.update', only: ['updateStatus', 'createShipment']),
            // صلاحية مالية منفصلة (H4): تغيير الحالة التشغيلية لا يمنح تعديل الدفع
            new Middleware('can:orders.payment', only: ['updatePayment']),
            new Middleware('can:orders.allocate', only: ['allocate', 'deallocate']),
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
            // تظهر العلامتان في المورد بلا شاشة تصفية (F-29): تُتاح الآن كفلتر
            'needs_attention' => ['nullable', 'boolean'],
            'requires_refund' => ['nullable', 'boolean'],
        ]);

        $orders = Order::query()
            ->with(['customer', 'allocatedBranch'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['allocated_branch_id'] ?? null, fn ($q, $branchId) => $q->where('allocated_branch_id', $branchId))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when(array_key_exists('needs_attention', $filters) && $filters['needs_attention'] !== null,
                fn ($q) => $q->where('needs_attention', (bool) $filters['needs_attention']))
            ->when(array_key_exists('requires_refund', $filters) && $filters['requires_refund'] !== null,
                fn ($q) => $q->where('requires_refund', (bool) $filters['requires_refund']))
            // نطاق زمني بدل whereDate حتى يعمل فهرس created_at (M16)،
            // بمنطقة العمل ثم يُخزّن/يُقارن بـ UTC
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=',
                Carbon::parse($date, (string) config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('created_at', '<',
                Carbon::parse($date, (string) config('app.business_timezone'))->startOfDay()->addDay()->utc()))
            // بحث مُقسَّم حسب شكل المدخل (F11): الرقمي على رقم الطلب/الهاتف
            // بالبادئة (فهرسي text_pattern_ops)، والنصي يضيف الاسم بالاحتواء
            // عبر فهرس pg_trgm — فلا يُشغَّل فحص الاسم على مدخل رقمي،
            // وتهريب محارف LIKE يمنع حقن الأنماط (M16)
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term);
                $numeric = preg_match('/^\s*[0-9+\-\s()]+\s*$/', (string) $term) === 1;
                $inner->where('order_number', 'like', $escaped.'%')
                    ->orWhere('guest_phone', 'like', $escaped.'%')
                    ->orWhere('guest_phone_normalized', 'like', $escaped.'%');
                if (! $numeric) {
                    $inner->orWhere('guest_name', 'ilike', '%'.$escaped.'%');
                }
            }))
            ->latest('id')
            ->paginate(20);

        return AdminOrderResource::collection($orders);
    }

    public function show(Order $order): AdminOrderResource
    {
        return $this->orderResource($order);
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

        return $this->orderResource($order);
    }

    /**
     * تحديث حالة الدفع يدويًا (F6): مسار إداري صريح بدل الترقيع عبر تغيير
     * الحالة. يُسجّل التغيير في سجل الطلب بالمنفّذ والملاحظة للتدقيق.
     */
    public function updatePayment(UpdateOrderPaymentRequest $request, Order $order): AdminOrderResource
    {
        $data = $request->validated();
        $to = (string) $data['payment_status'];

        // قفل صف + transaction (H4): سباق مع ويب هوك الدفع كان يضيّع تحديث
        // أحدهما (lost update)، وآلة انتقالات تمنع الحالات المستحيلة.
        $order = DB::transaction(function () use ($order, $to, $data, $request): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->payment_status;

            if ($from === $to) {
                return $order;
            }
            if (! in_array($to, self::PAYMENT_TRANSITIONS[$from] ?? [], true)) {
                throw new BusinessException("انتقال حالة دفع غير مسموح: من ({$from}) إلى ({$to}).");
            }

            // حالة الاسترداد تتبع حالة الطلب لا القيمة القادمة (H4): دفع على
            // طلب ملغى/مرتجع يستلزم استردادًا، والاسترداد يُسقط الالتزام.
            // ضبط requires_refund مباشرة من $to كان يمحو الالتزام بصمت.
            $requiresRefund = $to === 'paid'
                && in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true);
            if ($to === 'refunded') {
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
                'notes' => mb_substr($notes, 0, 500),
            ]);

            return $order;
        });

        return $this->orderResource($order);
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

        return $this->orderResource($order);
    }

    /** فك توزيع الطلب (M5) — عكس allocate، يُحرّر الحجوزات ويعيد الطلب غير موزّع. */
    public function deallocate(Request $request, Order $order): AdminOrderResource
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new BusinessException('فك التوزيع متاح فقط لطلب مؤكد أو قيد التجهيز.');
        }

        $order = $this->allocation->deallocate($order, (int) $request->user()->id);

        return $this->orderResource($order);
    }

    private function orderResource(Order $order): AdminOrderResource
    {
        return AdminOrderResource::make(
            $order->load(['items', 'statusHistories', 'customer', 'city', 'allocatedBranch'])
        );
    }
}
