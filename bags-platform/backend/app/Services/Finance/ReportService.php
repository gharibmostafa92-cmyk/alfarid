<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Customer\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generates aggregated administrative reports, dashboard metrics, and financial summaries.
 */
class ReportService
{
    /** @var list<string> */
    private const SALES_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered'];

    /** Canonical discrete buckets to prevent Redis cache pollution via arbitrary dead_days queries. */
    /** @var list<int> */
    private const DEAD_DAY_BUCKETS = [7, 14, 30, 60, 90, 120, 180, 365, 730, 1095, 1825, 3650];

    /** Maximum cap on inventory valuation rows returned per report. */
    public const INVENTORY_VALUATION_ROW_LIMIT = 500;

    /**
     * Aggregates key management KPIs and top performers for the dashboard.
     *
     * @param  int|null  $branchId  Optional branch scope filter
     * @return array<string, mixed> Dashboard KPI metrics
     */
    public function dashboard(?int $branchId = null): array
    {
        return StatementBudget::run(10000, function () use ($branchId): array {
            $businessNow = CarbonImmutable::now(config('app.business_timezone', 'Africa/Cairo'));
            [$todayStart, $todayEnd] = $this->periodBounds($businessNow->toDateString(), $businessNow->toDateString());
            $monthStart = $businessNow->startOfMonth()->utc()->toDateTimeString();

            $salesByChannel = function (string $start, string $end) use ($branchId): array {
                $rows = $this->salesEntries($branchId, 'entries', $start, $end)
                    ->selectRaw('channel, SUM(grand_total) AS total, COALESCE(SUM(sale_count), 0) AS orders_count')
                    ->groupBy('channel')
                    ->get();

                $byChannel = ['store' => 0.0, 'wholesale' => 0.0, 'pos' => 0.0];
                $total = BigDecimal::zero();
                foreach ($rows as $row) {
                    $amount = BigDecimal::of((string) ($row->total ?? '0'));
                    $byChannel[$row->channel] = $this->moneyFloat($amount);
                    $total = $total->plus($amount);
                }

                return ['total' => $this->moneyFloat($total), 'by_channel' => $byChannel];
            };

            $todaySales = $salesByChannel($todayStart, $todayEnd);
            $monthSales = $salesByChannel($monthStart, $todayEnd);

            $newOrdersCount = (int) DB::table('orders')->whereNull('return_of_order_id')->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
                ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)->count();

            $lowStockCount = (int) DB::table('stock_levels as sl')->when($branchId !== null, fn ($q) => $q->where('sl.branch_id', $branchId))
                ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
                ->whereRaw('COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0) > 0')
                ->whereRaw('sl.available <= COALESCE(NULLIF(sl.reorder_level, 0), pv.reorder_level, 0)')
                ->count();

            $pendingWholesale = $branchId !== null ? 0 : (int) DB::table('customers')
                ->whereIn('type', CustomerType::commercialValues())
                ->where('status', 'pending')
                ->count();

            $topProducts = DB::table('order_items as oi')->when($branchId !== null, fn ($q) => $q->where('oi.allocated_branch_id', $branchId))
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.product_variant_id')
                ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
                ->selectRaw('oi.product_variant_id, pv.sku, p.name_ar AS product_name, SUM(oi.qty - COALESCE(oi.returned_qty, 0)) AS qty_sold, SUM(oi.line_total) AS revenue')
                ->whereIn('o.status', self::SALES_STATUSES)
                ->whereNull('o.return_of_order_id')
                ->where('o.created_at', '>=', $monthStart)
                ->where('o.created_at', '<', $todayEnd)
                ->groupBy('oi.product_variant_id', 'pv.sku', 'p.name_ar')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get()
                ->map(fn ($row): array => [
                    'product_variant_id' => (int) $row->product_variant_id,
                    'sku' => $row->sku,
                    'product_name' => $row->product_name,
                    'qty_sold' => (float) $row->qty_sold,
                    'revenue' => $this->moneyFloat((string) ($row->revenue ?? '0')),
                ])->all();

            $topBranches = $this->salesEntries($branchId, 'o', $monthStart, $todayEnd)
                ->leftJoin('branches as b', 'b.id', '=', 'o.allocated_branch_id')
                ->selectRaw('o.allocated_branch_id, b.name AS branch_name, COALESCE(SUM(sale_count), 0) AS orders_count, SUM(o.grand_total) AS revenue')
                ->whereNotNull('o.allocated_branch_id')
                ->groupBy('o.allocated_branch_id', 'b.name')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get()
                ->map(fn ($row): array => [
                    'branch_id' => (int) $row->allocated_branch_id,
                    'branch_name' => $row->branch_name,
                    'orders_count' => (int) $row->orders_count,
                    'revenue' => $this->moneyFloat((string) ($row->revenue ?? '0')),
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
        }, readOnly: true);
    }

    /**
     * Generates a time-series sales report grouped by period and optional dimension.
     *
     * @param  string  $from  Start date (YYYY-MM-DD)
     * @param  string  $to  End date (YYYY-MM-DD)
     * @param  string  $granularity  Aggregation bucket ('daily' or 'monthly')
     * @param  string|null  $groupBy  Optional breakdown dimension ('branch', 'channel', 'payment_method', 'cashier')
     * @param  int|null  $branchId  Branch filter
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>} Aggregated sales report
     */
    public function salesReport(string $from, string $to, string $granularity = 'daily', ?string $groupBy = null, ?int $branchId = null): array
    {
        $bounds = $this->periodBounds($from, $to);

        return StatementBudget::run(25000, fn (): array => $this->calculateSalesReport($bounds, $granularity, $groupBy, $branchId));
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $bounds
     */
    private function calculateSalesReport(array $bounds, string $granularity = 'daily', ?string $groupBy = null, ?int $branchId = null): array
    {
        [$periodStart, $periodEnd] = $bounds;
        $zone = str_replace("'", "''", (string) config('app.business_timezone', 'Africa/Cairo'));
        $localCreated = "timezone('{$zone}', o.created_at AT TIME ZONE 'UTC')";
        $periodExpr = $granularity === 'monthly'
            ? "DATE_TRUNC('month', {$localCreated})::date"
            : "DATE({$localCreated})";

        $query = $this->salesEntries($branchId, 'o', $periodStart, $periodEnd)
            ->where('o.created_at', '>=', $periodStart)->where('o.created_at', '<', $periodEnd);

        $selects = [
            "{$periodExpr} AS period",
            'COALESCE(SUM(sale_count), 0) AS orders_count',
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
                'gross_sales' => $this->moneyFloat((string) $row->gross_sales),
                'discounts' => $this->moneyFloat((string) $row->discounts),
                'tax' => $this->moneyFloat((string) $row->tax),
                'shipping' => $this->moneyFloat((string) $row->shipping),
                'net_sales' => $this->moneyFloat((string) $row->net_sales),
            ])->all();

        $totals = $this->salesEntries($branchId, 'entries', $periodStart, $periodEnd)
            ->where('created_at', '>=', $periodStart)->where('created_at', '<', $periodEnd)
            ->selectRaw('COALESCE(SUM(sale_count), 0) AS orders_count, COALESCE(SUM(subtotal), 0) AS gross_sales, COALESCE(SUM(discount_amount), 0) AS discounts, COALESCE(SUM(tax_amount), 0) AS tax, COALESCE(SUM(shipping_cost), 0) AS shipping, COALESCE(SUM(grand_total), 0) AS net_sales')
            ->first();

        return [
            'rows' => $rows,
            'totals' => [
                'orders_count' => (int) $totals->orders_count,
                'gross_sales' => $this->moneyFloat((string) $totals->gross_sales),
                'discounts' => $this->moneyFloat((string) $totals->discounts),
                'tax' => $this->moneyFloat((string) $totals->tax),
                'shipping' => $this->moneyFloat((string) $totals->shipping),
                'net_sales' => $this->moneyFloat((string) $totals->net_sales),
            ],
        ];
    }

    /**
     * Computes inventory valuation and identifies dead stock without physical movement within a threshold.
     *
     * Stores one internal raw snapshot per (branch, dead-days) guarded by a
     * per-branch lock to prevent cache stampedes, and strictly strips all
     * unit-cost fields via stripInventoryCosts() when !$withCosts.
     *
     * @param  int  $deadDays  Inactivity threshold in days
     * @param  int|null  $branchId  Branch filter
     * @param  bool  $withCosts  Whether the caller may see unit-cost fields
     * @param  int  $statementBudgetMs  Statement budget (HTTP callers should pass a lower cap)
     * @return array{valuation: list<array<string, mixed>>, total_value?: float, dead_stock: list<array<string, mixed>>, dead_stock_days: int}
     */
    public function inventoryReport(int $deadDays = 90, ?int $branchId = null, bool $withCosts = true, int $statementBudgetMs = 150000): array
    {
        $deadDays = $this->normalizeDeadDays($deadDays);
        $cacheKey = 'report.inventory.raw.'.md5(($branchId ?? 'all').'|'.$deadDays);

        $data = Cache::get($cacheKey);
        if (! is_array($data)) {
            $compute = function () use ($cacheKey, $deadDays, $branchId, $statementBudgetMs): array {
                $existing = Cache::get($cacheKey);
                if (is_array($existing)) {
                    return $existing;
                }
                $fresh = StatementBudget::run(
                    $statementBudgetMs,
                    fn (): array => $this->calculateInventoryReport($deadDays, $branchId),
                    readOnly: true
                );
                Cache::put($cacheKey, $fresh, 300);

                return $fresh;
            };

            // Keep lock lease longer than statement timeout so slow scans never expire mid-query.
            $lockTtlSeconds = max(30, (int) ceil($statementBudgetMs / 1000) + 10);
            $lockWaitSeconds = min(2, max(1, (int) floor($statementBudgetMs / 1000)));

            try {
                // Serialize heavy inventory scans per branch to prevent cache stampedes or dead_days cache-busting floods.
                $data = Cache::lock('report.inventory.branch.'.($branchId ?? 'all'), $lockTtlSeconds)->block($lockWaitSeconds, $compute);
            } catch (LockTimeoutException) {
                $cachedAfterWait = Cache::get($cacheKey);
                if (! is_array($cachedAfterWait)) {
                    throw new BusinessException('تقرير الجرد قيد التحديث حاليًا؛ يرجى المحاولة بعد ثوانٍ.', [], 503);
                }
                $data = $cachedAfterWait;
            }
        }

        return $withCosts ? $data : $this->stripInventoryCosts($data);
    }

    private function normalizeDeadDays(int $deadDays): int
    {
        $clamped = max(1, min(3650, $deadDays));
        $closest = self::DEAD_DAY_BUCKETS[0];
        $bestDiff = abs($clamped - $closest);

        foreach (self::DEAD_DAY_BUCKETS as $bucket) {
            $diff = abs($clamped - $bucket);
            if ($diff < $bestDiff) {
                $closest = $bucket;
                $bestDiff = $diff;
            }
        }

        return $closest;
    }

    /**
     * Pure filter removing cost-derived valuation fields from a cached inventory report snapshot.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripInventoryCosts(array $data): array
    {
        unset($data['total_value']);
        $data['valuation'] = array_map(function (array $row): array {
            unset($row['total_value']);

            return $row;
        }, $data['valuation']);
        $data['dead_stock'] = array_map(function (array $row): array {
            unset($row['tied_value']);

            return $row;
        }, $data['dead_stock']);

        return $data;
    }

    private function calculateInventoryReport(int $deadDays = 90, ?int $branchId = null): array
    {
        $zone = (string) config('app.business_timezone', 'Africa/Cairo');
        $deadCutoff = CarbonImmutable::now($zone)->subDays($deadDays)->utc()->toDateTimeString();

        $valuationRows = DB::table('stock_levels as sl')->when($branchId !== null, fn ($q) => $q->where('sl.branch_id', $branchId))
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->leftJoin('branches as b', 'b.id', '=', 'sl.branch_id')
            ->selectRaw('sl.branch_id, b.name AS branch_name, COUNT(DISTINCT sl.product_variant_id) AS variants_count, COALESCE(SUM(sl.on_hand), 0) AS total_qty, COALESCE(SUM(sl.on_hand * COALESCE(pv.cost_price, 0)), 0) AS total_value')
            ->groupBy('sl.branch_id', 'b.name')
            ->orderByDesc('total_value')
            // Cap rows by highest valuation to prevent large catalog payloads from exhausting response memory.
            ->limit(self::INVENTORY_VALUATION_ROW_LIMIT + 1)
            ->get();
        $valuationTruncated = $valuationRows->count() > self::INVENTORY_VALUATION_ROW_LIMIT;

        $totalValueDecimal = $valuationTruncated
            ? BigDecimal::of((string) (DB::table('stock_levels as sl')->when($branchId !== null, fn ($q) => $q->where('sl.branch_id', $branchId))
                ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
                ->selectRaw('COALESCE(SUM(sl.on_hand * COALESCE(pv.cost_price, 0)), 0) AS v')
                ->first()?->v ?? '0'))
            : $valuationRows->reduce(
                fn (BigDecimal $carry, object $row): BigDecimal => $carry->plus((string) $row->total_value),
                BigDecimal::zero(),
            );

        $valuation = $valuationRows
            ->take(self::INVENTORY_VALUATION_ROW_LIMIT)
            ->map(fn ($row): array => [
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'variants_count' => (int) $row->variants_count,
                'total_qty' => (float) $row->total_qty,
                'total_value' => $this->moneyFloat((string) $row->total_value),
            ])->all();
        $totalValue = $this->moneyFloat($totalValueDecimal);

        $activeVariants = DB::table('stock_levels')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('product_variant_id, SUM(on_hand) AS on_hand')
            ->groupBy('product_variant_id')
            ->havingRaw('SUM(on_hand) > 0');

        $deadStock = DB::query()->fromSub($activeVariants, 'sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoinLateral(
                // Exclude reserve/release movements as temporary holds do not represent actual stock activity.
                DB::table('stock_movements')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                    ->whereColumn('product_variant_id', 'sl.product_variant_id')
                    ->whereNotIn('type', ['reserve', 'release_reserve'])
                    ->selectRaw('MAX(created_at) AS last_movement_at'),
                'lm',
            )
            ->selectRaw('sl.product_variant_id, pv.sku, p.name_ar AS product_name, sl.on_hand, COALESCE(sl.on_hand * COALESCE(pv.cost_price, 0), 0) AS tied_value, lm.last_movement_at')
            ->whereRaw('(lm.last_movement_at IS NULL OR lm.last_movement_at < ?)', [$deadCutoff])
            ->orderByDesc('tied_value')
            ->limit(100)
            ->get()
            ->map(fn ($row): array => [
                'product_variant_id' => (int) $row->product_variant_id,
                'sku' => $row->sku,
                'product_name' => $row->product_name,
                'on_hand' => (float) $row->on_hand,
                'tied_value' => $this->moneyFloat((string) $row->tied_value),
                'last_movement_at' => $row->last_movement_at,
            ])->all();

        return [
            'valuation' => $valuation,
            'valuation_truncated' => $valuationTruncated,
            'total_value' => $totalValue,
            'dead_stock' => $deadStock,
            'dead_stock_days' => $deadDays,
        ];
    }

    /**
     * Computes gross and net profit matching recognized sales revenue against cost of goods sold and expenses.
     *
     * @param  string  $from  Start date (YYYY-MM-DD)
     * @param  string  $to  End date (YYYY-MM-DD)
     * @param  int|null  $branchId  Branch filter
     * @return array<string, float> Profit metrics including revenue, COGS, and expenses
     */
    public function profitReport(string $from, string $to, ?int $branchId = null): array
    {
        $bounds = $this->periodBounds($from, $to);

        return StatementBudget::run(25000, fn (): array => $this->calculateProfitReport($bounds, $branchId));
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $bounds
     */
    private function calculateProfitReport(array $bounds, ?int $branchId = null): array
    {
        [$periodStart, $periodEnd, $localFrom, $localTo] = $bounds;

        // Recognize an order's merchandise revenue once, in the period that contains
        // its global first physical sale. NOT EXISTS keeps the lookup on the
        // (reference_type, reference_id, type, created_at) index while rejecting
        // orders whose first sale happened before this period.
        $orderReferences = array_values(array_unique([Order::class, (new Order)->getMorphClass(), 'order', 'orders']));
        $recognized = DB::table('orders as o')
            ->whereNull('o.return_of_order_id')
            ->whereIn('o.status', [...self::SALES_STATUSES, 'returned'])
            ->whereExists(function ($q) use ($periodStart, $periodEnd, $orderReferences): void {
                $q->selectRaw('1')->from('stock_movements as sm_sale')
                    ->whereIn('sm_sale.reference_type', $orderReferences)
                    ->whereColumn('sm_sale.reference_id', 'o.id')
                    ->where('sm_sale.type', 'sale')
                    ->where('sm_sale.created_at', '>=', $periodStart)
                    ->where('sm_sale.created_at', '<', $periodEnd);
            })
            ->whereNotExists(function ($q) use ($periodStart, $orderReferences): void {
                $q->selectRaw('1')->from('stock_movements as sm_prev')
                    ->whereIn('sm_prev.reference_type', $orderReferences)
                    ->whereColumn('sm_prev.reference_id', 'o.id')
                    ->where('sm_prev.type', 'sale')
                    ->where('sm_prev.created_at', '<', $periodStart);
            });

        if ($branchId !== null) {
            // Keep revenue on the order's allocated branch so it matches the
            // COGS attribution below, including legacy orders without items.
            $recognizedSalesRevenue = (string) ($recognized
                ->where('o.allocated_branch_id', $branchId)
                ->selectRaw('COALESCE(SUM(o.subtotal - o.discount_amount), 0) AS revenue')
                ->value('revenue') ?? '0');
        } else {
            $recognizedSalesRevenue = (string) ($recognized
                ->selectRaw('COALESCE(SUM(o.subtotal - o.discount_amount), 0) AS revenue')
                ->value('revenue') ?? '0');
        }

        $returnsRevenue = (string) ($this->returnEntries($branchId, 'returns', $periodStart, $periodEnd)
            ->selectRaw('COALESCE(SUM(returns.subtotal - returns.discount_amount), 0) AS revenue')
            ->value('revenue') ?? '0');

        $revenue = BigDecimal::of($recognizedSalesRevenue)->plus(BigDecimal::of($returnsRevenue));

        // COGS follows the same branch dimension as revenue: order-linked sale
        // movements are attributed to the order's allocated branch, so a branch
        // never reports one side of a sale without the other. Non-order sales
        // and returns fall back to the movement branch.
        $saleCogs = DB::table('stock_movements as sm')
            ->where('sm.type', 'sale')
            ->where('sm.created_at', '>=', $periodStart)->where('sm.created_at', '<', $periodEnd)
            ->when($branchId !== null, function ($q) use ($branchId, $orderReferences): void {
                $q->leftJoin('orders as o', function ($join) use ($orderReferences): void {
                    $join->on('o.id', '=', 'sm.reference_id')->whereIn('sm.reference_type', $orderReferences);
                })->where(function ($w) use ($branchId, $orderReferences): void {
                    $w->where(function ($w2) use ($branchId, $orderReferences): void {
                        $w2->whereIn('sm.reference_type', $orderReferences)->where('o.allocated_branch_id', $branchId);
                    })->orWhere(function ($w2) use ($branchId, $orderReferences): void {
                        $w2->whereNotIn('sm.reference_type', $orderReferences)->where('sm.branch_id', $branchId);
                    });
                });
            })
            ->selectRaw('COALESCE(SUM(-sm.quantity * sm.unit_cost), 0) AS c')
            ->first()?->c ?? '0';

        $returnCogs = DB::table('stock_movements as sm')
            ->where('sm.type', 'sale_return')
            ->where('sm.created_at', '>=', $periodStart)->where('sm.created_at', '<', $periodEnd)
            ->when($branchId !== null, fn ($q) => $q->where('sm.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(-sm.quantity * sm.unit_cost), 0) AS c')
            ->first()?->c ?? '0';

        $cogs = BigDecimal::of((string) $saleCogs)->plus(BigDecimal::of((string) $returnCogs));

        $expenses = BigDecimal::of((string) DB::table('expenses')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('voided_at')
            ->whereBetween('expense_date', [$localFrom, $localTo])
            ->sum('amount'));

        $gross = $revenue->minus($cogs);
        $net = $gross->minus($expenses);
        $missingCosts = DB::table('stock_movements as sm')->whereIn('sm.type', ['sale', 'sale_return'])
            ->whereNull('sm.unit_cost')->where('sm.created_at', '>=', $periodStart)->where('sm.created_at', '<', $periodEnd)
            ->when($branchId !== null, function ($q) use ($branchId, $orderReferences): void {
                $q->leftJoin('orders as o', function ($join) use ($orderReferences): void {
                    $join->on('o.id', '=', 'sm.reference_id')->whereIn('sm.reference_type', $orderReferences);
                })->where(function ($w) use ($branchId, $orderReferences): void {
                    $w->where(function ($w2) use ($branchId, $orderReferences): void {
                        $w2->whereIn('sm.reference_type', $orderReferences)->where('o.allocated_branch_id', $branchId);
                    })->orWhere(function ($w2) use ($branchId, $orderReferences): void {
                        $w2->whereNotIn('sm.reference_type', $orderReferences)->where('sm.branch_id', $branchId);
                    });
                });
            })->count();

        return [
            'net_sales_revenue' => $this->moneyFloat($revenue),
            'missing_cost_movements' => $missingCosts,
            'cogs' => $this->moneyFloat($cogs),
            'gross_profit' => $this->moneyFloat($gross),
            'total_expenses' => $this->moneyFloat($expenses),
            'net_profit' => $this->moneyFloat($net),
        ];
    }

    /**
     * Generates supplier debt balances and accounts payable aging buckets.
     *
     * @return list<array<string, mixed>> Supplier balances with aging breakdowns
     */
    public function suppliersReport(): array
    {
        return $this->suppliersReportPage()['data'];
    }

    public function suppliersReportPage(): array
    {
        return StatementBudget::run(150000, function (): array {
            $rows = $this->calculateSuppliersReport();

            return ['data' => array_slice($rows, 0, 500), 'truncated' => count($rows) > 500];
        });
    }

    private function calculateSuppliersReport(): array
    {
        $businessToday = CarbonImmutable::now((string) config('app.business_timezone', 'Africa/Cairo'))->toDateString();

        $aging = DB::table('supplier_invoices')
            ->selectRaw(implode(', ', [
                'supplier_id',
                'COALESCE(SUM(total_amount), 0) AS total_purchases',
                'COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND (due_date IS NULL OR due_date >= ?::date) THEN total_amount - paid_amount END), 0) AS not_due',
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < ?::date AND due_date >= (?::date - INTERVAL '30 days')::date THEN total_amount - paid_amount END), 0) AS overdue_1_30",
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < (?::date - INTERVAL '30 days')::date AND due_date >= (?::date - INTERVAL '60 days')::date THEN total_amount - paid_amount END), 0) AS overdue_31_60",
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < (?::date - INTERVAL '60 days')::date AND due_date >= (?::date - INTERVAL '90 days')::date THEN total_amount - paid_amount END), 0) AS overdue_61_90",
                "COALESCE(SUM(CASE WHEN (total_amount - paid_amount) > 0 AND due_date < (?::date - INTERVAL '90 days')::date THEN total_amount - paid_amount END), 0) AS overdue_90_plus",
            ]), [
                $businessToday,
                $businessToday, $businessToday,
                $businessToday, $businessToday,
                $businessToday, $businessToday,
                $businessToday,
            ])
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->groupBy('supplier_id');

        return DB::table('suppliers as s')
            ->leftJoinSub($aging, 'ag', 'ag.supplier_id', '=', 's.id')
            ->selectRaw('s.id, s.name, s.current_balance, COALESCE(ag.total_purchases, 0) AS total_purchases, COALESCE(ag.not_due, 0) AS not_due, COALESCE(ag.overdue_1_30, 0) AS overdue_1_30, COALESCE(ag.overdue_31_60, 0) AS overdue_31_60, COALESCE(ag.overdue_61_90, 0) AS overdue_61_90, COALESCE(ag.overdue_90_plus, 0) AS overdue_90_plus')
            // Financial importance first: suppliers with any balance always surface
            // ahead of inactive zero-balance rows, even beyond the 500-row cap.
            ->orderByRaw('ABS(s.current_balance) DESC, s.name ASC, s.id ASC')
            ->limit(501)
            ->get()
            ->map(fn ($row): array => [
                'supplier_id' => (int) $row->id,
                'supplier_name' => $row->name,
                'current_balance' => $this->moneyFloat((string) $row->current_balance),
                'total_purchases' => $this->moneyFloat((string) $row->total_purchases),
                'aging' => [
                    'not_due' => $this->moneyFloat((string) $row->not_due),
                    'overdue_1_30' => $this->moneyFloat((string) $row->overdue_1_30),
                    'overdue_31_60' => $this->moneyFloat((string) $row->overdue_31_60),
                    'overdue_61_90' => $this->moneyFloat((string) $row->overdue_61_90),
                    'overdue_90_plus' => $this->moneyFloat((string) $row->overdue_90_plus),
                ],
            ])->all();
    }

    /**
     * Reports POS cash drawer sessions, closing cash reconciliation, and variance tracking.
     *
     * @param  string  $from  Start date (YYYY-MM-DD)
     * @param  string  $to  End date (YYYY-MM-DD)
     * @param  bool  $onlyDifferences  Filter only shifts with non-zero cash variance
     * @param  int|null  $branchId  Branch filter
     * @return array{rows: list<array<string, mixed>>, truncated: bool, totals: array<string, mixed>} Shift report
     */
    public function shiftsReport(string $from, string $to, bool $onlyDifferences = false, ?int $branchId = null): array
    {
        $bounds = $this->periodBounds($from, $to);

        return StatementBudget::run(150000, fn (): array => $this->calculateShiftsReport($bounds, $onlyDifferences, $branchId));
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $bounds
     */
    private function calculateShiftsReport(array $bounds, bool $onlyDifferences = false, ?int $branchId = null): array
    {
        [$periodStart, $periodEnd] = $bounds;
        $base = fn () => DB::table('pos_shifts as ps')->when($branchId !== null, fn ($q) => $q->where('ps.branch_id', $branchId))
            ->leftJoin('users as u', 'u.id', '=', 'ps.user_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ps.branch_id')
            ->where('ps.opened_at', '>=', $periodStart)->where('ps.opened_at', '<', $periodEnd)
            ->when($onlyDifferences, fn ($q) => $q->whereNotNull('ps.cash_difference')->where('ps.cash_difference', '!=', 0));

        $rows = $base()
            ->selectRaw('ps.id, ps.shift_number, ps.user_id, u.name AS cashier_name, ps.branch_id, b.name AS branch_name, ps.status, ps.opened_at, ps.closed_at, ps.cash_sales_total, ps.card_sales_total, ps.cash_returns_total, ps.cash_expenses_total, ps.expected_closing_cash, ps.actual_closing_cash, ps.cash_difference')
            ->orderByDesc('ps.opened_at')
            ->limit(201)
            ->get();
        $truncated = $rows->count() > 200;
        $rows = $rows->take(200)
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
                'cash_sales_total' => $this->moneyFloat((string) ($row->cash_sales_total ?? '0')),
                'card_sales_total' => $this->moneyFloat((string) ($row->card_sales_total ?? '0')),
                'cash_returns_total' => $this->moneyFloat((string) ($row->cash_returns_total ?? '0')),
                'cash_expenses_total' => $this->moneyFloat((string) ($row->cash_expenses_total ?? '0')),
                'expected_closing_cash' => $this->moneyFloat((string) ($row->expected_closing_cash ?? '0')),
                'actual_closing_cash' => $row->actual_closing_cash !== null ? $this->moneyFloat((string) $row->actual_closing_cash) : null,
                'cash_difference' => $row->cash_difference !== null ? $this->moneyFloat((string) $row->cash_difference) : null,
                'has_difference' => $row->cash_difference !== null && ! BigDecimal::of((string) $row->cash_difference)->isZero(),
            ])->all();

        $totals = $base()
            ->selectRaw('COUNT(*) AS shifts_count, COALESCE(SUM(ps.cash_sales_total), 0) AS cash_sales, COALESCE(SUM(ps.card_sales_total), 0) AS card_sales, COALESCE(SUM(CASE WHEN ps.cash_difference IS NOT NULL AND ps.cash_difference != 0 THEN 1 ELSE 0 END), 0) AS shifts_with_difference')
            ->first();

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totals' => [
                'shifts_count' => (int) $totals->shifts_count,
                'cash_sales' => $this->moneyFloat((string) ($totals->cash_sales ?? '0')),
                'card_sales' => $this->moneyFloat((string) ($totals->card_sales ?? '0')),
                'shifts_with_difference' => (int) $totals->shifts_with_difference,
            ],
        ];
    }

    // Combines original sales with POS, RMA, and manual return reversals into a unified signed ledger stream.
    private function salesEntries(?int $branchId, string $alias = 'entries', ?string $periodStart = null, ?string $periodEnd = null): Builder
    {
        $sales = DB::table('orders as o')->whereNull('o.return_of_order_id')
            ->whereIn('o.status', [...self::SALES_STATUSES, 'returned'])
            ->when($periodStart !== null, fn ($q) => $q->where('o.created_at', '>=', $periodStart))
            ->when($periodEnd !== null, fn ($q) => $q->where('o.created_at', '<', $periodEnd))
            ->selectRaw('o.id, o.channel, o.allocated_branch_id, o.pos_shift_id, o.payment_method, o.created_at, o.subtotal, o.discount_amount, o.tax_amount, o.shipping_cost, o.grand_total, 1 AS sale_count');

        [$posReturns, $rmaReturns, $manualReturns] = $this->returnQueries($periodStart, $periodEnd);

        return DB::query()->fromSub($sales->unionAll($posReturns)->unionAll($rmaReturns)->unionAll($manualReturns), $alias)
            ->when($branchId !== null, fn ($q) => $q->where($alias.'.allocated_branch_id', $branchId));
    }

    private function returnEntries(?int $branchId, string $alias, string $periodStart, string $periodEnd): Builder
    {
        [$posReturns, $rmaReturns, $manualReturns] = $this->returnQueries($periodStart, $periodEnd);

        return DB::query()->fromSub($posReturns->unionAll($rmaReturns)->unionAll($manualReturns), $alias)
            ->when($branchId !== null, fn ($q) => $q->where($alias.'.allocated_branch_id', $branchId));
    }

    /**
     * @return array{0: Builder, 1: Builder, 2: Builder}
     */
    private function returnQueries(?string $periodStart, ?string $periodEnd): array
    {
        $posReturns = DB::table('orders as o')->whereNotNull('o.return_of_order_id')
            ->when($periodStart !== null, fn ($q) => $q->where('o.created_at', '>=', $periodStart))
            ->when($periodEnd !== null, fn ($q) => $q->where('o.created_at', '<', $periodEnd))
            ->selectRaw('o.id, o.channel, o.allocated_branch_id, o.pos_shift_id, o.payment_method, o.created_at, -o.subtotal AS subtotal, -o.discount_amount AS discount_amount, -o.tax_amount AS tax_amount, -o.shipping_cost AS shipping_cost, -o.grand_total AS grand_total, 0 AS sale_count');

        // Pro-rate RMA refunds across merchandise, tax, and shipping so refunded tax/shipping does not depress net merchandise revenue.
        $merch = 'GREATEST(o.subtotal - o.discount_amount, 0)';
        $tax = 'GREATEST(o.tax_amount, 0)';
        $ship = 'GREATEST(o.shipping_cost, 0)';
        $merchTax = "({$merch} + {$tax})";
        $totalBase = "({$merch} + {$tax} + {$ship})";

        $rmaSubtotalExpr = "CASE WHEN {$totalBase} <= 0 THEN -r.total_refund_amount WHEN r.total_refund_amount <= {$merchTax} AND {$merchTax} > 0 THEN -ROUND((r.total_refund_amount * ({$merch} / {$merchTax}))::numeric, 2) ELSE -ROUND((r.total_refund_amount * ({$merch} / {$totalBase}))::numeric, 2) END AS subtotal";
        $rmaTaxExpr = "CASE WHEN {$totalBase} <= 0 THEN 0 WHEN r.total_refund_amount <= {$merchTax} AND {$merchTax} > 0 THEN -(r.total_refund_amount - ROUND((r.total_refund_amount * ({$merch} / {$merchTax}))::numeric, 2)) ELSE -ROUND((r.total_refund_amount * ({$tax} / {$totalBase}))::numeric, 2) END AS tax_amount";
        $rmaShipExpr = "CASE WHEN {$totalBase} <= 0 THEN 0 WHEN r.total_refund_amount <= {$merchTax} AND {$merchTax} > 0 THEN 0 ELSE -(r.total_refund_amount - ROUND((r.total_refund_amount * ({$merch} / {$totalBase}))::numeric, 2) - ROUND((r.total_refund_amount * ({$tax} / {$totalBase}))::numeric, 2)) END AS shipping_cost";

        $rmaReturns = DB::table('return_requests as r')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->whereIn('r.status', ['received', 'refunded'])->whereNotNull('r.received_at')
            ->when($periodStart !== null, fn ($q) => $q->where('r.received_at', '>=', $periodStart))
            ->when($periodEnd !== null, fn ($q) => $q->where('r.received_at', '<', $periodEnd))
            ->selectRaw("o.id, o.channel, r.branch_id AS allocated_branch_id, o.pos_shift_id, o.payment_method, r.received_at AS created_at, {$rmaSubtotalExpr}, 0 AS discount_amount, {$rmaTaxExpr}, {$rmaShipExpr}, -r.total_refund_amount AS grand_total, 0 AS sale_count");

        $manualReturns = DB::table('orders as o')->where('o.status', 'returned')->whereNull('o.return_of_order_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders as pos_return')->whereColumn('pos_return.return_of_order_id', 'o.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('return_requests as r')->whereColumn('r.order_id', 'o.id')->whereIn('r.status', ['received', 'refunded']))
            ->whereNotNull('o.returned_at')
            ->when($periodStart !== null, fn ($q) => $q->where('o.returned_at', '>=', $periodStart))
            ->when($periodEnd !== null, fn ($q) => $q->where('o.returned_at', '<', $periodEnd))
            ->selectRaw('o.id, o.channel, o.allocated_branch_id, o.pos_shift_id, o.payment_method, o.returned_at AS created_at, -o.subtotal, -o.discount_amount, -o.tax_amount, -o.shipping_cost, -o.grand_total, 0 AS sale_count');

        return [$posReturns, $rmaReturns, $manualReturns];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function periodBounds(string $from, string $to): array
    {
        $zone = (string) config('app.business_timezone', 'Africa/Cairo');
        $trimmedFrom = trim($from);
        $trimmedTo = trim($to);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $trimmedFrom, $zone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $trimmedTo, $zone);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $trimmedFrom || $end->format('Y-m-d') !== $trimmedTo) {
            throw ValidationException::withMessages(['from' => 'صيغة تاريخ التقرير غير صالحة (المتوقع YYYY-MM-DD).']);
        }
        $start = $start->startOfDay();
        $end = $end->startOfDay();
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['to' => 'فترة التقرير يجب ألا تتجاوز 366 يومًا.']);
        }

        return [$start->utc()->toDateTimeString(), $end->addDay()->utc()->toDateTimeString(), $start->toDateString(), $end->toDateString()];
    }

    private function moneyFloat(BigDecimal|string $value): float
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of($value !== '' ? $value : '0');

        return (float) (string) $decimal->toScale(2, RoundingMode::HalfUp);
    }
}
