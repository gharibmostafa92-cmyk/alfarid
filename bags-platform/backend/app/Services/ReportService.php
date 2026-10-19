<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * خدمة التقارير ولوحة معلومات الإدارة (المرحلة 13.2).
 *
 * قاعدة الأداء: كل الأرقام تُحسب باستعلامات تجميعية مباشرة في قاعدة
 * البيانات (SUM/COUNT/GROUP BY/CASE WHEN) — ممنوع جلب سجلات وحسابها
 * في PHP. «طلبات المبيعات» هنا = طلبات بحالات مؤكدة فصاعدًا
 * (confirmed/processing/shipped/delivered) — الملغاة والمنتظرة لا
 * تُحتسب مبيعات.
 */
class ReportService
{
    /** @var list<string> */
    private const SALES_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered'];

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateTimeString();

        $salesByChannel = function (callable $scope): array {
            $rows = DB::table('orders')
                ->selectRaw('channel, SUM(grand_total) AS total, COUNT(*) AS orders_count')
                ->whereIn('status', self::SALES_STATUSES)
                ->tap($scope)
                ->groupBy('channel')
                ->get();

            $byChannel = ['store' => 0.0, 'wholesale' => 0.0, 'pos' => 0.0];
            $total = 0.0;
            foreach ($rows as $row) {
                $byChannel[$row->channel] = (float) $row->total;
                $total += (float) $row->total;
            }

            return ['total' => $total, 'by_channel' => $byChannel];
        };

        $todaySales = $salesByChannel(fn ($q) => $q->whereDate('created_at', $today));
        $monthSales = $salesByChannel(fn ($q) => $q->where('created_at', '>=', $monthStart));

        $newOrdersCount = (int) DB::table('orders')->whereDate('created_at', $today)->count();

        $lowStockCount = (int) DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->whereRaw('COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0) > 0')
            ->whereRaw('sl.available <= COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0)')
            ->count();

        $pendingWholesale = (int) DB::table('customers')
            ->whereIn('type', ['wholesale', 'distributor'])
            ->where('status', 'pending')
            ->count();

