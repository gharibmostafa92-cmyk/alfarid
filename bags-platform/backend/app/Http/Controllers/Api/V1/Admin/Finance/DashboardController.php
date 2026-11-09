<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Services\Inventory\BranchAccess;
use App\Services\Finance\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin executive dashboard aggregating daily and monthly KPIs scoped by staff branch access.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly BranchAccess $branchAccess,
    ) {}

    /**
     * Retrieve aggregated dashboard metrics.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->dashboard($this->branchAccess->restrictedBranch($request->user()))]);
    }
}
