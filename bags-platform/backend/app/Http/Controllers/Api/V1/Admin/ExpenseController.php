<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryTransactionType;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Treasury;
use App\Services\BranchAccess;
use App\Services\OperationIdempotencyService;
use App\Services\SearchTerm;
use App\Services\SettingService;
use App\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Handles administrative petty cash expenses, authorization checks, and ledger reversals.
 */
class ExpenseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:expenses.view', only: ['index', 'show']),
            new Middleware('can:expenses.create', only: ['store']),
            new Middleware('can:expenses.update', only: ['update']),
            new Middleware('can:expenses.delete', only: ['destroy', 'void']),
        ];
    }

    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly SettingService $settings,
        private readonly BranchAccess $branchAccess,
        private readonly OperationIdempotencyService $idempotency,
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
     * @throws BusinessException If treasury is inactive, pos_drawer, or branch mismatch
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

        $treasury = Treasury::query()->findOrFail($data['treasury_id']);
        $this->branchAccess->assertBranch($request->user()->id, (int) $data['branch_id']);
        $this->branchAccess->assertTreasury($request->user()->id, $treasury);
        if (! $treasury->is_active || ($treasury->branch_id !== null && (int) $treasury->branch_id !== (int) $data['branch_id'])) {
            throw new BusinessException('الخزينة غير مفعّلة أو لا تتبع الفرع المحدد.');
        }
        if ($treasury->type->value === 'pos_drawer') {
            throw new BusinessException('مصروفات أدراج نقطة البيع تُسجل من مسار نقطة البيع فقط.');
        }

        $expense = $this->idempotency->run(
            'expense',
            $request->user(),
            $request->header('Idempotency-Key'),
            $data,
            fn () => DB::transaction(function () use ($data, $request): Expense {
                $lockedTreasury = Treasury::query()->lockForUpdate()->findOrFail($data['treasury_id']);
                $this->branchAccess->assertTreasury($request->user()->id, $lockedTreasury);
                if ($lockedTreasury->type->value === 'pos_drawer'
                    || ($lockedTreasury->branch_id !== null && (int) $lockedTreasury->branch_id !== (int) $data['branch_id'])) {
                    throw new BusinessException('الخزينة لا تقبل مصروفات هذا الفرع من المسار الإداري.');
                }
                $expenseNumber = $this->settings->generateNextNumber('expense');

                $expense = Expense::create([
                    ...$data,
                    'amount' => bcadd((string) $data['amount'], '0', 2),
                    'expense_number' => $expenseNumber,
                    'created_by' => $request->user()->id,
                ]);

                $this->treasury->record([
                    'treasury_id' => $data['treasury_id'],
                    'type' => TreasuryTransactionType::Expense,
                    'amount' => bcsub('0', (string) $data['amount'], 2),
                    'reference_type' => Expense::class,
                    'reference_id' => $expense->id,
                    'created_by' => $request->user()->id,
                    'notes' => "مصروف إداري: {$data['category']} #{$expenseNumber}",
                ]);

                return $expense;
            })
        );

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

    /**
     * Updates non-financial attributes of an active expense.
     *
     * @param Request $request Update payload
     * @param Expense $expense Expense to update
     * @return JsonResponse Updated expense response
     * @throws BusinessException If expense was already voided
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->assertExpenseAccess($request->user()->id, $expense);

        if ($expense->voided_at !== null) {
            throw new BusinessException('لا يمكن تعديل مصروف ملغى.');
        }

        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expense->update($data);

        return response()->json(['message' => 'تم تحديث المصروف.', 'data' => $expense]);
    }

    /**
     * Voids an expense and atomically issues a treasury reversal transaction.
     *
     * @param Request $request Deletion request containing void_reason
     * @param Expense $expense Expense to void
     * @return JsonResponse Voided expense response
     * @throws BusinessException If already voided or tied to a POS shift
     */
    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        // Mandatory void reason maintains an audit trail when reversing ledger impact.
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->assertExpenseAccess($request->user()->id, $expense);

        $expense = DB::transaction(function () use ($expense, $request, $data): Expense {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->voided_at !== null) {
                throw new BusinessException('هذا المصروف ملغى سابقًا.');
            }
            if ($locked->shift_id !== null) {
                throw new BusinessException('لا يمكن إلغاء مصروف مرتبط بوردية من المسار الإداري.');
            }

            // Post distinct reversal type so reporting aggregations do not register positive expenses.
            $this->treasury->record([
                'treasury_id' => $locked->treasury_id,
                'type' => TreasuryTransactionType::ExpenseReversal,
                'amount' => (string) $locked->amount,
                'reference_type' => Expense::class,
                'reference_id' => $locked->id,
                'created_by' => $request->user()->id,
                'notes' => "إلغاء مصروف #{$locked->expense_number}",
            ]);

            $locked->update([
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $data['void_reason'] ?? null,
            ]);

            return $locked->fresh();
        });

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
