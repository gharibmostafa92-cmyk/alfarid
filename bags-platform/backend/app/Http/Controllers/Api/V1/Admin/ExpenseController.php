<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\ExpenseService;
use App\Services\Shared\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles administrative petty cash expenses, authorization checks, and ledger reversals.
 */
class ExpenseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:expenses.view', only: ['index', 'show', 'attachment']),
            new Middleware('can:expenses.create', only: ['store']),
            new Middleware('can:expenses.update', only: ['update']),
            new Middleware('can:expenses.delete', only: ['destroy', 'void']),
        ];
    }

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly BranchAccess $branchAccess,
    ) {}

    /**
     * Lists paginated administrative expenses filtered by branch, treasury, or date range.
     *
     * @param Request $request Filter query request
     * @return JsonResponse Paginated expense models
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'treasury_id' => ['nullable', 'integer', 'min:1'],
            'shift_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:100'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branchAccess->restrictedBranch($request->user());
        if ($branch !== null && isset($filters['branch_id']) && (int) $filters['branch_id'] !== $branch) {
            throw new BusinessException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }

        $expenses = Expense::query()
            ->with(['branch', 'treasury', 'creator'])
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['treasury_id'] ?? null, fn ($q, $v) => $q->where('treasury_id', $v))
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->whereLike('category', SearchTerm::pattern($v), caseSensitive: false))
            ->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('expense_date', '>=', $v))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('expense_date', '<=', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($expenses);
    }

    /**
     * Records a new administrative expense and posts debit transaction against the selected treasury.
     *
     * @param Request $request Creation payload
     * @return JsonResponse Created expense
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1', 'exists:branches,id'],
            'treasury_id' => ['required', 'integer', 'min:1', 'exists:treasuries,id'],
            'category' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expense = $this->expenses->create($data, $request->user(), $request->header('Idempotency-Key'));

        return response()->json([
            'message' => 'تم تسجيل المصروف بنجاح.',
            'data' => $expense->load(['branch', 'treasury', 'creator']),
        ], 201);
    }

    /**
     * Retrieves a single expense details with associations.
     *
     * @param Request $request Incoming HTTP request
     * @param Expense $expense Resolved expense model
     * @return JsonResponse Expense details
     */
    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->assertExpenseAccess($request->user()->id, $expense);

        return response()->json(['data' => $expense->load(['branch', 'treasury', 'shift', 'creator'])]);
    }

    public function attachment(Request $request, Expense $expense): StreamedResponse
    {
        $this->assertExpenseAccess($request->user()->id, $expense);
        $disk = Storage::disk('ai_private');
        abort_unless($expense->attachment_path && $disk->exists($expense->attachment_path), 404);

        return $disk->download($expense->attachment_path, headers: ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Updates non-financial attributes of an active expense.
     *
     * @param Request $request Update payload
     * @param Expense $expense Expense to update
     * @return JsonResponse Updated expense response
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expense = $this->expenses->update($expense, $data, $request->user());

        return response()->json(['message' => 'تم تحديث المصروف.', 'data' => $expense]);
    }

    /**
     * Voids an expense and atomically issues a treasury reversal transaction.
     *
     * @param Request $request Deletion request containing void_reason
     * @param Expense $expense Expense to void
     * @return JsonResponse Voided expense response
     */
    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        // Mandatory void reason maintains an audit trail when reversing ledger impact.
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        $expense = $this->expenses->void($expense, $data['void_reason'], $request->user());

        return response()->json(['message' => 'تم إلغاء المصروف وعكس أثره على الخزينة.', 'data' => $expense]);
    }

    /**
     * Explicit void alias route for cancelling an expense with an audit reason.
     *
     * @param Request $request Void request
     * @param Expense $expense Expense to cancel
     * @return JsonResponse Voided expense response
     */
    public function void(Request $request, Expense $expense): JsonResponse
    {
        $request->validate(['void_reason' => ['required', 'string', 'max:500']]);

        return $this->destroy($request, $expense);
    }

    private function assertExpenseAccess(int $userId, Expense $expense): void
    {
        $this->branchAccess->assertBranch($userId, $expense->branch_id);
        $this->branchAccess->assertTreasury($userId, $expense->treasury);
    }
}
