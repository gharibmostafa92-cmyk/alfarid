<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreProductionReceiptRequest;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\ProductionReceiptService;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductionReceiptController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['options', 'index']),
        ];
    }

    public function __construct(
        private readonly BranchAccess $branchAccess,
        private readonly ProductionReceiptService $productionReceipts,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $branch = $this->branchAccess->restrictedBranch($request->user());
        $branches = Branch::where('is_active', true)->when($branch, fn ($q) => $q->whereKey($branch))
            ->with(['locations' => fn ($q) => $q->where('is_active', true)])->orderBy('id')->get();
        $variants = ProductVariant::where('is_active', true)->whereHas('product')
            ->with('product:id,name_ar')->when($filters['search'] ?? null, function ($q, $search): void {
                $pattern = SearchTerm::pattern($search);
                $q->where(fn ($q) => $q->whereLike('sku', $pattern)->orWhereLike('barcode', $pattern)
                    ->orWhereHas('product', fn ($p) => $p->whereLike('name_ar', $pattern)->orWhereLike('name_en', $pattern)));
            })->orderBy('id')->limit(50)->get();

        return response()->json(['data' => [
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name,
                'locations' => $b->locations->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])]),
            'variants' => $variants->map(fn ($v) => ['id' => $v->id, 'label' => $v->product->name_ar.' / '.$v->sku]),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $branch = $this->branchAccess->restrictedBranch($request->user());
        $receipts = ProductionReceipt::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 15);
        if (! CostAccess::canView($request->user())) {
            $receipts->getCollection()->each(function ($receipt): void {
                $receipt->items = array_map(function ($item) {
                    unset($item['unit_cost']);

                    return $item;
                }, $receipt->items);
            });
        }

        return response()->json($receipts);
    }

    public function store(StoreProductionReceiptRequest $request): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        abort_if($key === null, 422, 'مفتاح Idempotency-Key مطلوب لاستلام الإنتاج.');
        $receipt = $this->productionReceipts->receive($request->validated(), $request->user()->id, $key);

        return response()->json(['message' => 'تم استلام الإنتاج وإضافة الكميات للمخزون.', 'data' => $receipt], 201);
    }
}
