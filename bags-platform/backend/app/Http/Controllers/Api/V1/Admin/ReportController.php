<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\BranchAccess;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin controller serving aggregated sales, profit, inventory, supplier, and shift reports.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        $businessNow = now(config('app.business_timezone', 'Africa/Cairo'));

        $from = $validated['from'] ?? $businessNow->copy()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? $businessNow->toDateString();

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 365) {
            throw new BusinessException('المدى الزمني للتقارير لا يتجاوز 366 يومًا شاملة البداية والنهاية.');
        }

        return [$from, $to];
    }

    /**
     * Returns sales analytics aggregated by daily/monthly periods and optional breakdown dimensions.
     *
     * @param Request $request Query request containing filters
     * @return JsonResponse Sales report data
     */
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

    /**
     * Returns inventory valuation and dead stock analysis.
     *
     * @param Request $request Request containing optional dead_days threshold
     * @return JsonResponse Inventory report data
     */
    public function inventory(Request $request): JsonResponse
    {
        $request->validate(['dead_days' => ['nullable', 'integer', 'min:1', 'max:3650']]);

        return response()->json(['data' => $this->reports->inventoryReport((int) $request->input('dead_days', 90), app(BranchAccess::class)->restrictedBranch($request->user()))]);
    }

    /**
     * Returns profit margins matching recognized sales against COGS and expenses.
     *
     * @param Request $request Request containing date range
     * @return JsonResponse Profit and margin metrics
     */
    public function profit(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->reports->profitReport($from, $to, app(BranchAccess::class)->restrictedBranch($request->user()))]);
    }

    /**
     * Returns accounts payable aging report across all suppliers.
     *
     * @param Request $request Authorized admin request
     * @return JsonResponse Supplier aging data
     */
    public function suppliers(Request $request): JsonResponse
    {
        app(BranchAccess::class)->assertCentralAccess($request->user());

        return response()->json(['data' => $this->reports->suppliersReport()]);
    }

    /**
     * Returns POS shift balancing reports and closing cash discrepancies.
     *
     * @param Request $request Request containing date range and filters
     * @return JsonResponse Shift reports
     */
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
