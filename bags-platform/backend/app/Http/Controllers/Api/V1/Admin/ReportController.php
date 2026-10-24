<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\BranchAccess;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقارير الإدارة (المرحلة 13.2) — كلها من ReportService باستعلامات
 * تجميعية في قاعدة البيانات فقط.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $businessNow = now(config('app.business_timezone', 'Africa/Cairo'));

        return [
            $validated['from'] ?? $businessNow->copy()->startOfMonth()->toDateString(),
            $validated['to'] ?? $businessNow->toDateString(),
        ];
    }

    public function sales(Request $request): JsonResponse
    {
        $request->validate([
            'granularity' => ['nullable', 'in:daily,monthly'],
            'group_by' => ['nullable', 'in:branch,channel,cashier,payment_method'],
        ]);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->reports->salesReport(
            $from,
            $to,
            $request->input('granularity', 'daily'),
            $request->input('group_by'),
            app(BranchAccess::class)->restrictedBranch($request->user()),
        )]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $request->validate(['dead_days' => ['nullable', 'integer', 'min:1', 'max:3650']]);

        return response()->json(['data' => $this->reports->inventoryReport((int) $request->input('dead_days', 90), app(BranchAccess::class)->restrictedBranch($request->user()))]);
    }

    public function profit(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->reports->profitReport($from, $to, app(BranchAccess::class)->restrictedBranch($request->user()))]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());

        return response()->json(['data' => $this->reports->suppliersReport()]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $request->validate(['only_differences' => ['nullable', 'boolean']]);
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->reports->shiftsReport(
            $from,
            $to,
            $request->boolean('only_differences'),
            app(BranchAccess::class)->restrictedBranch($request->user()),
        )]);
    }
}
