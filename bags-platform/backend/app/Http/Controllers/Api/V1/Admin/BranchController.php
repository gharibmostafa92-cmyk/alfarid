<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Branches\CreateBranch;
use App\Actions\Branches\DeleteBranch;
use App\Actions\Branches\UpdateBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBranchRequest;
use App\Http\Resources\Api\V1\Admin\BranchResource;
use App\Models\Branch;
use App\Services\BranchAccess;
use App\Services\StaffAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        StaffAdministration::authorize($request->user('staff'), ['branches.view', 'branches.manage']);
        $restricted = app(BranchAccess::class)->restrictedBranch($request->user('staff'));
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $branches = Branch::query()->when($restricted !== null, fn ($query) => $query->whereKey($restricted))->withCount(['locations', 'users'])->orderBy('id')->paginate($validated['per_page'] ?? 15)->withQueryString();

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request, CreateBranch $action): JsonResponse
    {
        $branch = $action($request->user(), $request->validated());

        return $this->branchResource($branch)->response()->setStatusCode(201);
    }

    public function show(Request $request, Branch $branch): BranchResource
    {
        StaffAdministration::authorize($request->user('staff'), ['branches.view', 'branches.manage']);
        $restricted = app(BranchAccess::class)->restrictedBranch($request->user('staff'));
        abort_if($restricted !== null && (int) $branch->id !== $restricted, 403);

        return $this->branchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranch $action): BranchResource
    {
        $branch = $action($request->user(), $branch, $request->validated());

        return $this->branchResource($branch);
    }

    private function branchResource(Branch $branch): BranchResource
    {
        return BranchResource::make($branch->loadCount(['locations', 'users']));
    }

    public function destroy(Request $request, Branch $branch, DeleteBranch $action): JsonResponse|Response
    {
        try {
            $deleted = $action($request->user(), $branch);
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

    private function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23503'], true);
    }

    private function conflictResponse(): JsonResponse
    {
        return response()->json(['message' => 'لا يمكن حذف الفرع لارتباطه بموظفين أو حركات مخزنية.'], 409);
    }
}
