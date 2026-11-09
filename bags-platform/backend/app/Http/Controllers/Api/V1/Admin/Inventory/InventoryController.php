<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inventory;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreAdjustmentRequest;
use App\Http\Resources\Admin\Inventory\StockLevelResource;
use App\Http\Resources\Admin\Inventory\StockMovementResource;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Backoffice inventory management: real-time stock levels, movement ledger queries, and manual adjustments.
 */
class InventoryController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['index', 'movements']),
            new Middleware('can:inventory.adjust', only: ['adjustments']),
        ];
    }

    /**
     * List current stock levels across branches and locations with calculated available balance.
     */
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
            ->when($this->branchAccess->restrictedBranch($request->user()), fn ($q, $branch) => $q->where('stock_levels.branch_id', $branch))
            ->with(['variant.product', 'branch', 'location'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.branch_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.location_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.product_variant_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) => $vq->where('product_id', $v)))
            // Evaluates location row safety thresholds and branch aggregate reorder points.
            ->when($filters['low_stock'] ?? null, fn ($q) => $q->whereLowStock())
            ->orderBy('stock_levels.branch_id')
            ->orderBy('stock_levels.product_variant_id')
            ->orderBy('stock_levels.id')
            ->paginate($filters['per_page'] ?? 15);

        return StockLevelResource::collection($levels);
    }

    /**
     * List paginated ledger movements in reverse chronological order with optional filtering.
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_column(StockMovementType::cases(), 'value'))],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from_date'), 'after_or_equal:from_date')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = StockMovement::query()
            ->when($this->branchAccess->restrictedBranch($request->user()), fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with(['variant', 'branch', 'location', 'creator'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) => $vq->where('product_id', $v)))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            // Bound date windows using configured business timezone while querying against UTC timestamps.
            ->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v, config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v, config('app.business_timezone'))->addDay()->startOfDay()->utc()))
            ->orderByDesc('id')
            // Keyset pagination avoids COUNT(*) and OFFSET cost on append-only ledger tables.
            ->cursorPaginate($filters['per_page'] ?? 15);

        return StockMovementResource::collection($movements);
    }

    /**
     * Apply manual stock adjustments atomically within a single transaction.
     */
    public function adjustments(StoreAdjustmentRequest $request): JsonResponse
    {
        $movements = $this->inventory->applyAdjustments($request->validated(), $request->user()->id, $this->requiredIdempotencyKey($request));

        return response()->json([
            'message' => 'تم تنفيذ التسوية بنجاح',
            'data' => StockMovementResource::collection((new EloquentCollection($movements))->load(['variant', 'branch', 'location'])),
        ], 201);
    }
}
