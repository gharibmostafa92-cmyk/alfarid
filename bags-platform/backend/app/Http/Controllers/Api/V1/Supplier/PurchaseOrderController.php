<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\PurchaseOrder\RequestChangeRequest;
use App\Http\Resources\Supplier\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierUser;
use App\Services\ProcurementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * أوامر شراء المورد في بوابته — القاعدة الأمنية الأولى:
 * كل استعلام يبدأ بـ where('supplier_id', مورد صاحب التوكن)، فأمر مورد
 * آخر غير موجود أصلًا (404) ولا يظهر في أي قائمة.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(protected ProcurementService $procurement) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $orders = $this->scoped($user)
            ->with(['branch:id,name', 'items.variant'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return PurchaseOrderResource::collection($orders);
    }

    public function show(Request $request, int $purchaseOrder): PurchaseOrderResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = $this->scoped($user)
            ->with(['branch:id,name', 'items.variant', 'deliveries.items.variant'])
            ->findOrFail($purchaseOrder);

        return PurchaseOrderResource::make($order);
    }

    /** قبول أمر الشراء وتأكيد الالتزام بالتوريد: sent_to_supplier ← supplier_accepted. */
    public function accept(Request $request, int $purchaseOrder): PurchaseOrderResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = $this->scoped($user)->findOrFail($purchaseOrder);

        return PurchaseOrderResource::make($this->procurement->supplierAccept($order, $user));
    }

    /** طلب تعديل أسعار/كميات/موعد مع ملاحظات للإدارة: ← change_requested. */
    public function requestChange(RequestChangeRequest $request, int $purchaseOrder): PurchaseOrderResource
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $order = $this->scoped($user)->findOrFail($purchaseOrder);

        return PurchaseOrderResource::make(
            $this->procurement->supplierRequestChange($order, $user, $request->validated('notes'))
        );
    }

    /** استعلامات البوابة كلها تبدأ من نطاق مورد صاحب التوكن. */
    private function scoped(SupplierUser $user): Builder
    {
        return PurchaseOrder::query()->where('supplier_id', $user->supplier_id);
    }
}
