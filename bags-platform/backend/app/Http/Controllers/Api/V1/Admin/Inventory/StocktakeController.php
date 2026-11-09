<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inventory;

use App\Enums\StocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Stocktake\StoreStocktakeRequest;
use App\Http\Requests\Admin\Stocktake\UpdateStocktakeItemsRequest;
use App\Http\Requests\Admin\Stocktake\UpdateStocktakeRequest;
use App\Http\Resources\Admin\Inventory\StocktakeResource;
use App\Models\Stocktake;
use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\StocktakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Backoffice administration of physical warehouse and store stocktake counting sessions and ledger adjustments.
 */
class StocktakeController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly StocktakeService $stocktakes,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:stocktakes.view', only: ['index', 'show']),
            new Middleware('can:stocktakes.create', only: ['store']),
            new Middleware('can:stocktakes.update', only: ['update', 'updateItems']),
            new Middleware('can:stocktakes.delete', only: ['destroy']),
            new Middleware('can:stocktakes.approve', only: ['approve']),
        ];
    }

    /**
     * List paginated physical stocktake sessions with branch access constraints.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(StocktakeStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters['staff_branch_id'] = $this->branchAccess->restrictedBranch($request->user());

        return StocktakeResource::collection($this->stocktakes->paginate($filters));
    }

    /**
     * Create a new physical stocktake session under an active branch.
     */
    public function store(StoreStocktakeRequest $request): JsonResponse
    {
        $stocktake = $this->stocktakes->create($request->validated(), $request->user()->id, $this->requiredIdempotencyKey($request));

        return StocktakeResource::make($stocktake)
            ->additional(['message' => 'تم إنشاء جلسة الجرد بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retrieve stocktake session details, lines, and counted variances.
     */
    public function show(Request $request, Stocktake $stocktake): StocktakeResource
    {
        $this->branchAccess->assertBranch($request->user()->id, $stocktake->branch_id);

        return StocktakeResource::make($this->stocktakes->find($stocktake->id));
    }

    /**
     * Update stocktake session details and reset/replace counted lines.
     */
    public function update(UpdateStocktakeRequest $request, Stocktake $stocktake): StocktakeResource
    {
        return StocktakeResource::make($this->stocktakes->update($stocktake, $request->validated(), $request->user()->id));
    }

    /**
     * Incrementally upsert counted stocktake lines without deleting unmentioned items.
     */
    public function updateItems(UpdateStocktakeItemsRequest $request, Stocktake $stocktake): StocktakeResource
    {
        return StocktakeResource::make($this->stocktakes->upsertSomeItems($stocktake, $request->validated()['items'], $request->user()->id));
    }

    /**
     * Delete an unapproved stocktake session.
     */
    public function destroy(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakes->delete($stocktake, $request->user()->id);

        return response()->json(['message' => 'تم حذف جلسة الجرد بنجاح']);
    }

    /**
     * Approve stocktake session and post automated variance adjustment movements.
     */
    public function approve(Request $request, Stocktake $stocktake): StocktakeResource
    {
        $stocktake = $this->stocktakes->approve($stocktake, $request->user()->id);

        return StocktakeResource::make($stocktake)->additional(['message' => 'تم اعتماد الجرد وتسوية الفروق']);
    }
}
