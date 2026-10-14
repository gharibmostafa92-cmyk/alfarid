<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchRequest;
use App\Http\Resources\Api\V1\Admin\BranchResource;
use App\Models\Branch;
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
        $deleted = DB::transaction(function () use ($branch): bool {
            $lockedBranch = Branch::query()
                ->whereKey($branch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBranch->users()->exists() || $this->hasInventoryMovements($lockedBranch)) {
                return false;
            }

            $lockedBranch->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'message' => 'لا يمكن حذف الفرع لارتباطه بموظفين أو حركات مخزنية.',
            ], 409);
        }

        return response()->noContent();
    }

    private function hasInventoryMovements(Branch $branch): bool
    {
        foreach (['inventory_movements', 'stock_movements', 'inventory_transactions', 'stock_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'branch_id') && DB::table($table)->where('branch_id', $branch->getKey())->exists()) {
                return true;
            }

            if (Schema::hasColumn($table, 'location_id')) {
                $locationIds = $branch->locations()->select('locations.id');

                if (DB::table($table)->whereIn('location_id', $locationIds)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
