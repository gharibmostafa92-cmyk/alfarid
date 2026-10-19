<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\WholesaleQuickOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly WholesaleQuickOrderService $quickOrder) {}

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
