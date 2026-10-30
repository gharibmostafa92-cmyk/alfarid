<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wholesale\WholesaleOrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\WholesaleQuickOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly WholesaleQuickOrderService $quickOrder) {}

    /**
     * قائمة طلبات التاجر مع فلتر المجموعة (active/delivered/cancelled).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'group' => ['nullable', Rule::in(['active', 'delivered', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        $query = Order::query()->where('customer_id', $customer->id)->with('items')->latest('id');

        match ($validated['group'] ?? null) {
            'active' => $query->whereIn('status', ['pending', 'confirmed', 'processing', 'shipped']),
            'delivered' => $query->where('status', 'delivered'),
            'cancelled' => $query->where('status', 'cancelled'),
            default => null,
        };

        return WholesaleOrderResource::collection($query->paginate($validated['per_page'] ?? 15));
    }

    /**
     * تفاصيل طلب واحد يخص التاجر (404 لطلب تاجر آخر).
     */
    public function show(Request $request, int $order): WholesaleOrderResource
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $model = Order::query()->where('customer_id', $customer->id)
            ->with(['items', 'statusHistories', 'city'])->findOrFail($order);

        return WholesaleOrderResource::make($model);
    }

    /**
     * إعادة طلب سابق: نسخ بنوده إلى سلة التاجر الحالية بأسعار اليوم
     * السارية عبر CartService/PricingService، مع تقرير بالمتخطى.
     */
    public function reorder(Request $request, int $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $model = Order::query()->where('customer_id', $customer->id)->findOrFail($order);

        return response()->json(['data' => $this->quickOrder->reorderToCart($customer, $model)]);
    }
}
