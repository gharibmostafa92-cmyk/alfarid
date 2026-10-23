<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryTransactionType;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\SearchTerm;
use App\Services\SettingService;
use App\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:expenses.view', only: ['index', 'show']),
            new Middleware('can:expenses.create', only: ['store']),
            new Middleware('can:expenses.update', only: ['update']),
            new Middleware('can:expenses.delete', only: ['destroy']),
        ];
    }

    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly SettingService $settings,
    ) {}

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

        $expenses = Expense::query()
            ->with(['branch', 'treasury', 'creator'])
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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1', 'exists:branches,id'],
            'treasury_id' => ['required', 'integer', 'min:1', 'exists:treasuries,id'],
            'category' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expense = DB::transaction(function () use ($data, $request): Expense {
            $expenseNumber = $this->settings->generateNextNumber('expense');

            $expense = Expense::create([
                ...$data,
                'expense_number' => $expenseNumber,
                'created_by' => $request->user()->id,
            ]);

            // خصم من الخزينة
            $this->treasury->record([
                'treasury_id' => $data['treasury_id'],
                'type' => TreasuryTransactionType::Expense,
                'amount' => -(float) $data['amount'],
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'created_by' => $request->user()->id,
                'notes' => "مصروف إداري: {$data['category']} #{$expenseNumber}",
            ]);

            return $expense;
        });

        return response()->json([
            'message' => 'تم تسجيل المصروف بنجاح.',
            'data' => $expense->load(['branch', 'treasury', 'creator']),
        ], 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json(['data' => $expense->load(['branch', 'treasury', 'shift', 'creator'])]);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expense->update($data);

        return response()->json(['message' => 'تم تحديث المصروف.', 'data' => $expense]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['message' => 'تم حذف المصروف.']);
    }
}
