<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TreasuryType;
use App\Http\Controllers\Controller;
use App\Models\Treasury;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

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

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $treasuries = Treasury::query()
            ->with('branch')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('branch_id')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($treasuries);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50', 'unique:treasuries,code'],
            'branch_id' => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
            'type' => ['required', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $treasury = Treasury::create($data + ['current_balance' => 0]);

        return response()->json([
            'message' => 'تم إنشاء الخزينة بنجاح.',
            'data' => $this->treasuryData($treasury),
        ], 201);
    }

    public function show(Treasury $treasury): JsonResponse
    {
        return response()->json(['data' => $this->treasuryData($treasury)]);
    }

    public function update(Request $request, Treasury $treasury): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('treasuries', 'code')->ignore($treasury->id)],
            'branch_id' => ['nullable', 'integer', 'min:1', 'exists:branches,id'],
            'type' => ['sometimes', Rule::in(array_column(TreasuryType::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $treasury->update($data);

        return response()->json([
            'message' => 'تم تحديث الخزينة بنجاح.',
            'data' => $this->treasuryData($treasury),
        ]);
    }

    public function destroy(Treasury $treasury): JsonResponse
    {
        if ((float) $treasury->current_balance !== 0.0) {
            return response()->json(['message' => 'لا يمكن حذف خزينة برصيد غير صفري.'], 422);
        }

        $treasury->delete();

        return response()->json(['message' => 'تم حذف الخزينة.']);
    }

    private function treasuryData(Treasury $treasury): Treasury
    {
        return $treasury->loadMissing('branch');
    }
}
