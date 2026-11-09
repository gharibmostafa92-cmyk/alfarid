<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryType;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Treasury;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Manages treasury accounts, safes, and POS drawer definitions with branch access enforcement.
 */
class TreasuryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:treasuries.view', only: ['index', 'show']),
            new Middleware('can:treasuries.create', only: ['store']),
            new Middleware('can:treasuries.update', only: ['update']),
            new Middleware('can:treasuries.delete', only: ['destroy']),
        ];
    }

    public function __construct(
        private readonly BranchAccess $branchAccess,
        private readonly TreasuryService $treasuryService,
    ) {}

    /**
     * Lists paginated treasuries scoped by user branch restrictions and filters.
     *
     * @param Request $request Filter query request
     * @return JsonResponse Paginated treasury accounts
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branchAccess->restrictedBranch($request->user());
        $treasuries = Treasury::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
            ->with('branch')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('branch_id')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($treasuries);
    }

    /**
     * Creates a new treasury account with zero starting balance.
     *
     * @param Request $request Creation payload
     * @return JsonResponse Created treasury response
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50', 'unique:treasuries,code'],
            'branch_id' => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
            'type' => ['required', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $treasury = $this->treasuryService->createAccount($data, $request->user());

        return response()->json([
            'message' => 'تم إنشاء الخزينة بنجاح.',
            'data' => $this->treasuryData($treasury),
        ], 201);
    }

    /**
     * Retrieves details of a specific treasury.
     *
     * @param Request $request Incoming request
     * @param Treasury $treasury Target treasury
     * @return JsonResponse Treasury details
     */
    public function show(Request $request, Treasury $treasury): JsonResponse
    {
        $this->branchAccess->assertTreasury($request->user()->id, $treasury);

        return response()->json(['data' => $this->treasuryData($treasury)]);
    }

    /**
     * Updates an existing treasury, preventing type changes or reassignment with active balances.
     *
     * @param Request $request Update request payload
     * @param Treasury $treasury Target treasury
     * @return JsonResponse Updated treasury response
     * @throws BusinessException If mutating immutable type or active balances
     */
    public function update(Request $request, Treasury $treasury): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('treasuries', 'code')->ignore($treasury->id)],
            'branch_id' => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
            'type' => ['sometimes', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $treasury = $this->treasuryService->updateAccount($treasury, $data, $request->user());

        return response()->json([
            'message' => 'تم تحديث الخزينة بنجاح.',
            'data' => $this->treasuryData($treasury),
        ]);
    }

    /**
     * Deletes a treasury account if it has zero balance and no transaction history.
     *
     * @param Request $request Deletion request
     * @param Treasury $treasury Target treasury
     * @return JsonResponse Deletion confirmation
     * @throws BusinessException If account has transactions, shifts, or non-zero balance
     */
    public function destroy(Request $request, Treasury $treasury): JsonResponse
    {
        $this->treasuryService->deleteAccount($treasury, $request->user());

        return response()->json(['message' => 'تم حذف الخزينة.']);
    }

    private function treasuryData(Treasury $treasury): Treasury
    {
        return $treasury->loadMissing('branch');
    }
}
