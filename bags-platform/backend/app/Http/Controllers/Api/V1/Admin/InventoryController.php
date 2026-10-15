<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreAdjustmentRequest;
use App\Http\Resources\Admin\StockLevelResource;
use App\Http\Resources\Admin\StockMovementResource;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\BranchAccess;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class InventoryController extends Controller implements HasMiddleware
{
    public function __construct(private readonly InventoryService $inventory) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['index', 'movements']),
            new Middleware('can:inventory.adjust', only: ['adjustments']),
        ];
    }

    /** قائمة أرصدة المخزون الحالية مع الفلاتر والمتاح المحسوب. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $levels = StockLevel::query()
            ->when(app(BranchAccess::class)->restrictedBranch($request->user()), fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with(['variant.product', 'branch', 'location'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) => $vq->where('product_id', $v)))
            // حد إعادة الطلب الفعّال: مستوى الصف إن ضُبط (> 0)، وإلا مستوى المتغير نفسه
            ->when($filters['low_stock'] ?? null, fn ($q) => $q
                ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
                ->whereRaw('stock_levels.available <= COALESCE(NULLIF(stock_levels.reorder_level, 0), product_variants.reorder_level, 0)')
                // صف بلا حد إعادة طلب فعّال ليس "منخفضًا": صفوف صفرية ينشئها
                // lockLevels كأثر جانبي كانت تملأ الفلتر وهميًا (L-4).
                ->whereRaw('COALESCE(NULLIF(stock_levels.reorder_level, 0), product_variants.reorder_level, 0) > 0')
                ->select('stock_levels.*'))
            ->orderBy('branch_id')
            ->orderBy('product_variant_id')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);

        return StockLevelResource::collection($levels);
    }

    /** سجل حركات دفتر الأستاذ من الأحدث للأقدم مع الفلاتر. */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_column(StockMovementType::cases(), 'value'))],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = StockMovement::query()
            ->when(app(BranchAccess::class)->restrictedBranch($request->user()), fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with(['variant', 'branch', 'location', 'creator'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) => $vq->where('product_id', $v)))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            // حدود الأيام بأيام العمل (business_timezone) لا UTC، والتخزين يظل UTC
            ->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v, config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v, config('app.business_timezone'))->addDay()->startOfDay()->utc()))
            ->orderByDesc('id')
            // الدفتر append-only وسيكبر لملايين الصفوف: بلا COUNT(*) وبلا OFFSET —
            // ترقيم بالمؤشر على (branch_id, id DESC) يجعل الصفحات العميقة بنفس
            // تكلفة الأولى (M-7). المقايضة: لا قفز لرقم صفحة محدد.
            ->cursorPaginate($filters['per_page'] ?? 15);

        return StockMovementResource::collection($movements);
    }

    /** تسوية يدوية: كل العناصر في transaction واحدة عبر InventoryService فقط. */
    public function adjustments(StoreAdjustmentRequest $request): JsonResponse
    {
        $movements = $this->inventory->applyAdjustments($request->validated(), $request->user()->id, $this->idempotencyKey($request));

        return response()->json([
            'message' => 'تم تنفيذ التسوية بنجاح',
            'data' => StockMovementResource::collection((new EloquentCollection($movements))->load(['variant', 'branch', 'location'])),
        ], 201);
    }
}
