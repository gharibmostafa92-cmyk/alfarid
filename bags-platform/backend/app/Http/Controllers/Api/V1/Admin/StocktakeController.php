<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Stocktake\StoreStocktakeRequest;
use App\Http\Requests\Admin\Stocktake\UpdateStocktakeRequest;
use App\Http\Resources\Admin\StocktakeResource;
use App\Models\Stocktake;
use App\Services\StocktakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class StocktakeController extends Controller implements HasMiddleware
{
    public function __construct(private readonly StocktakeService $stocktakes) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:stocktakes.view', only: ['index', 'show']),
            new Middleware('can:stocktakes.create', only: ['store']),
            new Middleware('can:stocktakes.update', only: ['update']),
            new Middleware('can:stocktakes.delete', only: ['destroy']),
            new Middleware('can:stocktakes.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(StocktakeStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return StocktakeResource::collection($this->stocktakes->paginate($filters));
    }

    public function store(StoreStocktakeRequest $request): JsonResponse
    {
        $stocktake = $this->stocktakes->create($request->validated(), $request->user()->id);

        return (new StocktakeResource($stocktake))
            ->additional(['message' => 'تم إنشاء جلسة الجرد بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Stocktake $stocktake): StocktakeResource
    {
        return new StocktakeResource($this->stocktakes->find($stocktake->id));
    }

    public function update(UpdateStocktakeRequest $request, Stocktake $stocktake): StocktakeResource
    {
        return new StocktakeResource($this->stocktakes->update($stocktake, $request->validated()));
    }

    public function destroy(Stocktake $stocktake): JsonResponse
    {
        $this->stocktakes->delete($stocktake);

        return response()->json(['message' => 'تم حذف جلسة الجرد بنجاح']);
    }

    public function approve(Request $request, Stocktake $stocktake): StocktakeResource
    {
        $stocktake = $this->stocktakes->approve($stocktake, $request->user()->id);

        return (new StocktakeResource($stocktake))->additional(['message' => 'تم اعتماد الجرد وتسوية الفروق']);
    }
}