        $topProducts = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->selectRaw('oi.product_variant_id, pv.sku, p.name_ar AS product_name, SUM(oi.qty) AS qty_sold, SUM(oi.line_total) AS revenue')
            ->whereIn('o.status', self::SALES_STATUSES)
            ->where('o.created_at', '>=', $monthStart)
            ->groupBy('oi.product_variant_id', 'pv.sku', 'p.name_ar')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'product_variant_id' => (int) $row->product_variant_id,
                'sku' => $row->sku,
                'product_name' => $row->product_name,
                'qty_sold' => (float) $row->qty_sold,
                'revenue' => (float) $row->revenue,
            ])->all();

        $topBranches = DB::table('orders as o')
            ->leftJoin('branches as b', 'b.id', '=', 'o.allocated_branch_id')
            ->selectRaw('o.allocated_branch_id, b.name AS branch_name, COUNT(*) AS orders_count, SUM(o.grand_total) AS revenue')
            ->whereIn('o.status', self::SALES_STATUSES)
            ->where('o.created_at', '>=', $monthStart)
            ->whereNotNull('o.allocated_branch_id')
            ->groupBy('o.allocated_branch_id', 'b.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'branch_id' => (int) $row->allocated_branch_id,
                'branch_name' => $row->branch_name,
                'orders_count' => (int) $row->orders_count,
                'revenue' => (float) $row->revenue,
            ])->all();

        return [
            'today' => [
                'sales_total' => $todaySales['total'],
                'sales_by_channel' => $todaySales['by_channel'],
                'new_orders_count' => $newOrdersCount,
            ],
            'month' => [
                'sales_total' => $monthSales['total'],
                'sales_by_channel' => $monthSales['by_channel'],
            ],
            'low_stock_count' => $lowStockCount,
            'pending_wholesale_applications' => $pendingWholesale,
            'top_products' => $topProducts,
            'top_branches' => $topBranches,
        ];
    }

    /**
     * تقرير المبيعات الزمني: تجميع بالفترة (يومي/شهري) وبُعد اختياري
     * (فرع/قناة/كاشير/طريقة دفع).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function salesReport(string $from, string $to, string $granularity = 'daily', ?string $groupBy = null): array
    {
        $periodExpr = $granularity === 'monthly'
            ? "DATE_TRUNC('month', o.created_at)::date"
            : 'DATE(o.created_at)';

        $query = DB::table('orders as o')
            ->whereIn('o.status', self::SALES_STATUSES)
            ->whereBetween('o.created_at', [$from.' 00:00:00', $to.' 23:59:59']);

        $selects = [
            "{$periodExpr} AS period",
            'COUNT(*) AS orders_count',
            'COALESCE(SUM(o.subtotal), 0) AS gross_sales',
            'COALESCE(SUM(o.discount_amount), 0) AS discounts',
            'COALESCE(SUM(o.tax_amount), 0) AS tax',
            'COALESCE(SUM(o.shipping_cost), 0) AS shipping',
            'COALESCE(SUM(o.grand_total), 0) AS net_sales',
        ];
        $groups = [$periodExpr];

        if ($groupBy === 'branch') {
            $query->leftJoin('branches as b', 'b.id', '=', 'o.allocated_branch_id');
            $selects[] = 'o.allocated_branch_id AS group_key';
            $selects[] = 'b.name AS group_label';
            $groups[] = 'o.allocated_branch_id';
            $groups[] = 'b.name';
        } elseif ($groupBy === 'channel') {
            $selects[] = 'o.channel AS group_key';
            $groups[] = 'o.channel';
        } elseif ($groupBy === 'payment_method') {
            $selects[] = 'o.payment_method AS group_key';
            $groups[] = 'o.payment_method';
        } elseif ($groupBy === 'cashier') {
            $query->join('pos_shifts as ps', 'ps.id', '=', 'o.pos_shift_id')
                ->leftJoin('users as u', 'u.id', '=', 'ps.user_id');
            $selects[] = 'ps.user_id AS group_key';
            $selects[] = 'u.name AS group_label';
            $groups[] = 'ps.user_id';
            $groups[] = 'u.name';
        }

        $rows = $query
            ->selectRaw(implode(', ', $selects))
            ->groupByRaw(implode(', ', $groups))
            ->orderBy('period')
            ->get()
            ->map(fn ($row): array => [
                'period' => (string) $row->period,
                'group_key' => $row->group_key ?? null,
                'group_label' => $row->group_label ?? ($row->group_key ?? null),
                'orders_count' => (int) $row->orders_count,
                'gross_sales' => (float) $row->gross_sales,
                'discounts' => (float) $row->discounts,
                'tax' => (float) $row->tax,
                'shipping' => (float) $row->shipping,
                'net_sales' => (float) $row->net_sales,
            ])->all();

        $totals = DB::table('orders')
            ->whereIn('status', self::SALES_STATUSES)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(subtotal), 0) AS gross_sales, COALESCE(SUM(discount_amount), 0) AS discounts, COALESCE(SUM(tax_amount), 0) AS tax, COALESCE(SUM(shipping_cost), 0) AS shipping, COALESCE(SUM(grand_total), 0) AS net_sales')
            ->first();

        return [
            'rows' => $rows,
            'totals' => [
                'orders_count' => (int) $totals->orders_count,
                'gross_sales' => (float) $totals->gross_sales,
                'discounts' => (float) $totals->discounts,
                'tax' => (float) $totals->tax,
                'shipping' => (float) $totals->shipping,
                'net_sales' => (float) $totals->net_sales,
            ],
        ];
    }

    /**
     * تقييم المخزون لكل فرع (on_hand × cost_price) + الأصناف الراكدة
     * (رصيد موجود بلا أي حركة منذ X يوم).
     *
     * @return array{valuation: list<array<string, mixed>>, total_value: float, dead_stock: list<array<string, mixed>>}
     */
    public function inventoryReport(int $deadDays = 90): array
    {
        $valuation = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sl.branch_id')
            ->selectRaw('sl.branch_id, b.name AS branch_name, COUNT(DISTINCT sl.product_variant_id) AS variants_count, COALESCE(SUM(sl.on_hand), 0) AS total_qty, COALESCE(SUM(sl.on_hand * COALESCE(pv.cost_price, 0)), 0) AS total_value')
            ->groupBy('sl.branch_id', 'b.name')
            ->orderByDesc('total_value')
            ->get()
            ->map(fn ($row): array => [
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'variants_count' => (int) $row->variants_count,
                'total_qty' => (float) $row->total_qty,
                'total_value' => (float) $row->total_value,
            ])->all();

        $totalValue = (float) (DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->selectRaw('COALESCE(SUM(sl.on_hand * COALESCE(pv.cost_price, 0)), 0) AS v')
            ->first()?->v ?? 0);

        $deadStock = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoinSub(
                DB::table('stock_movements')
                    ->selectRaw('product_variant_id, MAX(created_at) AS last_movement_at')
                    ->groupBy('product_variant_id'),
                'lm',
                'lm.product_variant_id',
                '=',
                'sl.product_variant_id',
            )
            ->selectRaw('sl.product_variant_id, pv.sku, p.name_ar AS product_name, SUM(sl.on_hand) AS on_hand, COALESCE(SUM(sl.on_hand * COALESCE(pv.cost_price, 0)), 0) AS tied_value, lm.last_movement_at')
            ->groupBy('sl.product_variant_id', 'pv.sku', 'p.name_ar', 'lm.last_movement_at')
            ->havingRaw('SUM(sl.on_hand) > 0')
            ->havingRaw('(lm.last_movement_at IS NULL OR lm.last_movement_at < NOW() - (? * INTERVAL \'1 day\'))', [$deadDays])
            ->orderByDesc('tied_value')
            ->limit(100)
            ->get()
            ->map(fn ($row): array => [
                'product_variant_id' => (int) $row->product_variant_id,
                'sku' => $row->sku,
                'product_name' => $row->product_name,
                'on_hand' => (float) $row->on_hand,
                'tied_value' => (float) $row->tied_value,
                'last_movement_at' => $row->last_movement_at,
            ])->all();

        return [
            'valuation' => $valuation,
            'total_value' => $totalValue,
            'dead_stock' => $deadStock,
            'dead_stock_days' => $deadDays,
        ];
    }

    /**
     * مجمل وصافي الربح: صافي إيراد المبيعات − COGS (من حركات البيع في
     * الدفتر بتكلفتها المسجلة أو تكلفة المتغير الحالية عند غيابها) −
     * المصروفات المسجلة في الفترة.
     *
     * @return array<string, float>
     */
    public function profitReport(string $from, string $to): array
    {
        $revenue = (float) DB::table('orders')
            ->whereIn('status', self::SALES_STATUSES)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->sum('grand_total');

        $cogs = (float) (DB::table('stock_movements as sm')
            ->join('product_variants as pv', 'pv.id', '=', 'sm.product_variant_id')
            ->where('sm.type', 'sale')
            ->whereBetween('sm.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('COALESCE(SUM(ABS(sm.quantity) * COALESCE(sm.unit_cost, pv.cost_price, 0)), 0) AS c')
            ->first()?->c ?? 0);

        $expenses = (float) DB::table('expenses')
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        $gross = $revenue - $cogs;

        return [
            'net_sales_revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $gross,
            'total_expenses' => $expenses,
            'net_profit' => $gross - $expenses,
        ];
    }

    /**
     * أرصدة الموردين وأعمار ديونهم: لكل مورد رصيده الحالي وإجمالي
     * مشترياته (فواتير معتمدة) وتوزيع المستحق غير المسدد على شرائح
     * عمرية حسب تاريخ الاستحقاق.
     *
     * @return list<array<string, mixed>>
     */
    public function suppliersReport(): array
    {
        $aging = DB::table('supplier_invoices')
            ->selectRaw(implode(', ', [
                'supplier_id',
                'COALESCE(SUM(total_amount), 0) AS total_purchases',
                'COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND (due_date IS NULL OR due_date >= CURRENT_DATE) THEN total_amount - paid_amount END), 0) AS not_due',
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < CURRENT_DATE AND due_date >= CURRENT_DATE - INTERVAL '30 days' THEN total_amount - paid_amount END), 0) AS overdue_1_30",
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < CURRENT_DATE - INTERVAL '30 days' AND due_date >= CURRENT_DATE - INTERVAL '60 days' THEN total_amount - paid_amount END), 0) AS overdue_31_60",
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < CURRENT_DATE - INTERVAL '60 days' AND due_date >= CURRENT_DATE - INTERVAL '90 days' THEN total_amount - paid_amount END), 0) AS overdue_61_90",
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < CURRENT_DATE - INTERVAL '90 days' THEN total_amount - paid_amount END), 0) AS overdue_90_plus",
            ]))
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->groupBy('supplier_id');

        return DB::table('suppliers as s')
            ->leftJoinSub($aging, 'ag', 'ag.supplier_id', '=', 's.id')
            ->selectRaw('s.id, s.name, s.current_balance, COALESCE(ag.total_purchases, 0) AS total_purchases, COALESCE(ag.not_due, 0) AS not_due, COALESCE(ag.overdue_1_30, 0) AS overdue_1_30, COALESCE(ag.overdue_31_60, 0) AS overdue_31_60, COALESCE(ag.overdue_61_90, 0) AS overdue_61_90, COALESCE(ag.overdue_90_plus, 0) AS overdue_90_plus')
            ->orderBy('s.name')
            ->get()
            ->map(fn ($row): array => [
                'supplier_id' => (int) $row->id,
                'supplier_name' => $row->name,
                'current_balance' => (float) $row->current_balance,
                'total_purchases' => (float) $row->total_purchases,
                'aging' => [
                    'not_due' => (float) $row->not_due,
                    'overdue_1_30' => (float) $row->overdue_1_30,
                    'overdue_31_60' => (float) $row->overdue_31_60,
                    'overdue_61_90' => (float) $row->overdue_61_90,
                    'overdue_90_plus' => (float) $row->overdue_90_plus,
                ],
            ])->all();
    }

    /**
     * تقرير ورديات الكاشير: أرقام الوردية المالية كما سُجلت عند الإغلاق
     * (مبيعات نقدية/شبكة، متوقع/فعلي، فرق) مع إبراز ذوات الفروق.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function shiftsReport(string $from, string $to, bool $onlyDifferences = false): array
    {
        $base = fn () => DB::table('pos_shifts as ps')
            ->leftJoin('users as u', 'u.id', '=', 'ps.user_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ps.branch_id')
            ->whereBetween('ps.opened_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($onlyDifferences, fn ($q) => $q->whereNotNull('ps.cash_difference')->where('ps.cash_difference', '!=', 0));

        $rows = $base()
            ->selectRaw('ps.id, ps.shift_number, ps.user_id, u.name AS cashier_name, ps.branch_id, b.name AS branch_name, ps.status, ps.opened_at, ps.closed_at, ps.cash_sales_total, ps.card_sales_total, ps.cash_returns_total, ps.cash_expenses_total, ps.expected_closing_cash, ps.actual_closing_cash, ps.cash_difference')
            ->orderByDesc('ps.opened_at')
            ->limit(200)
            ->get()
            ->map(fn ($row): array => [
                'shift_id' => (int) $row->id,
                'shift_number' => $row->shift_number,
                'cashier_id' => (int) $row->user_id,
                'cashier_name' => $row->cashier_name,
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'status' => $row->status,
                'opened_at' => $row->opened_at,
                'closed_at' => $row->closed_at,
                'cash_sales_total' => (float) $row->cash_sales_total,
                'card_sales_total' => (float) $row->card_sales_total,
                'cash_returns_total' => (float) $row->cash_returns_total,
                'cash_expenses_total' => (float) $row->cash_expenses_total,
                'expected_closing_cash' => (float) $row->expected_closing_cash,
                'actual_closing_cash' => $row->actual_closing_cash !== null ? (float) $row->actual_closing_cash : null,
                'cash_difference' => $row->cash_difference !== null ? (float) $row->cash_difference : null,
                'has_difference' => $row->cash_difference !== null && (float) $row->cash_difference !== 0.0,
            ])->all();

        $totals = $base()
            ->selectRaw('COUNT(*) AS shifts_count, COALESCE(SUM(ps.cash_sales_total), 0) AS cash_sales, COALESCE(SUM(ps.card_sales_total), 0) AS card_sales, COALESCE(SUM(CASE WHEN ps.cash_difference IS NOT NULL AND ps.cash_difference != 0 THEN 1 ELSE 0 END), 0) AS shifts_with_difference')
            ->first();

        return [
            'rows' => $rows,
            'totals' => [
                'shifts_count' => (int) $totals->shifts_count,
                'cash_sales' => (float) $totals->cash_sales,
                'card_sales' => (float) $totals->card_sales,
                'shifts_with_difference' => (int) $totals->shifts_with_difference,
            ],
        ];
    }
}
