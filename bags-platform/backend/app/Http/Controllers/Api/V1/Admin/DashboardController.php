<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/** لوحة معلومات الإدارة: مؤشرات اليوم والشهر من ReportService. */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->dashboard(app(BranchAccess::class)->restrictedBranch($request->user()))]);
    }
}
