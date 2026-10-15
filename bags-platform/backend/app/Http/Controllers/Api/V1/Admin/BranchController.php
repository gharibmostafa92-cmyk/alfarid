<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchRequest;
use App\Http\Resources\Api\V1\Admin\BranchResource;
use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $branches = Branch::query()
            ->withCount(['locations', 'users'])
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = Branch::query()->create($request->validated());
        $branch->loadCount(['locations', 'users']);

        return BranchResource::make($branch)->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return BranchResource::make($branch->loadCount(['locations', 'users']));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return BranchResource::make($branch->loadCount(['locations', 'users']));
    }

    public function destroy(Branch $branch): JsonResponse|Response
    {
        $inventoryTables = $this->inventoryMovementTables();

        try {
            $deleted = DB::transaction(function () use ($branch, $inventoryTables): bool {
                $lockedBranch = Branch::query()
                    ->whereKey($branch->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedBranch->users()->exists() || $this->hasInventoryMovements($lockedBranch, $inventoryTables)) {
                    return false;
                }

                $lockedBranch->delete();

                return true;
            });
        } catch (QueryException $exception) {
            if (! $this->isIntegrityConstraintViolation($exception)) {
                throw $exception;
            }

            return $this->conflictResponse();
        }

        if (! $deleted) {
            return $this->conflictResponse();
        }

        return response()->noContent();
    }

    /**
     * @return array<string, list<string>>
     */
    private function inventoryMovementTables(): array
    {
        $tables = [];

        foreach (['inventory_movements', 'stock_movements', 'inventory_transactions', 'stock_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = [];

            foreach (['branch_id', 'location_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $tables[$table] = $columns;
            }
        }

        return $tables;
    }

    /**
     * @param  array<string, list<string>>  $tables
     */
    private function hasInventoryMovements(Branch $branch, array $tables): bool
    {
        foreach ($tables as $table => $columns) {
            if (in_array('branch_id', $columns, true) && DB::table($table)->where('branch_id', $branch->getKey())->exists()) {
                return true;
            }

            if (in_array('location_id', $columns, true)) {
                $locationIds = $branch->locations()->select('locations.id');

                if (DB::table($table)->whereIn('location_id', $locationIds)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23503'], true);
    }

    private function conflictResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'لا يمكن حذف الفرع لارتباطه بموظفين أو حركات مخزنية.',
        ], 409);
    }
}
