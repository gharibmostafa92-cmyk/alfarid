<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Auth\UserResource;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\PosService;
use App\Services\Shared\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single-request POS terminal bootstrap endpoint returning cashier profile, branch,
 * current shift with live totals, available drawers, active categories, settings,
 * catalog version, and held sales count.
 */
class PosBootstrapController extends Controller
{
    public function __construct(
        private readonly PosService $pos,
        private readonly SettingService $settings,
    ) {}

    /**
     * Retrieve startup state for the POS terminal.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        if ($user->branch_id === null) {
            throw new AuthorizationException('حسابك غير مرتبط بأي فرع.');
        }

        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $branch = Branch::query()->whereKey($branchId)->where('is_active', true)->firstOrFail();
        $user->load(['branch', 'roles.permissions', 'permissions']);

        $openShift = PosShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('status', PosShiftStatus::Open)
            ->with(['cashier', 'treasury', 'branch'])
            ->latest('id')
            ->first();

        $shiftData = null;
        if ($openShift !== null) {
            $totals = $this->pos->computeShiftTotals($openShift);
            $shiftData = [
                'id'                    => $openShift->id,
                'shift_number'          => $openShift->shift_number,
                'status'                => $openShift->status->value,
                'opened_at'             => $openShift->opened_at,
                'closed_at'             => $openShift->closed_at,
                'approved_at'           => $openShift->approved_at,
                'opening_cash'          => number_format((float) $totals['opening_cash'], 2, '.', ''),
                'cash_sales_total'      => number_format((float) $totals['cash_sales_total'], 2, '.', ''),
                'card_sales_total'      => number_format((float) $totals['card_sales_total'], 2, '.', ''),
                'cash_returns_total'    => number_format((float) $totals['cash_returns_total'], 2, '.', ''),
                'cash_expenses_total'   => number_format((float) $totals['cash_expenses_total'], 2, '.', ''),
                'expected_closing_cash' => number_format((float) $totals['expected_closing_cash'], 2, '.', ''),
                'actual_closing_cash'   => $openShift->actual_closing_cash,
                'cash_difference'       => $openShift->cash_difference,
                'invoices_count'        => (int) $totals['invoices_count'],
                'notes'                 => $openShift->notes,
                'cashier'               => $openShift->relationLoaded('cashier') && $openShift->cashier !== null ? [
                    'id'   => $openShift->cashier->id,
                    'name' => $openShift->cashier->name,
                ] : null,
                'treasury'              => $openShift->relationLoaded('treasury') && $openShift->treasury !== null ? [
                    'id'   => $openShift->treasury->id,
                    'name' => $openShift->treasury->name,
                    'code' => $openShift->treasury->code,
                ] : null,
                'branch'                => $openShift->relationLoaded('branch') && $openShift->branch !== null ? [
                    'id'   => $openShift->branch->id,
                    'name' => $openShift->branch->name,
                ] : null,
            ];
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $cat): array => [
                'id'   => (string) $cat->id,
                'name' => $cat->name_ar ?? $cat->name_en ?? '',
            ])
            ->values()
            ->all();

        $maxDiscount = $this->settings->get('pos.max_discount_percent')
            ?? $this->settings->get('pos_max_discount_percent')
            ?? config('inventory.pos_max_discount_percent', 20);

        $heldCount = PosHeldSale::query()
            ->where('branch_id', $branchId)
            ->count();

        return response()->json([
            'data' => [
                'user'            => UserResource::make($user)->resolve($request),
                'branch'          => [
                    'id'   => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                ],
                'shift'           => $shiftData,
                'drawers'         => $this->pos->getAvailableDrawers($branchId),
                'categories'      => $categories,
                'settings'        => [
                    'max_discount_percent' => (float) $maxDiscount,
                    'currency'             => 'EGP',
                ],
                'catalog_version' => $this->pos->computeCatalogVersion($branchId),
                'held_count'      => $heldCount,
            ],
        ]);
    }
}
