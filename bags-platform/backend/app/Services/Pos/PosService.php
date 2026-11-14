<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\InventoryService;
use App\Services\Pricing\PricingService;
use App\Services\Shared\DiscreteQuantity;
use App\Services\Shared\SearchTerm;
use App\Services\Shared\SettingService;

use App\Enums\Orders\OrderStatus;
use App\Enums\Finance\PosShiftStatus;
use App\Enums\Catalog\PriceListType;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Exceptions\RetryableConflict;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use App\Support\ArabicDigits;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core engine for POS shift lifecycle, immediate retail sales, returns, and cash drawer management.
 */
class PosService
{
    public const CATALOG_VERSION_EPOCH_KEY = 'pos:catalog_version:epoch';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PricingService $pricing,
        private readonly TreasuryService $treasury,
        private readonly SettingService $settings,
    ) {}

    public static function invalidateCatalogVersionCache(?int $branchId = null, bool $stockOnly = true): void
    {
        $invalidate = static function () use ($branchId, $stockOnly): void {
            if ($branchId !== null) {
                $epoch = (int) Cache::get(self::CATALOG_VERSION_EPOCH_KEY, 0);
                Cache::forget("pos:catalog_version:{$branchId}:{$epoch}");
                if (! $stockOnly) {
                    Cache::forget("pos:catalog_version:{$branchId}:{$epoch}:structure");
                }

                return;
            }

            if (! Cache::has(self::CATALOG_VERSION_EPOCH_KEY)) {
                Cache::forever(self::CATALOG_VERSION_EPOCH_KEY, 1);
            } else {
                Cache::increment(self::CATALOG_VERSION_EPOCH_KEY);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);
        } else {
            $invalidate();
        }
    }

    /**
     * Opens a new POS shift and drawer for a cashier, transferring opening float from the branch main safe.
     *
     * @param  User  $user  Operating cashier
     * @param  int  $branchId  Operating branch ID
     * @param  int  $treasuryId  POS drawer treasury ID
     * @param  float  $openingCash  Declared opening float amount
     * @return PosShift Created open shift
     *
     * @throws BusinessException If cashier or drawer already has an active shift, or float mismatch
     */
    public function openShift(User $user, int $branchId, int $treasuryId, float $openingCash): PosShift
    {
        return DB::transaction(function () use ($user, $branchId, $treasuryId, $openingCash): PosShift {
            app(BranchAccess::class)->assertBranch($user->id, $branchId);
            app(BranchAccess::class)->lockActiveBranch($branchId);
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! is_finite($openingCash) || $openingCash < 0) {
                throw new BusinessException('العهدة النقدية غير صالحة.');
            }

            $openingCents = $this->minor($openingCash);
            $capCents = $this->minor((float) ($this->settings->get('pos.max_opening_float')
                ?? config('inventory.pos_max_opening_float', 10000)));
            if ($capCents > 0 && $openingCents > $capCents && ! $user->can('pos.override_opening_float')) {
                throw new BusinessException('العهدة الافتتاحية تتجاوز الحد المسموح به؛ يلزم اعتماد مدير.');
            }

            $existingUserShift = PosShift::query()
                ->where('user_id', $user->id)
                ->where('status', PosShiftStatus::Open)
                ->exists();

            if ($existingUserShift) {
                throw new BusinessException('لديك وردية مفتوحة بالفعل. أغلقها أولًا قبل فتح وردية جديدة.');
            }

            // Branch-scoped drawer lookup runs before the busy check so cashiers
            // from other branches get a neutral 404 instead of cross-branch state.
            $treasury = Treasury::query()
                ->where('id', $treasuryId)
                ->where('branch_id', $branchId)
                ->where('type', 'pos_drawer')
                ->where('is_active', true)
                ->firstOrFail();

            $existingDrawerShift = PosShift::query()
                ->where('treasury_id', $treasuryId)
                ->where('status', PosShiftStatus::Open)
                ->exists();

            if ($existingDrawerShift) {
                throw new BusinessException('درج النقدية المحدد مفتوح على وردية أخرى.');
            }

            $mainSafe = Treasury::query()->where('branch_id', $branchId)->where('type', 'main_safe')->where('is_active', true)->orderBy('id')->first();
            $treasuries = $this->treasury->lockTreasuries(array_filter([$treasury->id, $mainSafe?->id]));
            $treasury = $treasuries->get($treasury->id);
            if (PosShift::query()->where('treasury_id', $treasuryId)->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])->exists()) {
                throw new BusinessException('الدرج مرتبط بوردية مفتوحة أو تنتظر الاعتماد.');
            }
            $existingCash = $this->minor($treasury->current_balance);
            if ($existingCash !== 0 && $existingCash !== $openingCents) {
                throw new BusinessException('العهدة لا تطابق رصيد الدرج القائم؛ سوِّ الرصيد قبل فتح الوردية.');
            }
            if ($existingCash === 0 && $openingCents > 0 && $mainSafe === null) {
                throw new BusinessException('يلزم وجود خزنة رئيسية لتمويل عهدة الوردية.');
            }

            $shiftNumber = $this->settings->generateNextNumber('shift');

            $shift = PosShift::create([
                'shift_number' => $shiftNumber,
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'treasury_id' => $treasuryId,
                'status' => PosShiftStatus::Open,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'cash_sales_total' => 0,
                'card_sales_total' => 0,
                'cash_returns_total' => 0,
                'cash_expenses_total' => 0,
                'expected_closing_cash' => $openingCash,
            ]);

            // Fund opening float directly from the branch safe without minting untracked cash.
            if ($existingCash === 0 && $openingCents > 0) {
                $this->treasury->record([
                    'treasury_id' => $mainSafe->id, 'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::TransferOut, 'amount' => -$openingCash,
                    'reference_type' => PosShift::class, 'reference_id' => $shift->id,
                    'created_by' => $user->id, 'notes' => 'تمويل عهدة الوردية '.$shiftNumber,
                ]);
                $this->treasury->record([
                    'treasury_id' => $treasury->id,
                    'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::OpeningBalance,
                    'amount' => $openingCash,
                    'reference_type' => PosShift::class,
                    'reference_id' => $shift->id,
                    'created_by' => $user->id,
                    'notes' => "رصيد فتح وردية #{$shiftNumber}",
                ]);
            }

            return $shift;
        });
    }

    /**
     * Computes live financial totals and delivered invoice count for a shift.
     *
     * @param  PosShift  $s  Target shift
     * @return array{
     *     opening_cash: float,
     *     cash_sales_total: float,
     *     card_sales_total: float,
     *     cash_returns_total: float,
     *     cash_expenses_total: float,
     *     expected_closing_cash: float,
     *     invoices_count: int
     * }
     */
    public function computeShiftTotals(PosShift $s): array
    {
        $salesStats = Order::query()
            ->where('pos_shift_id', $s->id)
            ->where('status', OrderStatus::Delivered)
            ->selectRaw('
                COALESCE(SUM(cash_amount), 0) AS cash_sales,
                COALESCE(SUM(card_amount), 0) AS card_sales
            ')
            ->first();

        // Decimal-only ledger math: every operand arrives as a decimal string
        // from the database, so no binary float ever enters the computation.
        $openingCash = BigDecimal::of((string) ($s->opening_cash ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $cashSales = BigDecimal::of((string) ($salesStats?->cash_sales ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $cardSales = BigDecimal::of((string) ($salesStats?->card_sales ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $cashReturns = BigDecimal::of((string) (Order::query()
            ->where('pos_shift_id', $s->id)
            ->where('status', OrderStatus::Returned)
            ->sum('cash_amount')))->toScale(2, RoundingMode::HalfUp);
        $cashExpenses = BigDecimal::of((string) (Expense::query()
            ->where('shift_id', $s->id)
            ->whereNull('voided_at')
            ->sum('amount')))->toScale(2, RoundingMode::HalfUp);
        $expectedClosingCash = $openingCash
            ->plus($cashSales)
            ->minus($cashReturns)
            ->minus($cashExpenses)
            ->toScale(2, RoundingMode::HalfUp);

        return [
            'opening_cash' => $openingCash->toFloat(),
            'cash_sales_total' => $cashSales->toFloat(),
            'card_sales_total' => $cardSales->toFloat(),
            'cash_returns_total' => $cashReturns->toFloat(),
            'cash_expenses_total' => $cashExpenses->toFloat(),
            'expected_closing_cash' => $expectedClosingCash->toFloat(),
            'invoices_count' => Order::where('pos_shift_id', $s->id)->where('status', OrderStatus::Delivered)->count(),
        ];
    }

    /**
     * Lists active POS drawers for a branch with busy status and current balance.
     * Balance is suppressed (null) for busy drawers unless the viewer can view expected cash.
     *
     * @param  int  $branchId  Target branch ID
     * @param  User|null  $viewer  Requesting staff user
     * @return array<int, array{id: int, name: string, code: string, busy: bool, balance: string|null}>
     */
    public function getAvailableDrawers(int $branchId, ?User $viewer = null): array
    {
        $drawers = Treasury::query()
            ->where('branch_id', $branchId)
            ->where('type', TreasuryType::PosDrawer)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($drawers->isEmpty()) {
            return [];
        }

        $busyIds = PosShift::query()
            ->whereIn('treasury_id', $drawers->pluck('id')->all())
            ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
            ->pluck('treasury_id')
            ->flip();

        $canViewExpected = $viewer?->can('pos_shifts.view_expected')
            ?? request()->user('staff')?->can('pos_shifts.view_expected')
            ?? false;

        return $drawers->map(function (Treasury $drawer) use ($busyIds, $canViewExpected): array {
            $isBusy = $busyIds->has($drawer->id);

            return [
                'id' => $drawer->id,
                'name' => $drawer->name,
                'code' => $drawer->code,
                'busy' => $isBusy,
                'balance' => ($isBusy && ! $canViewExpected) ? null : number_format((float) $drawer->current_balance, 2, '.', ''),
            ];
        })->values()->all();
    }

    /**
     * Computes an MD5 version hash of the branch product catalog and stock levels.
     *
     * The aggregates are O(1) in working memory: COUNT/MAX/SUM over a single
     * pass with a commutative hash accumulator, so no ORDER BY sort and no
     * giant string_agg buffer is ever built in PostgreSQL.
     *
     * @param  int  $branchId  Target branch ID
     * @return string 32-character MD5 hex string
     */
    public function computeCatalogVersion(int $branchId, bool $structureOnly = false): string
    {
        $epoch = (int) Cache::get(self::CATALOG_VERSION_EPOCH_KEY, 0);
        $cacheKey = "pos:catalog_version:{$branchId}:{$epoch}".($structureOnly ? ":structure" : "");
        $now = now();
        $cached = Cache::get($cacheKey);
        if (is_array($cached)
            && isset($cached['version'])
            && ($cached['valid_until'] === null || $now->getTimestamp() < (int) $cached['valid_until'])) {
            return (string) $cached['version'];
        }

        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            $variants = ProductVariant::query()
                ->whereNull('deleted_at')
                ->toBase()
                ->selectRaw("COUNT(*) AS c, MAX(updated_at) AS m, COALESCE(SUM(hashtextextended(concat_ws('|', id, retail_price, wholesale_price, barcode, sku, is_active), 0)), 0) AS h")
                ->first();
            $categories = Category::query()
                ->whereNull('deleted_at')
                ->toBase()
                ->selectRaw("COUNT(*) AS c, MAX(updated_at) AS m, COALESCE(SUM(hashtextextended(concat_ws('|', id, name_ar, name_en, is_active), 0)), 0) AS h")
                ->first();
            $stock = $structureOnly ? null : $this->catalogStockLevels($branchId)->toBase()->selectRaw(
                "COUNT(*) AS c, MAX(updated_at) AS m, COALESCE(SUM(hashtextextended(concat_ws('|', id, product_variant_id, on_hand, available, reorder_level), 0)), 0) AS h"
            )->first();
            $products = Product::query()
                ->where('is_active', true)
                ->whereNotNull('published_at')
                ->whereNull('deleted_at')
                ->toBase()
                ->selectRaw("COUNT(*) AS c, MAX(updated_at) AS m, COALESCE(SUM(hashtextextended(concat_ws('|', id, category_id, name_ar, name_en, is_active), 0)), 0) AS h")
                ->first();
            $pricing = $this->catalogPriceItems($branchId)->toBase()->selectRaw(
                "COUNT(*) AS c, MAX(price_list_items.updated_at) AS m, COALESCE(SUM(hashtextextended(concat_ws('|', price_list_items.id, price_list_items.product_variant_id, price_list_items.min_qty, price_list_items.price), 0)), 0) AS h"
            )->first();
        } else {
            $variants = ProductVariant::query()
                ->whereNull('deleted_at')
                ->toBase()
                ->selectRaw("COUNT(*) AS c, MAX(updated_at) AS m, 0 AS h")
                ->first();
            $categories = Category::query()
                ->whereNull('deleted_at')
                ->toBase()
                ->selectRaw("COUNT(*) AS c, MAX(updated_at) AS m, 0 AS h")
                ->first();
            $stock = $structureOnly ? null : $this->catalogStockLevels($branchId)->toBase()->selectRaw(
                "COUNT(*) AS c, MAX(updated_at) AS m, 0 AS h"
            )->first();
            $products = Product::query()
                ->where('is_active', true)
                ->whereNotNull('published_at')
                ->whereNull('deleted_at')
                ->toBase()
                ->selectRaw("COUNT(*) AS c, MAX(updated_at) AS m, 0 AS h")
                ->first();
            $pricing = $this->catalogPriceItems($branchId)->toBase()->selectRaw(
                "COUNT(*) AS c, MAX(price_list_items.updated_at) AS m, 0 AS h"
            )->first();
        }

        $version = md5(implode('|', [
            $variants->c ?? 0, (string) ($variants->m ?? ''), (string) ($variants->h ?? 0),
            $categories->c ?? 0, (string) ($categories->m ?? ''), (string) ($categories->h ?? 0),
            $stock->c ?? 0, (string) ($stock->m ?? ''), (string) ($stock->h ?? 0),
            $products->c ?? 0, (string) ($products->m ?? ''), (string) ($products->h ?? 0),
            $pricing->c ?? 0, (string) ($pricing->m ?? ''), (string) ($pricing->h ?? 0),
        ]));

        // Respect upcoming scheduled price boundaries within the 10-second cache window.
        // F-13: one UNION per direction instead of four round-trips; the
        // predicates (and the +1s end-boundary grace) are unchanged.
        $windowEnd = $now->copy()->addSeconds(10);
        $startsAt = DB::query()->fromSub(
            DB::table('price_lists')
                ->selectRaw('starts_at AS b')
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $windowEnd)
                ->unionAll(
                    DB::table('price_list_items')
                        ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
                        ->selectRaw('price_list_items.starts_at AS b')
                        ->where('price_lists.is_active', true)
                        ->where(fn ($q) => $q->whereNull('price_lists.branch_id')->orWhere('price_lists.branch_id', $branchId))
                        ->where('price_list_items.starts_at', '>', $now)
                        ->where('price_list_items.starts_at', '<=', $windowEnd)
                ),
            'u'
        )->min('b');
        $endsAt = DB::query()->fromSub(
            DB::table('price_lists')
                ->selectRaw('ends_at AS b')
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->where('ends_at', '>=', $now)
                ->where('ends_at', '<', $windowEnd)
                ->unionAll(
                    DB::table('price_list_items')
                        ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
                        ->selectRaw('price_list_items.ends_at AS b')
                        ->where('price_lists.is_active', true)
                        ->where(fn ($q) => $q->whereNull('price_lists.branch_id')->orWhere('price_lists.branch_id', $branchId))
                        ->where('price_list_items.ends_at', '>=', $now)
                        ->where('price_list_items.ends_at', '<', $windowEnd)
                ),
            'u'
        )->min('b');

        $validUntil = $windowEnd->getTimestamp();
        if ($startsAt !== null) {
            $validUntil = min($validUntil, \Illuminate\Support\Carbon::parse($startsAt)->getTimestamp());
        }
        if ($endsAt !== null) {
            $validUntil = min($validUntil, \Illuminate\Support\Carbon::parse($endsAt)->getTimestamp() + 1);
        }

        Cache::put($cacheKey, [
            'version' => $version,
            'valid_until' => $validUntil,
        ], $windowEnd);

        return $version;
    }

    /**
     * Closes an active POS shift, recalculates expected cash, and records cash difference.
     *
     * @param  PosShift  $shift  Active shift to close
     * @param  float  $actualCash  Physical cash count declared by cashier
     * @param  string|null  $notes  Optional closing remarks
     * @return PosShift Shift moved to pending approval
     *
     * @throws BusinessException If shift is not open or amount is invalid
     */
    public function closeShift(PosShift $shift, float $actualCash, ?string $notes): PosShift
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('يمكن إغلاق الورديات المفتوحة فقط.');
        }

        return DB::transaction(function () use ($shift, $actualCash, $notes): PosShift {
            $shift = $this->lockOpenShift($shift);
            if (! is_finite($actualCash) || $actualCash < 0) {
                throw new BusinessException('النقدية الفعلية غير صالحة.');
            }

            $totals = $this->computeShiftTotals($shift);
            // Decimal compare: re-materialize both sides from their decimal
            // strings so the cent difference is exact, never a float residue.
            $expectedClosing = BigDecimal::of((string) $totals['expected_closing_cash'])->toScale(2, RoundingMode::HalfUp);
            $actualClosing = BigDecimal::of((string) $actualCash)->toScale(2, RoundingMode::HalfUp);
            $cashDifference = $actualClosing->minus($expectedClosing)->toScale(2, RoundingMode::HalfUp);

            // Blind close: notes stay optional for blind cashiers so 422 cannot be
            // used as a binary-search oracle for expected drawer cash. Cashiers who
            // already hold pos_shifts.view_expected still explain non-zero variances.
            if (! $cashDifference->isZero() && trim((string) $notes) === '' && ($shift->cashier?->can('pos_shifts.view_expected') ?? false)) {
                throw new BusinessException('ملاحظات الإغلاق مطلوبة عند وجود فرق في النقدية.');
            }
            $shift->update([
                'status' => PosShiftStatus::ClosedPendingApproval,
                'closed_at' => now(),
                'cash_sales_total' => $totals['cash_sales_total'],
                'card_sales_total' => $totals['card_sales_total'],
                'cash_returns_total' => $totals['cash_returns_total'],
                'cash_expenses_total' => $totals['cash_expenses_total'],
                'expected_closing_cash' => (string) $expectedClosing,
                'actual_closing_cash' => (string) $actualClosing,
                'cash_difference' => (string) $cashDifference,
                'notes' => $notes,
            ]);

            // F-02: closures join the staff audit trail like reopen/force-close.
            $this->auditShift($shift, User::query()->findOrFail($shift->user_id), 'pos_shift.close', ['cash_difference' => (string) $cashDifference]);

            return $shift->fresh();
        });
    }

    /**
     * Administratively approves a closed shift and sweeps drawer cash to the branch main safe.
     *
     * @param  PosShift  $shift  Closed shift awaiting approval
     * @param  User  $approver  Authorizing supervisor/admin
     * @param  string|null  $notes  Optional supervisor reconciliation notes (mandatory if variance != 0 and no cashier note)
     * @return PosShift Approved shift instance
     *
     * @throws BusinessException If shift is not in pending approval state
     */
    public function approveShift(PosShift $shift, User $approver, ?string $notes = null): PosShift
    {
        if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
            throw new BusinessException('يمكن اعتماد الورديات المغلقة المنتظرة للاعتماد فقط.');
        }

        return DB::transaction(function () use ($shift, $approver, $notes): PosShift {
            app(BranchAccess::class)->assertBranch($approver->id, $shift->branch_id);
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
            $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
                throw new BusinessException('الوردية ليست في انتظار الاعتماد.');
            }
            if ((int) $shift->user_id === (int) $approver->id) {
                throw new BusinessException('لا يمكن اعتماد وردية فتحتها بنفسك؛ يلزم اعتماد موظف آخر.');
            }
            // Segregation of duties: the supervisor who force-closed the
            // drawer cannot also approve it; a second supervisor must review.
            if ($shift->force_closed_by !== null && (int) $shift->force_closed_by === (int) $approver->id) {
                throw new BusinessException('لا يمكن اعتماد وردية أغلقتها قسرياً بنفسك؛ يلزم اعتماد مشرف آخر.');
            }

            // Re-verify shift totals under lock so any post-close ledger mutation
            // forces a recount instead of quietly settling stale numbers.
            $totals = $this->computeShiftTotals($shift);
            $recomputedExpected = BigDecimal::of((string) $totals['expected_closing_cash'])->toScale(2, RoundingMode::HalfUp);
            $storedExpected = BigDecimal::of((string) ($shift->expected_closing_cash ?? '0'))->toScale(2, RoundingMode::HalfUp);
            if (! $recomputedExpected->isEqualTo($storedExpected)) {
                throw new BusinessException(
                    'تغير الرصيد المتوقع للوردية بعد الإغلاق؛ يلزم إعادة فتح الوردية ومراجعة الحركات.',
                    [],
                    409,
                );
            }

            $cashDifference = BigDecimal::of((string) ($shift->cash_difference ?? '0'))->toScale(2, RoundingMode::HalfUp);
            $incomingNote = trim((string) ($notes ?? ''));
            $existingNote = trim((string) ($shift->notes ?? ''));
            if (! $cashDifference->isZero() && $incomingNote === '' && $existingNote === '') {
                throw new BusinessException('ملاحظة تسوية فرق النقدية مطلوبة عند اعتماد وردية بها عجز أو زيادة.');
            }

            // Settle the drawer into the main safe BEFORE flipping the status
            // so a zero-zero shift (or any retry) cannot be marked approved
            // without its settlement marker, and repeats stay rejected.
            $this->treasury->settleShiftToMainSafe($shift, $approver->id);

            $mergedNote = $incomingNote !== ''
                ? mb_substr($existingNote !== '' ? "{$existingNote} | {$incomingNote}" : $incomingNote, 0, 500)
                : $shift->notes;

            $shift->update([
                'status' => PosShiftStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $approver->id,
                'notes' => $mergedNote,
            ]);

            // F-02: approvals join the staff audit trail like reopen/force-close.
            $this->auditShift($shift, $approver, 'pos_shift.approve', ['cash_difference' => (string) ($shift->cash_difference ?? '0')]);

            return $shift->fresh();
        });
    }

    /**
     * Reopens a pending shift for recount so the cashier can re-enter physical cash.
     * No cash is settled and the shift returns to the open state.
     *
     * @param  PosShift  $shift  Pending shift
     * @param  User  $approver  Supervisor rejecting the closure
     * @param  string  $reason  Mandatory recount/rejection reason
     * @return PosShift Reopened shift
     *
     * @throws BusinessException If shift is not pending approval
     */
    public function reopenShiftForRecount(PosShift $shift, User $approver, string $reason): PosShift
    {
        if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
            throw new BusinessException('يمكن إعادة فتح الورديات المنتظرة للاعتماد فقط.');
        }

        return DB::transaction(function () use ($shift, $approver, $reason): PosShift {
            app(BranchAccess::class)->assertBranch($approver->id, $shift->branch_id);
            // Strict lock hierarchy: branches -> users -> pos_shifts -> treasuries
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
            User::query()->whereKey($shift->user_id)->lockForUpdate()->firstOrFail();
            $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
            Treasury::query()->whereKey($shift->treasury_id)->lockForUpdate()->firstOrFail();

            if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
                throw new BusinessException('الوردية ليست في انتظار الاعتماد.');
            }
            // F-02: segregation of duties — nobody reopens their own shift,
            // mirroring the approveShift self-ban.
            if ((int) $shift->user_id === (int) $approver->id) {
                throw new BusinessException('لا يمكن إعادة فتح وردية فتحتها بنفسك؛ يلزم مشرف آخر.');
            }

            // Invariant 1: Cashier must NOT have another Open shift
            $hasOtherOpenShift = PosShift::query()
                ->where('user_id', $shift->user_id)
                ->where('id', '!=', $shift->id)
                ->where('status', PosShiftStatus::Open)
                ->exists();

            if ($hasOtherOpenShift) {
                throw new BusinessException('لا يمكن إعادة فتح الوردية؛ لدى الكاشير وردية أخرى مفتوحة حالياً. يجب إغلاقها أولاً.', status: 409);
            }

            // Invariant 2: Drawer must NOT have another active shift
            $hasOtherDrawerShift = PosShift::query()
                ->where('treasury_id', $shift->treasury_id)
                ->where('id', '!=', $shift->id)
                ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
                ->exists();

            if ($hasOtherDrawerShift) {
                throw new BusinessException('لا يمكن إعادة فتح الوردية؛ درج النقدية مرتبط بوردية أخرى نشطة حالياً.', status: 409);
            }

            $shift->update([
                'status' => PosShiftStatus::Open,
                'closed_at' => null,
                'actual_closing_cash' => null,
                'cash_difference' => null,
                // F-02: the force-close taint is preserved on purpose — the
                // supervisor who force-closed still cannot approve the final
                // closure even after a recount reopen.
                'notes' => $reason,
            ]);

            $this->auditShift($shift, $approver, 'pos_shift.reopen_for_recount', ['reason' => $reason]);

            return $shift->fresh();
        });
    }

    /**
     * Force-closes an open shift at the supervisor's physically counted cash
     * when the cashier cannot close it (for example after losing the terminal
     * session). The counted amount is mandatory: assuming expected == actual
     * would silently move phantom cash into the branch main safe.
     *
     * @param  PosShift  $shift  Open shift
     * @param  User  $approver  Supervisor performing the forced close
     * @param  float|string  $actualClosingCash  Physical cash counted by the supervisor
     * @param  string  $reason  Mandatory audit reason
     * @return PosShift Shift awaiting approval
     *
     * @throws BusinessException If shift is not open or the counted cash is invalid
     */
    public function forceCloseShift(PosShift $shift, User $approver, float|string $actualClosingCash, string $reason): PosShift
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('يمكن الإغلاق القسري للورديات المفتوحة فقط.');
        }

        if (is_float($actualClosingCash) && ! is_finite($actualClosingCash)) {
            throw new BusinessException('النقدية الفعلية المجردة غير صالحة.');
        }

        try {
            $actualClosing = BigDecimal::of((string) $actualClosingCash)->toScale(2, RoundingMode::HalfUp);
        } catch (\Throwable) {
            throw new BusinessException('النقدية الفعلية المجردة غير صالحة.');
        }

        if ($actualClosing->isNegative() || $actualClosing->isGreaterThan('999999999999.99')) {
            throw new BusinessException('النقدية الفعلية المجردة غير صالحة.');
        }

        return DB::transaction(function () use ($shift, $approver, $actualClosing, $reason): PosShift {
            $shift = $this->lockOpenShift($shift);
            app(BranchAccess::class)->assertBranch($approver->id, $shift->branch_id);
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
            // F-02: segregation of duties — nobody force-closes their own shift.
            if ((int) $shift->user_id === (int) $approver->id) {
                throw new BusinessException('لا يمكن الإغلاق القسري لوردية فتحتها بنفسك؛ يلزم مشرف آخر.');
            }

            $totals = $this->computeShiftTotals($shift);
            // Decimal-only compare so cent fractions reconcile exactly.
            $expectedClosing = BigDecimal::of((string) $totals['expected_closing_cash'])->toScale(2, RoundingMode::HalfUp);
            $cashDifference = $actualClosing->minus($expectedClosing)->toScale(2, RoundingMode::HalfUp);

            $shift->update([
                'status' => PosShiftStatus::ClosedPendingApproval,
                'closed_at' => now(),
                'cash_sales_total' => $totals['cash_sales_total'],
                'card_sales_total' => $totals['card_sales_total'],
                'cash_returns_total' => $totals['cash_returns_total'],
                'cash_expenses_total' => $totals['cash_expenses_total'],
                'expected_closing_cash' => (string) $expectedClosing,
                'actual_closing_cash' => (string) $actualClosing,
                'cash_difference' => (string) $cashDifference,
                'force_closed_by' => $approver->id,
                'notes' => $reason,
            ]);

            $this->auditShift($shift, $approver, 'pos_shift.force_close', ['reason' => $reason]);

            return $shift->fresh();
        });
    }

    /**
     * Append a POS shift action to the staff audit trail.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function auditShift(PosShift $shift, User $actor, string $action, array $metadata): void
    {
        DB::table('staff_audit_logs')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => PosShift::class,
            'target_id' => $shift->id,
            'metadata' => json_encode($metadata + [
                'shift_number' => $shift->shift_number,
                'ip' => request()->ip(),
                'method' => request()->method(),
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(),
        ]);
    }

    /**
     * Creates an immediate delivered retail POS sale, deducting on-hand stock and registering payment.
     *
     * @param  PosShift  $shift  Active shift
     * @param array{
     *     items: array<array{product_variant_id: int, qty: int, unit_price?: float|null, discount_amount?: float}>,
     *     customer_id?: int|null,
     *     payment_method?: string,
     *     cash_amount?: float,
     *     card_amount?: float,
     *     exchange_credit?: float,
     *     notes?: string|null,
     *     override_price?: bool,
     * } $payload Sale parameters
     * @return Order Created delivered order
     *
     * @throws BusinessException If shift is inactive, insufficient stock, or split payments mismatch
     */
    public function createSale(PosShift $shift, array $payload): Order
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي بيع.');
        }

        return DB::transaction(function () use ($shift, $payload): Order {
            $shift = $this->lockOpenShift($shift);
            $this->treasury->lockTreasuries([(int) $shift->treasury_id]);
            $actor = User::findOrFail($shift->user_id);

            // A hold already consumed by resumeHeld leaves no row behind; the
            // sale proceeds as a normal sale and repeats are guarded by the
            // Idempotency-Key. An existing row is still branch- and
            // owner-checked, then consumed here so a direct (unresumed) sale
            // cannot be replayed against the same parked cart.
            $heldSaleIds = [];
            if (! empty($payload['held_sale_id'])) {
                $heldSaleIds[] = (int) $payload['held_sale_id'];
            }
            if (! empty($payload['held_sale_ids']) && is_array($payload['held_sale_ids'])) {
                foreach ($payload['held_sale_ids'] as $hid) {
                    if ($hid) {
                        $heldSaleIds[] = (int) $hid;
                    }
                }
            }
            $heldSaleIds = array_values(array_unique($heldSaleIds));

            if (! empty($heldSaleIds)) {
                $heldSales = PosHeldSale::query()
                    ->whereIn('id', $heldSaleIds)
                    ->lockForUpdate()
                    ->get();

                if ($heldSales->count() !== count($heldSaleIds)) {
                    throw new BusinessException('إحدى الفواتير المعلقة المطلوبة لم تعد متاحة أو تم استهلاكها مسبقاً.', [], 409);
                }

                foreach ($heldSales as $heldSale) {
                    app(BranchAccess::class)->assertBranch($actor->id, (int) $heldSale->branch_id);
                    if ((int) $heldSale->branch_id !== (int) $shift->branch_id) {
                        throw new BusinessException('الفاتورة المعلقة تخص فرعاً آخر.');
                    }

                    if ($heldSale->resumed_by !== null
                        && (int) $heldSale->resumed_by !== (int) $actor->id
                        && $heldSale->resumed_at !== null
                        && $heldSale->resumed_at->gt(now()->subMinutes(5))) {
                        throw new BusinessException('الفاتورة المعلقة قيد الدفع لدى مستخدم آخر.', [], 409);
                    }

                    if ($heldSale->user_id !== $actor->id && ! $actor->can('pos.held.view_all')) {
                        throw new AuthorizationException('لا تملك صلاحية استئناف فاتورة معلقة لمصرفي آخر.');
                    }

                    $heldSale->delete();
                }
            }
            if (! empty($payload['override_price']) && ! $actor->can('pos.override_price')) {
                throw new AuthorizationException('لا تملك صلاحية تجاوز سعر البيع.');
            }
            if (collect($payload['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0) && ! $actor->can('pos.discount')) {
                throw new AuthorizationException('لا تملك صلاحية تطبيق خصم يدوي.');
            }
            $stockRows = StockLevel::query()->where('branch_id', $shift->branch_id)
                ->whereIn('product_variant_id', array_column($payload['items'], 'product_variant_id'))
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get(['branch_id', 'location_id', 'product_variant_id']);
            $levels = $this->inventory->lockLevels($stockRows->map->getAttributes()->all());
            $customer = isset($payload['customer_id'])
                ? Customer::withTrashed()->find($payload['customer_id'])
                : null;
            if ($customer !== null && ($customer->trashed() || ! $customer->canShop())) {
                throw new BusinessException('العميل المحدد غير صالح أو موقوف.');
            }

            $overridePrice = (bool) ($payload['override_price'] ?? false);
            if ($overridePrice && filled($payload['override_reason'] ?? null)) {
                // The checkout screen sends one reason for the whole ticket;
                // copy it onto each line so resolveItems can validate it.
                $payload['items'] = array_map(
                    fn (array $item): array => [...$item, 'override_reason' => $item['override_reason'] ?? $payload['override_reason']],
                    $payload['items'],
                );
            }
            $items = $this->resolveItems((int) $shift->branch_id, $payload['items'], $customer, $overridePrice, true, $actor);
            $totals = $this->computeCartTotals($items);

            $subtotal = $totals['subtotal'];
            $discountTotal = $totals['discount_total'];
            $grandTotal = $totals['grand_total'];

            // Split payments must reconcile down to the cent against the calculated grand total.
            $grandCents = $this->minor($grandTotal);
            if ($grandCents <= 0) {
                throw new BusinessException('إجمالي الفاتورة يجب أن يكون أكبر من صفر.');
            }
            $creditCents = $this->minor($payload['exchange_credit'] ?? 0);
            if ($creditCents < 0 || $creditCents > $grandCents) {
                throw new BusinessException('رصيد الاستبدال غير صالح.');
            }

            $cashAmount = (float) ($payload['cash_amount'] ?? ($grandCents - $creditCents) / 100);
            $cardAmount = (float) ($payload['card_amount'] ?? 0);

            if ($cashAmount < 0 || $cardAmount < 0) {
                throw new BusinessException('مبالغ الدفع يجب ألا تكون سالبة.');
            }

            $paidCents = $this->minor($cashAmount) + $this->minor($cardAmount);

            if ($paidCents + $creditCents !== $grandCents) {
                throw new BusinessException('مجموع المبالغ (نقدي + بطاقة + رصيد استبدال) لا يساوي إجمالي الفاتورة.');
            }

            $orderNumber = $this->settings->generateNextNumber('pos_invoice');

            // Derive the payment method from the actual tender split instead of
            // trusting a client-supplied value that can disagree with the amounts.
            $paymentMethod = $cashAmount > 0 && $cardAmount > 0
                ? 'mixed'
                : ($cardAmount > 0 ? 'card' : 'cash');

            // POS orders deliver immediately on checkout, bypassing online reservation workflows.
            $order = Order::create([
                'order_number' => $orderNumber,
                'channel' => 'pos',
                'customer_id' => $customer?->id,
                'allocated_branch_id' => $shift->branch_id,
                'pos_shift_id' => $shift->id,
                'status' => OrderStatus::Delivered,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'notes' => $payload['notes'] ?? null,
                'override_reason' => $overridePrice ? ($payload['override_reason'] ?? null) : null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'shipping_cost' => 0,
                'tax_amount' => 0,
                'grand_total' => $grandTotal,
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
                'exchange_credit' => number_format($creditCents / 100, 2, '.', ''),
            ]);

            $orderItemRows = [];
            $now = now();
            foreach ($items as $item) {
                $orderItemRows[] = [
                    'order_id' => $order->id,
                    'product_variant_id' => $item['product_variant_id'],
                    'product_name_snapshot' => $item['product_name_snapshot'],
                    'sku_snapshot' => $item['sku_snapshot'],
                    'allocated_branch_id' => $shift->branch_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'line_total' => $item['line_total'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            OrderItem::insert($orderItemRows);

            foreach ($items as $item) {
                $remaining = (int) round((float) $item['qty'] * 10000);
                foreach ($levels->where('product_variant_id', $item['product_variant_id']) as $level) {
                    $take = min($remaining, (int) round($level->available * 10000));
                    if ($take <= 0) {
                        continue;
                    }
                    $this->inventory->recordMovement([
                        'product_variant_id' => $item['product_variant_id'],
                        'branch_id' => $shift->branch_id,
                        'location_id' => $level->location_id,
                        'type' => StockMovementType::Sale,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => -$take / 10000,
                        'unit_cost' => $item['unit_cost'],
                        'reference_type' => Order::class,
                        'reference_id' => $order->id,
                        'created_by' => $shift->user_id,
                        'notes' => "بيع POS #{$orderNumber}",
                    ], $level);
                    $remaining -= $take;
                }
                if ($remaining > 0) {
                    throw new BusinessException('الرصيد المتاح تغير؛ أعد مراجعة الكميات.');
                }
            }

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Delivered,
                'changed_by_type' => User::class,
                'changed_by_id' => $shift->user_id,
                'notes' => 'بيع POS مباشر',
            ]);

            if ($cashAmount > 0) {
                $this->treasury->record([
                    'treasury_id' => $shift->treasury_id,
                    'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::PosSale,
                    'amount' => $cashAmount,
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'created_by' => $shift->user_id,
                    'notes' => "مبيعات نقدية POS #{$orderNumber}",
                ]);
            }

            self::invalidateCatalogVersionCache((int) $shift->branch_id);

            return $order->load(['items.variant', 'customer']);
        });
    }

    /**
     * Processes full or partial POS returns, restocking items and issuing cash/card refunds.
     *
     * @param  PosShift  $shift  Active shift
     * @param  Order  $originalOrder  Original POS delivered order
     * @param  array<array{product_variant_id: int, qty: int, return_to?: string}>  $returnedItems  Items to return
     * @param  array<array{product_variant_id: int, qty: int}>  $exchangeItems  Exchange items (must be empty; exchanges are separate sales)
     * @return array{return_order_id: int|null, exchange_order_id: null, refund_amount: float}
     *
     * @throws BusinessException If order is unreturnable, quantities exceed sold units, or invalid shift
     */
    public function processReturn(
        PosShift $shift,
        Order $originalOrder,
        array $returnedItems,
        array $exchangeItems = [],
        float $exchangeCredit = 0.0
    ): array {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي مرتجع.');
        }

        if ($originalOrder->channel !== 'pos') {
            throw new BusinessException('يمكن مرتجع طلبات POS فقط من شاشة الـ POS.');
        }

        return DB::transaction(function () use ($shift, $originalOrder, $returnedItems, $exchangeItems, $exchangeCredit): array {
            $shift = $this->lockOpenShift($shift);
            $this->lockRefundChain($originalOrder);
            $this->treasury->lockTreasuries([(int) $shift->treasury_id]);
            /** @var Order $originalOrder */
            $originalOrder = Order::query()->lockForUpdate()->findOrFail($originalOrder->id);
            $originalOrder->load('items');
            app(BranchAccess::class)->assertBranch($shift->user_id, (int) $originalOrder->allocated_branch_id);
            if ($originalOrder->channel !== 'pos') {
                throw new BusinessException('يمكن مرتجع طلبات POS فقط من شاشة الـ POS.');
            }

            if ($originalOrder->status !== OrderStatus::Delivered) {
                throw new BusinessException('لا يمكن إرجاع طلب POS غير مكتمل.');
            }

            $returnWindowDays = (int) ($this->settings->get('pos.return_window_days')
                ?? config('inventory.pos_return_window_days', 30));
            if ($returnWindowDays > 0
                && $originalOrder->created_at !== null
                && $originalOrder->created_at->lt(now()->subDays($returnWindowDays))) {
                throw new BusinessException("انتهت مدة الإرجاع المسموحة ({$returnWindowDays} يومًا) لهذه الفاتورة.");
            }

            if ($originalOrder->getAttribute('return_of_order_id') !== null) {
                throw new BusinessException('لا يمكن إرجاع طلب مرتجع.');
            }

            if (! empty($exchangeItems)) {
                throw new BusinessException('الاستبدال يتم كعملية بيع مستقلة بعد المرتجع، وليس ضمن نفس الطلب.');
            }

            $returnValue = 0.0;
            $returnOrderId = null;

            if (! empty($returnedItems)) {
                $grouped = [];
                foreach ($returnedItems as $ret) {
                    $vid = (int) $ret['product_variant_id'];
                    $qty = (float) $ret['qty'];
                    DiscreteQuantity::assertWhole($qty, false, 'كمية المرتجع يجب أن تكون موجبة.');
                    $destination = $ret['return_to'] ?? 'on_hand';
                    if (! in_array($destination, ['on_hand', 'damaged'], true)
                        || (isset($grouped[$vid]) && $grouped[$vid]['return_to'] !== $destination)) {
                        throw new BusinessException('حدد حالة واحدة صحيحة لكل صنف مرتجع.');
                    }
                    if (! isset($grouped[$vid])) {
                        $grouped[$vid] = ['qty' => 0.0, 'return_to' => $ret['return_to'] ?? 'on_hand'];
                    }
                    $grouped[$vid]['qty'] += $qty;
                }

                $previousQuantities = StockMovement::query()
                    ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                    ->where('reference_id', $originalOrder->id)->where('type', StockMovementType::SaleReturn)
                    ->selectRaw('product_variant_id, SUM(quantity) AS qty')->groupBy('product_variant_id')->pluck('qty', 'product_variant_id');
                $previousRefunds = DB::table('order_items as ri')->join('orders as ro', 'ro.id', '=', 'ri.order_id')
                    ->where('ro.return_of_order_id', $originalOrder->id)
                    ->whereNull('ro.deleted_at')
                    ->where('ro.status', OrderStatus::Returned)
                    ->selectRaw('ri.product_variant_id, SUM(ri.qty) AS qty, SUM(ri.line_total) AS amount')
                    ->groupBy('ri.product_variant_id')->get()->keyBy('product_variant_id');
                foreach ($previousQuantities as $variantId => $quantity) {
                    if ((float) $quantity > (float) ($previousRefunds->get($variantId)?->qty ?? 0) + 0.00001) {
                        // Unlinked historical returns cannot be reliably split between cash and card; abort to avoid duplicate refunds.
                        throw new BusinessException('للفاتورة مرتجعات تاريخية غير مرتبطة؛ راجع ربط مستندات الرد بالأصل قبل رد مبلغ جديد.', [], 409);
                    }
                }

                $lines = [];
                foreach ($grouped as $variantId => $g) {
                    $matching = $originalOrder->items->where('product_variant_id', $variantId);
                    if ($matching->isEmpty()) {
                        throw new BusinessException("الصنف {$variantId} غير موجود في الطلب الأصلي.");
                    }

                    $soldQty = (float) $matching->sum('qty');
                    $soldNet = round((float) $matching->sum('line_total'), 2);
                    $netUnit = $soldQty > 0 ? $soldNet / $soldQty : 0.0;

                    $alreadyReturned = (float) $previousQuantities->get($variantId, 0);
                    $remaining = $soldQty - $alreadyReturned;
                    if ($g['qty'] > $remaining + 1e-9) {
                        throw new BusinessException("كمية المرتجع ({$g['qty']}) تتجاوز المتبقي القابل للإرجاع ({$remaining}).");
                    }

                    $previousRefund = $previousRefunds->get($variantId)?->amount ?? 0;
                    $targetCents = (int) round(($alreadyReturned + $g['qty']) * $this->minor($soldNet) / $soldQty);
                    $lineRefund = max(0, min($this->minor($soldNet), $targetCents) - $this->minor($previousRefund)) / 100;
                    $returnValue = round($returnValue + $lineRefund, 2);

                    $first = $matching->first();
                    $lines[$variantId] = [
                        'qty' => $g['qty'],
                        'return_to' => $g['return_to'],
                        'net_unit' => $netUnit,
                        'line_refund' => $lineRefund,
                        'product_name_snapshot' => $first->product_name_snapshot,
                        'sku_snapshot' => $first->sku_snapshot,
                    ];
                }

                if ($returnValue < 0) {
                    throw new BusinessException('قيمة المرتجع لا يمكن أن تكون سالبة.');
                }

                // Cash refund cannot exceed the net physical cash paid on the original order.
                $creditCents = min(max(0, $this->minor($exchangeCredit)), $this->minor($returnValue));
                $credit = $creditCents / 100;
                $netCents = $this->minor($returnValue) - $creditCents;

                [$cashCap, $cardCap] = $this->chainTenderCaps($originalOrder);
                $cardCents = min(max(0, $cardCap - $creditCents), $netCents);
                $cashCents = min($cashCap, $netCents - $cardCents);
                // Unfunded remainder stays out of the cash drawer for review.
                $cashRefund = $cashCents / 100;
                $cardRefund = ($netCents - $cashCents) / 100;

                $variantIds = array_keys($lines);
                $saleMovementsByVariant = StockMovement::query()
                    ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                    ->where('reference_id', $originalOrder->id)
                    ->whereIn('product_variant_id', $variantIds)
                    ->where('type', StockMovementType::Sale)
                    ->orderBy('id')
                    ->get(['product_variant_id', 'quantity', 'unit_cost', 'location_id', 'branch_id'])
                    ->groupBy('product_variant_id');
                $variantCostFallbacks = ProductVariant::withTrashed()
                    ->whereIn('id', $variantIds)
                    ->pluck('cost_price', 'id');

                $returnedByLocation = StockMovement::query()
                    ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                    ->where('reference_id', $originalOrder->id)
                    ->where('type', StockMovementType::SaleReturn)
                    ->where('branch_id', $shift->branch_id)
                    ->whereIn('product_variant_id', $variantIds)
                    ->get(['product_variant_id', 'location_id', 'quantity'])
                    ->groupBy('product_variant_id');

                $activeLocations = Location::query()->where('branch_id', $shift->branch_id)->where('is_active', true)
                    ->whereIn('id', $saleMovementsByVariant->flatten(1)->pluck('location_id')->filter()->unique())
                    ->pluck('id')->flip();
                $destinations = [];
                foreach ($lines as $variantId => $line) {
                    $remaining = (int) $line['qty'];
                    $soldByLocation = $saleMovementsByVariant->get($variantId, collect())
                        ->where('branch_id', $shift->branch_id)
                        ->filter(fn (StockMovement $movement) => $movement->location_id === null || $activeLocations->has($movement->location_id))
                        ->groupBy('location_id');
                    $alreadyReturned = $returnedByLocation->get($variantId, collect())->groupBy('location_id');
                    foreach ($soldByLocation as $locationId => $movements) {
                        $capacity = -$movements->sum('quantity') - ($alreadyReturned->get($locationId)?->sum('quantity') ?? 0);
                        $quantity = min($remaining, max(0, (int) $capacity));
                        if ($quantity > 0) {
                            $destinations[] = [$variantId, $locationId === '' ? null : (int) $locationId, $quantity];
                            $remaining -= $quantity;
                        }
                    }
                    if ($remaining > 0) {
                        $destinations[] = [$variantId, $this->inventory->receivingLocation((int) $shift->branch_id), $remaining];
                    }
                }

                $levels = $this->inventory->lockLevels(array_map(fn ($destination) => [
                    'product_variant_id' => $destination[0],
                    'branch_id' => $shift->branch_id,
                    'location_id' => $destination[1],
                ], $destinations));

                foreach ($destinations as [$variantId, $targetLocationId, $quantity]) {
                    $line = $lines[$variantId];
                    $base = [
                        'product_variant_id' => $variantId,
                        'branch_id' => $shift->branch_id,
                        'location_id' => $targetLocationId,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $quantity,
                        'reference_type' => Order::class,
                        'reference_id' => $originalOrder->id,
                        'created_by' => $shift->user_id,
                        'notes' => "مرتجع POS من #{$originalOrder->order_number}",
                    ];
                    $level = $levels->get($this->inventory->levelKey($base));
                    $saleCost = $this->resolveWeightedSaleCost(
                        $saleMovementsByVariant->get($variantId, collect()),
                        $variantCostFallbacks->get($variantId),
                    );
                    $this->inventory->recordMovement([...$base, 'unit_cost' => $saleCost], $level);
                    if ($line['return_to'] === 'damaged') {
                        // Damaged units remain physically on hand and are excluded from available stock.
                        $this->inventory->recordMovement([...$base, 'type' => StockMovementType::Damage, 'affected_bucket' => StockBucket::Damaged, 'unit_cost' => $saleCost], $level);
                    }
                }

                $returnOrderNumber = $this->settings->generateNextNumber('pos_invoice');
                $returnAttrs = [
                    'order_number' => $returnOrderNumber,
                    'channel' => 'pos',
                    'customer_id' => $originalOrder->customer_id,
                    'allocated_branch_id' => $shift->branch_id,
                    'pos_shift_id' => $shift->id,
                    'status' => OrderStatus::Returned,
                    'payment_method' => $originalOrder->payment_method,
                    'payment_status' => 'refunded',
                    'subtotal' => $returnValue,
                    'discount_amount' => 0,
                    'shipping_cost' => 0,
                    'tax_amount' => 0,
                    'grand_total' => $returnValue,
                    'cash_amount' => $cashRefund,
                    'card_amount' => $cardRefund,
                    // Credit consumed against the replacement sale: the tender
                    // invariant reads cash + card + credit == grand total.
                    'exchange_credit' => number_format($credit, 2, '.', ''),
                ];
                $returnAttrs['return_of_order_id'] = $originalOrder->id;
                $returnAttrs['requires_refund'] = $returnAttrs['card_amount'] > 0;
                $returnAttrs['payment_status'] = $returnAttrs['card_amount'] > 0 ? 'refund_pending' : 'refunded';
                $returnOrder = Order::create($returnAttrs);

                $orderItemRows = [];
                $now = now();
                foreach ($lines as $variantId => $line) {
                    $orderItemRows[] = [
                        'order_id' => $returnOrder->id,
                        'product_variant_id' => $variantId,
                        'product_name_snapshot' => $line['product_name_snapshot'],
                        'sku_snapshot' => $line['sku_snapshot'],
                        'allocated_branch_id' => $shift->branch_id,
                        'qty' => $line['qty'],
                        'unit_price' => round($line['net_unit'], 2),
                        'discount_amount' => 0,
                        'line_total' => $line['line_refund'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                OrderItem::insert($orderItemRows);

                foreach ($grouped as $variantId => $g) {
                    $remaining = (float) $previousQuantities->get($variantId, 0) + $g['qty'];
                    foreach ($originalOrder->items->where('product_variant_id', $variantId)->sortBy('id') as $item) {
                        $take = min($remaining, (float) $item->qty);
                        $item->update(['returned_qty' => $take]);
                        $remaining -= $take;
                    }
                }

                OrderStatusHistory::create([
                    'order_id' => $returnOrder->id,
                    'from_status' => null,
                    'to_status' => OrderStatus::Returned,
                    'changed_by_type' => User::class,
                    'changed_by_id' => $shift->user_id,
                    'notes' => "مرتجع من #{$originalOrder->order_number}",
                ]);

                if ($cashRefund > 0) {
                    $this->treasury->record([
                        'treasury_id' => $shift->treasury_id,
                        'shift_id' => $shift->id,
                        'type' => TreasuryTransactionType::PosReturn,
                        'amount' => -$cashRefund,
                        'reference_type' => Order::class,
                        'reference_id' => $returnOrder->id,
                        'created_by' => $shift->user_id,
                        'notes' => "رد مرتجع #{$returnOrderNumber}",
                    ]);
                }

                $returnOrderId = $returnOrder->id;
                self::invalidateCatalogVersionCache((int) $shift->branch_id);
            }

            return [
                'return_order_id' => $returnOrderId,
                'exchange_order_id' => null,
                'refund_amount' => $returnValue,
                'cash_refund_amount' => $cashRefund,
                'card_refund_amount' => $cardRefund,
                'exchange_credit' => $credit,
            ];
        });
    }

    /**
     * Atomically processes a POS item exchange inside a single database transaction under shift & order locks.
     *
     * @param  array<string, mixed>  $data
     */
    public function processExchange(PosShift $shift, Order $originalOrder, User $actor, array $data): Order
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي استبدال.');
        }

        if ($originalOrder->channel !== 'pos') {
            throw new BusinessException('يمكن استبدال طلبات POS فقط من شاشة الـ POS.');
        }

        return DB::transaction(function () use ($shift, $originalOrder, $actor, $data): Order {
            $shift = $this->lockOpenShift($shift);
            $this->lockRefundChain($originalOrder);
            $this->treasury->lockTreasuries([(int) $shift->treasury_id]);
            /** @var Order $lockedOriginal */
            $lockedOriginal = Order::query()->lockForUpdate()->with('items')->findOrFail($originalOrder->id);
            app(BranchAccess::class)->assertBranch($actor->id, (int) $lockedOriginal->allocated_branch_id);

            $returnEstimate = $this->estimateReturnValue($lockedOriginal, $data['returned_items']);

            // If original customer was soft-deleted or suspended, detach them from replacement sale
            // so the exchange proceeds with standard retail pricing rather than failing.
            $customer = null;
            if ($lockedOriginal->customer_id) {
                $existingCustomer = Customer::withTrashed()->find($lockedOriginal->customer_id);
                if ($existingCustomer !== null && ! $existingCustomer->trashed() && $existingCustomer->canShop()) {
                    $customer = $existingCustomer;
                }
            }

            // Legacy callers omitted the flag when sending an override.
            $hasOverrides = $data['override_price'] ?? collect($data['exchange_items'])->some(
                fn (array $item): bool => isset($item['unit_price'])
            );

            if ($hasOverrides && trim((string) ($data['override_reason'] ?? '')) === '') {
                throw new BusinessException('سبب تجاوز السعر مطلوب.');
            }

            $quote = $this->quote(
                (int) $shift->branch_id,
                $data['exchange_items'],
                $customer,
                $hasOverrides,
                $actor,
            );

            $replacementTotal = (float) $quote['grand_total'];
            $credit = min($returnEstimate, $replacementTotal);

            $replacementCents = $this->minor($replacementTotal);
            $creditCents = $this->minor($credit);
            $expectedNetCents = max(0, $replacementCents - $creditCents);
            $cashAmount = (float) ($data['cash_amount'] ?? 0);
            $cardAmount = (float) ($data['card_amount'] ?? 0);
            if ($cashAmount < 0 || $cardAmount < 0) {
                throw new BusinessException('مبالغ الدفع يجب ألا تكون سالبة.');
            }
            if ($this->minor($cashAmount) + $this->minor($cardAmount) !== $expectedNetCents) {
                throw new BusinessException('مجموع المبالغ (نقدي + بطاقة + رصيد استبدال) لا يساوي إجمالي الفاتورة.');
            }

            $exchangePayload = [
                'items' => $data['exchange_items'],
                'customer_id' => $customer?->id,
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
                'exchange_credit' => $credit,
                'override_price' => $hasOverrides,
                'override_reason' => $hasOverrides ? ($data['override_reason'] ?? null) : null,
            ];

            $saleFirst = $replacementTotal >= $returnEstimate;
            $createSale = fn (): Order => $this->createSale($shift, $exchangePayload);

            if ($saleFirst) {
                $exchangeOrder = $createSale();
                $result = $this->processReturn($shift, $lockedOriginal, $data['returned_items'], [], $credit);
            } else {
                $result = $this->processReturn($shift, $lockedOriginal, $data['returned_items'], [], $credit);
                $exchangeOrder = $createSale();
            }

            $returnOrder = Order::findOrFail($result['return_order_id']);
            $returnOrder->update(['exchange_order_id' => $exchangeOrder->id]);

            return $returnOrder->fresh();
        });
    }

    /**
     * Resolves weighted average unit_cost from historical sale movements, falling back to variant cost_price.
     *
     * @param  Collection<int, StockMovement>  $movements
     */
    private function resolveWeightedSaleCost(Collection $movements, mixed $fallbackCost): float|string|null
    {
        $nonNull = $movements->filter(fn (StockMovement $m): bool => $m->unit_cost !== null)->values();
        if ($nonNull->isEmpty()) {
            return $fallbackCost;
        }
        if ($nonNull->count() === 1) {
            return $nonNull->first()->unit_cost;
        }

        $weightedSum = BigDecimal::zero();
        $totalQty = BigDecimal::zero();
        foreach ($nonNull as $movement) {
            $qty = BigDecimal::of((string) $movement->quantity)->abs();
            $cost = BigDecimal::of((string) $movement->unit_cost);
            $weightedSum = $weightedSum->plus($qty->multipliedBy($cost));
            $totalQty = $totalQty->plus($qty);
        }

        if ($totalQty->isZero()) {
            return $fallbackCost;
        }

        return (string) $weightedSum->dividedBy($totalQty, 4, RoundingMode::HalfUp);
    }

    /**
     * Estimates the cash value that would be refunded for the given lines
     * without mutating the order. Used by exchanges to pick a safe ordering.
     *
     * @param  array<int, array<string, mixed>>  $returnedItems
     */
    public function estimateReturnValue(Order $order, array $returnedItems): float
    {
        $order->loadMissing('items');

        $grouped = [];
        foreach ($returnedItems as $ret) {
            $variantId = (int) $ret['product_variant_id'];
            $grouped[$variantId] = ($grouped[$variantId] ?? 0.0) + (float) $ret['qty'];
        }

        $previousQuantities = StockMovement::query()
            ->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
            ->where('reference_id', $order->id)->where('type', StockMovementType::SaleReturn)
            ->selectRaw('product_variant_id, SUM(quantity) AS qty')->groupBy('product_variant_id')
            ->pluck('qty', 'product_variant_id');
        $previousRefunds = DB::table('order_items as ri')
            ->join('orders as ro', 'ro.id', '=', 'ri.order_id')
            ->where('ro.return_of_order_id', $order->id)
            ->whereNull('ro.deleted_at')
            ->where('ro.status', OrderStatus::Returned)
            ->selectRaw('ri.product_variant_id, SUM(ri.qty) AS qty, SUM(ri.line_total) AS amount')
            ->groupBy('ri.product_variant_id')->get()->keyBy('product_variant_id');

        $total = 0.0;
        foreach ($grouped as $variantId => $qty) {
            $matching = $order->items->where('product_variant_id', $variantId);
            $soldQty = (float) $matching->sum('qty');
            if ($matching->isEmpty() || $soldQty <= 0) {
                continue;
            }

            $soldNet = round((float) $matching->sum('line_total'), 2);
            $alreadyReturned = (float) $previousQuantities->get($variantId, 0);
            $previousRefund = $previousRefunds->get($variantId)?->amount ?? 0;
            $targetCents = (int) round(($alreadyReturned + $qty) * $this->minor($soldNet) / $soldQty);
            $total += max(0, min($this->minor($soldNet), $targetCents) - $this->minor($previousRefund)) / 100;
        }

        return round($total, 2);
    }

    /**
     * Find the connected exchange chain in batches. Each replacement is
     * created after its original sale, so the lowest sale ID is stable.
     *
     * @return Collection<int, Order>
     */
    private function refundChain(Order $originalOrder): Collection
    {
        $orders = new Collection;
        $frontier = [$originalOrder->id];

        while ($frontier !== []) {
            $batch = Order::withTrashed()
                ->whereNotIn('id', $orders->keys()->all())
                ->where(fn ($query) => $query->whereIn('id', $frontier)
                    ->orWhereIn('return_of_order_id', $frontier)
                    ->orWhereIn('exchange_order_id', $frontier))
                ->get();
            $orders = $orders->union($batch->keyBy('id'));
            $frontier = $batch->flatMap(fn (Order $order) => [
                $order->id, $order->return_of_order_id, $order->exchange_order_id,
            ])->filter()->unique()->values()->all();
        }

        return $orders;
    }

    private function lockRefundChain(Order $originalOrder): void
    {
        $lockedIds = [];

        for (;;) {
            $chainIds = $this->refundChain($originalOrder)
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($chainIds === [] || $chainIds === $lockedIds) {
                return;
            }

            Order::withTrashed()
                ->whereKey($chainIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $lockedIds = $chainIds;
        }
    }

    /** @return array{int, int} Remaining cash and card tender in cents. */
    private function chainTenderCaps(Order $originalOrder): array
    {
        $cash = 0;
        $card = 0;

        foreach ($this->refundChain($originalOrder) as $order) {
            $sale = $order->return_of_order_id === null;
            if ($order->trashed() || $order->status !== ($sale ? OrderStatus::Delivered : OrderStatus::Returned)) {
                continue;
            }

            // Exchange credit is internal funding. Count only actual tender
            // collected or actually refunded so that credit is never counted twice.
            $direction = $sale ? 1 : -1;
            $cash += $direction * $this->minor($order->cash_amount);
            $card += $direction * $this->minor($order->card_amount);
        }

        return [max(0, $cash), max(0, $card)];
    }

    /**
     * Parks an in-progress sale payload to free the POS terminal.
     *
     * @param  PosShift  $shift  Active shift
     * @param  array<string, mixed>  $payload  Uncompleted cart payload
     * @return PosHeldSale Created held sale record
     */
    public function holdSale(PosShift $shift, array $payload): PosHeldSale
    {
        return DB::transaction(function () use ($shift, $payload): PosHeldSale {
            $shift = $this->lockOpenShift($shift);
            // A plain parked snapshot may outlive its catalog entry. Only
            // carts carrying price adjustments need a live pricing check here.
            if (collect($payload['items'])->contains(fn (array $item): bool =>
                ! empty($item['manual_price']) || (float) ($item['discount_amount'] ?? 0) > 0
            )) {
                $actor = User::query()->findOrFail($shift->user_id);
                $customer = isset($payload['customer_id']) ? Customer::query()->find($payload['customer_id']) : null;
                $items = array_map(static function (array $item): array {
                    if (empty($item['manual_price'])) {
                        unset($item['unit_price']);
                    }

                    return $item;
                }, $payload['items']);
                $this->resolveItems((int) $shift->branch_id, $items, $customer, true, false, $actor);
            }

            for ($attempt = 1; ; $attempt++) {
                try {
                    return DB::transaction(fn () => PosHeldSale::create([
                        'hold_reference' => 'HOLD-'.strtoupper(Str::random(8)),
                        'branch_id' => $shift->branch_id,
                        'user_id' => $shift->user_id,
                        'customer_id' => $payload['customer_id'] ?? null,
                        'items_payload' => $payload['items'],
                        'notes' => $payload['notes'] ?? null,
                        'created_at' => now(),
                    ]));
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    if ($attempt === 5) {
                        throw new RetryableConflict('تعذر إنشاء رقم فريد للفاتورة المعلقة؛ أعد المحاولة.', $e);
                    }
                }
            }
        });
    }

    /**
     * High-speed product variant lookup matching barcode, SKU, or name with branch inventory availability.
     *
     * @param  string  $query  Search query term
     * @param  int  $branchId  Branch ID
     * @param  Customer|null  $customer  Optional customer for targeted pricing
     * @return array<int, array<string, mixed>> Matching variants with live price and stock
     */
    public function lookupProducts(string $query, int $branchId, ?Customer $customer = null): array
    {
        if ($customer !== null && ! $customer->canShop()) {
            $customer = null;
        }

        $trimmed = trim($query);
        $normalized = ArabicDigits::normalize($trimmed);

        $baseQuery = ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereNotNull('published_at'))
            ->where('is_active', true);

        $exactCodes = array_values(array_unique(array_filter([$trimmed, $normalized], fn ($v) => $v !== '')));

        $variants = $exactCodes !== []
            ? (clone $baseQuery)
                ->where(function ($q) use ($exactCodes): void {
                    $q->whereIn('barcode', $exactCodes)
                        ->orWhereIn('sku', $exactCodes);
                })
                ->limit(20)
                ->get()
            : collect();

        // Only run the unindexed LIKE scan on product names when exact barcode/SKU lookup
        // missed and the query is not a purely numeric barcode scan.
        if ($variants->isEmpty() && $normalized !== '' && ! ctype_digit($normalized)) {
            $like = SearchTerm::pattern(mb_strtolower($trimmed));
            $variants = (clone $baseQuery)
                ->whereHas('product', fn ($pq) => $pq->whereRaw('LOWER(name_ar) LIKE ? OR LOWER(name_en) LIKE ?', [$like, $like]))
                ->limit(20)
                ->get();
        }

        $variantIds = $variants->pluck('id')->all();
        $stockByVariant = ! empty($variantIds)
            ? StockLevel::query()
                ->where('branch_id', $branchId)
                ->whereIn('product_variant_id', $variantIds)
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get()
                ->groupBy('product_variant_id')
            : collect();

        $result = [];
        foreach ($variants as $variant) {
            $stock = $stockByVariant->get($variant->id, collect());

            try {
                $priceData = $this->pricing->resolve($variant, $customer, 1, $branchId);
            } catch (\Throwable) {
                $priceData = ['unit_price' => null, 'source' => 'unpriced', 'price_list_id' => null];
            }

            $result[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'product_name' => $variant->product->name_ar ?? $variant->product->name_en,
                'variant_name' => $variant->color_name_ar ?? $variant->color_name_en,
                'on_hand' => (float) $stock->sum('on_hand'),
                'available' => (float) $stock->sum('available'),
                'unit_price' => $priceData['unit_price'],
                'price_source' => $priceData['source'],
                'price_list_id' => $priceData['price_list_id'],
            ];
        }

        return $result;
    }

    /**
     * Retrieves the active branch product catalog with aggregated stock and batch-resolved retail/wholesale prices.
     *
     * Supports cursor pagination when total active variants exceed 3000 or when a cursor is explicitly provided.
     *
     * @param  int  $branchId  Target branch ID
     * @param  string  $catalogVersion  Pre-computed catalog version hash
     * @param  string|null  $cursor  Optional pagination cursor
     * @return array{data: list<array<string, mixed>>, meta: array{catalog_version: string, next_cursor: string|null}}
     */
    public function getBranchCatalog(int $branchId, string $catalogVersion, ?string $cursor = null): array
    {
        $baseQuery = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereNotNull('published_at'))
            // F-13: only the images collection is ever rendered (first image
            // per row), so other collections never leave the database.
            ->with([
                'product.media' => fn ($q) => $q->where('collection_name', 'images')->orderBy('order_column'),
                'media' => fn ($q) => $q->where('collection_name', 'images')->orderBy('order_column'),
            ])
            ->orderBy('id');

        // F-13: fixed paging on every page. The old <=3000 single-fetch mode
        // hydrated thousands of Eloquent + Spatie media models per catalog
        // refresh; catalogs up to one page are byte-identical to before.
        if ($cursor !== null && $cursor !== '') {
            $paginator = $baseQuery->cursorPaginate(500, ['*'], 'cursor', $cursor);
        } else {
            $paginator = $baseQuery->cursorPaginate(500);
        }
        $variants = collect($paginator->items());
        $nextCursor = $paginator->nextCursor()?->encode();

        if ($variants->isEmpty()) {
            return [
                'data' => [],
                'meta' => [
                    'catalog_version' => $catalogVersion,
                    'next_cursor' => null,
                ],
            ];
        }

        $variantIds = $variants->pluck('id')->all();

        $stockByVariant = $this->catalogStockLevels($branchId)
            ->whereIn('product_variant_id', $variantIds)
            ->selectRaw('product_variant_id, SUM(on_hand) AS on_hand, SUM(available) AS available, MAX(reorder_level) AS reorder_level')
            ->groupBy('product_variant_id')
            ->toBase()->get()->keyBy('product_variant_id');

        $priceListItemsByVariant = $this->catalogPriceItems($branchId)
            ->select('price_list_items.*', 'price_lists.type as pricing_type', 'price_lists.branch_id as pricing_branch_id', 'price_lists.priority as pricing_priority')
            ->whereIn('price_list_items.product_variant_id', $variantIds)
            ->orderByRaw('price_lists.branch_id IS NULL ASC')
            ->orderByDesc('price_list_items.min_qty')
            ->orderByDesc('price_lists.priority')
            ->orderBy('price_list_items.price')
            ->orderBy('price_list_items.id')
            ->get()
            ->groupBy('product_variant_id');

        $data = [];
        foreach ($variants as $variant) {
            /** @var ProductVariant $variant */
            $stock = $stockByVariant->get($variant->id);
            $onHand = (float) ($stock->on_hand ?? 0);
            $available = (float) ($stock->available ?? 0);

            $rowReorderMax = (float) ($stock->reorder_level ?? 0);
            $reorderPoint = $rowReorderMax > 0 ? $rowReorderMax : (float) ($variant->reorder_level ?? 0);

            $candidates = $priceListItemsByVariant->get($variant->id, collect());
            $retailMatch = $candidates->firstWhere('pricing_type', PriceListType::Retail->value);
            $wholesaleMatch = $candidates->firstWhere('pricing_type', PriceListType::Wholesale->value);

            $retailPriceRaw = $retailMatch !== null
                ? (float) $retailMatch->price
                : (float) ($variant->retail_price ?? 0);

            if ($wholesaleMatch !== null) {
                $wholesalePriceRaw = (float) $wholesaleMatch->price;
            } elseif ($retailMatch !== null && $variant->wholesale_price === null) {
                $wholesalePriceRaw = (float) $retailMatch->price;
            } elseif ($variant->wholesale_price !== null) {
                $wholesalePriceRaw = (float) $variant->wholesale_price;
            } else {
                $wholesalePriceRaw = $retailPriceRaw;
            }

            $productName = $variant->product->name_ar ?? $variant->product->name_en ?? '';
            $variantName = $variant->color_name_ar ?? $variant->color_name_en ?? $variant->size ?? $variant->capacity;
            $composedName = implode(' - ', array_filter([$productName, $variantName], fn ($v) => $v !== null && trim((string) $v) !== ''));

            $media = $variant->getMedia('images')->first() ?? $variant->product->getMedia('images')->first();
            $imageUrl = $media !== null
                ? ($media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl())
                : null;

            $data[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'name' => $composedName !== '' ? $composedName : (string) $variant->sku,
                'product_name' => $productName,
                'variant_name' => $variantName,
                'category_id' => $variant->product->category_id !== null ? (string) $variant->product->category_id : null,
                'unit_price' => round($retailPriceRaw, 2),
                'wholesale_price' => round($wholesalePriceRaw, 2),
                'available' => floor($available) === $available ? (int) $available : $available,
                'on_hand' => floor($onHand) === $onHand ? (int) $onHand : $onHand,
                'reorder_point' => floor($reorderPoint) === $reorderPoint ? (int) $reorderPoint : $reorderPoint,
                'image_url' => $imageUrl,
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'catalog_version' => $catalogVersion,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    /** @return Builder<StockLevel> */
    private function catalogStockLevels(int $branchId): Builder
    {
        return StockLevel::query()
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)));
    }

    /** @return Builder<PriceListItem> */
    private function catalogPriceItems(int $branchId): Builder
    {
        $now = now();

        return PriceListItem::query()
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->where('price_lists.is_active', true)
            ->whereIn('price_lists.type', [PriceListType::Retail->value, PriceListType::Wholesale->value])
            ->where('price_list_items.min_qty', '<=', 1)
            ->where(fn ($q) => $q->whereNull('price_lists.starts_at')->orWhere('price_lists.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.ends_at')->orWhere('price_lists.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.starts_at')->orWhere('price_list_items.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.ends_at')->orWhere('price_list_items.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.branch_id')->orWhere('price_lists.branch_id', $branchId));
    }

    /**
     * Quotes a POS cart without DB locks or persistence, returning line pricing, availability, and totals.
     * Never throws when stock is insufficient; populates line `error` with an Arabic message instead.
     *
     * @param  int  $branchId  Target branch ID
     * @param  array<array{product_variant_id: int, qty: int|float, unit_price?: float|null, discount_amount?: float|null}>  $items  Raw cart items
     * @param  Customer|null  $c  Optional customer for targeted pricing
     * @param  bool  $override  Whether manual price override is enabled
     * @return array{
     *     lines: array<int, array{
     *         product_variant_id: int,
     *         qty: int|float,
     *         unit_price: string,
     *         discount_amount: string,
     *         line_total: string,
     *         available: int|float,
     *         error: string|null
     *     }>,
     *     subtotal: string,
     *     discount_total: string,
     *     grand_total: string
     * }
     */
    public function quote(int $branchId, array $items, ?Customer $c = null, bool $override = false, ?User $actor = null): array
    {
        if ($c !== null && ($c->trashed() || ! $c->canShop())) {
            throw new BusinessException('العميل المحدد غير صالح أو موقوف.');
        }

        $resolved = $this->resolveItems($branchId, $items, $c, $override, false, $actor);
        $totals = $this->computeCartTotals($resolved);

        $lines = $resolved->map(fn (array $item): array => [
            'product_variant_id' => $item['product_variant_id'],
            'qty' => is_float($item['qty']) && floor($item['qty']) === $item['qty'] ? (int) $item['qty'] : $item['qty'],
            'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
            'discount_amount' => number_format((float) $item['discount_amount'], 2, '.', ''),
            'line_total' => number_format((float) $item['line_total'], 2, '.', ''),
            'available' => is_float($item['available']) && floor($item['available']) === $item['available']
                ? (int) $item['available']
                : $item['available'],
            'error' => $item['error'],
        ])->values()->all();

        return [
            'lines' => $lines,
            'subtotal' => number_format($totals['subtotal'], 2, '.', ''),
            'discount_total' => number_format($totals['discount_total'], 2, '.', ''),
            'grand_total' => number_format($totals['grand_total'], 2, '.', ''),
        ];
    }

    /**
     * Computes subtotal, discount_total, and grand_total from resolved items in minor units.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{subtotal: float, discount_total: float, grand_total: float}
     */
    private function computeCartTotals(Collection $items): array
    {
        $discountCents = $items->sum(fn ($item) => $this->minor(round((float) $item['discount_amount'], 2)));
        $netCents = $items->sum(fn ($item) => $this->minor(round((float) $item['line_total'], 2)));

        return [
            // Reconstruct the gross subtotal from the same rounded amounts shown on the invoice.
            'subtotal' => ($netCents + $discountCents) / 100,
            'discount_total' => $discountCents / 100,
            'grand_total' => $netCents / 100,
        ];
    }

    /**
     * Prepares sale item line totals and resolves pricing tiers.
     *
     * @param  int  $branchId  Target branch ID
     * @param  array<array{product_variant_id: int, qty: int|float, unit_price?: float|null, discount_amount?: float|null}>  $rawItems  Raw cart items
     * @param  Customer|null  $customer  Target customer
     * @param  bool  $overridePrice  Whether manual price override is enabled
     * @param  bool  $enforceStock  When true, throws BusinessException if available < qty; when false, sets line `error`
     * @return Collection<int, array<string, mixed>> Resolved item rows
     */
    private function resolveItems(
        int $branchId,
        array $rawItems,
        ?Customer $customer,
        bool $overridePrice,
        bool $enforceStock = true,
        ?User $actor = null
    ): Collection {
        $ids = array_values(array_unique(array_map(
            fn (array $item): int => (int) $item['product_variant_id'],
            $rawItems,
        )));
        if (count($ids) !== count($rawItems)) {
            throw new BusinessException('لا يمكن تكرار نفس الصنف في الفاتورة؛ عدّل الكمية على السطر الموجود.');
        }

        // Batch-load variants, their parent product, and branch stock so the cart
        // cost stays O(1) queries regardless of how many distinct lines it holds.
        $variants = ProductVariant::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with('product')
            ->get()
            ->keyBy('id');

        $stockByVariant = StockLevel::query()
            ->whereIn('product_variant_id', $ids)
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
            ->get()
            ->groupBy('product_variant_id');

        $maxDiscount = (float) ($this->settings->get('pos.max_discount_percent')
            ?? $this->settings->get('pos_max_discount_percent')
            ?? config('inventory.pos_max_discount_percent', 20));

        $pricingContext = $this->pricing->contextFor($customer, $ids, $branchId);

        return collect($rawItems)->map(function (array $item) use ($branchId, $customer, $overridePrice, $enforceStock, $variants, $stockByVariant, $maxDiscount, $actor, $pricingContext): array {
            $qty = (float) $item['qty'];
            DiscreteQuantity::assertWhole($qty, false, 'كمية البيع يجب أن تكون عددًا صحيحًا موجبًا.');

            /** @var ProductVariant|null $variant */
            $variant = $variants->get((int) $item['product_variant_id']);
            if ($variant === null) {
                throw (new ModelNotFoundException)->setModel(ProductVariant::class, [(int) $item['product_variant_id']]);
            }
            if ($variant->product === null || ! $variant->product->is_active) {
                throw new BusinessException("المنتج الخاص بالصنف [{$variant->sku}] غير متاح للبيع.");
            }

            $stock = $stockByVariant->get($variant->id, collect());
            $available = (float) $stock->sum('available');
            $availableFormatted = floor($available) === $available ? (int) $available : $available;

            $error = null;
            if ($available < $qty) {
                if ($enforceStock) {
                    throw new BusinessException(
                        "الكمية المتاحة للصنف [{$variant->sku}] في الفرع هي {$available} فقط.",
                        status: 422,
                    );
                }
                $error = "الكمية المطلوبة غير متوفرة في المخزون (المتاح: {$availableFormatted})";
            }

            $catalogPrice = (float) $this->pricing->resolve($variant, $customer, $qty, $branchId, $pricingContext)['unit_price'];
            if ($overridePrice && isset($item['unit_price'])) {
                $unitPrice = (float) $item['unit_price'];
                if ($enforceStock && trim((string) ($item['override_reason'] ?? '')) === '') {
                    throw new BusinessException("سبب تجاوز سعر الصنف [{$variant->sku}] مطلوب.");
                }
                if ($unitPrice < (float) $variant->cost_price
                    && ! ($actor?->can('pos.override_price_below_cost') ?? false)) {
                    throw new BusinessException("سعر بيع الصنف [{$variant->sku}] أقل من التكلفة؛ يلزم اعتماد مدير.");
                }
            } else {
                $unitPrice = $catalogPrice;
            }

            if (! is_finite($unitPrice) || $unitPrice < 0) {
                throw new BusinessException("سعر الصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }

            $discount = (float) ($item['discount_amount'] ?? 0);
            $priceReduction = max(0, $this->minor($catalogPrice) - $this->minor($unitPrice)) * (int) $qty;
            $discountLimit = (int) round($this->minor($catalogPrice) * $qty * $maxDiscount / 100);
            if ($discount > 0 && $priceReduction + $this->minor($discount) > $discountLimit) {
                throw new BusinessException('الخصم يتجاوز النسبة القصوى المسموحة.');
            }
            if (! is_finite($discount) || $discount < 0) {
                throw new BusinessException("خصم الصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }
            $lineTotal = round($unitPrice * $qty - $discount, 2);

            if ($lineTotal < 0) {
                throw new BusinessException("إجمالي السطر للصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }

            return [
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $variant->product->name_ar ?? $variant->product->name_en ?? 'POS Item',
                'sku_snapshot' => $variant->sku,
                'qty' => (int) $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'line_total' => $lineTotal,
                'unit_cost' => $variant->cost_price,
                'available' => $availableFormatted,
                'error' => $error,
            ];
        });
    }

    /**
     * Records a petty cash expense paid directly from the active shift cash drawer.
     *
     * @param  PosShift  $shift  Active shift
     * @param  array<string, mixed>  $data  Expense attributes
     * @param  int  $userId  Cashier/User recording the expense
     * @return Expense Created expense record
     *
     * @throws BusinessException If shift is not open
     */
    public function recordExpense(PosShift $shift, array $data, int $userId): Expense
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن تسجيل مصاريف.');
        }

        return DB::transaction(function () use ($shift, $data, $userId): Expense {
            $shift = $this->lockOpenShift($shift);
            $actor = User::findOrFail($userId);
            app(BranchAccess::class)->assertBranch($actor->id, (int) $shift->branch_id);
            if ((int) $actor->id !== (int) $shift->user_id && ! $actor->can('pos_shifts.approve')) {
                throw new AuthorizationException('لا تملك صلاحية تسجيل مصروف على هذه الوردية.');
            }
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);

            $category = trim((string) ($data['category'] ?? ''));
            if ($category === '') {
                throw new BusinessException('فئة المصروف مطلوبة.');
            }
            if (mb_strlen($category) > 100) {
                throw new BusinessException('فئة المصروف يجب ألا تتجاوز 100 حرف.');
            }
            if (! array_key_exists('amount', $data) || $data['amount'] === null || trim((string) $data['amount']) === '') {
                throw new BusinessException('مبلغ المصروف مطلوب.');
            }
            try {
                $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            } catch (\Throwable) {
                throw new BusinessException('المبلغ المالي غير صالح؛ يُسمح بخانتين عشريتين فقط.');
            }
            if (! $amount->isPositive()) {
                throw new BusinessException('مبلغ المصروف يجب أن يكون أكبر من صفر.');
            }

            $attachmentPath = null;
            if (isset($data['attachment_path']) && $data['attachment_path'] !== null && trim((string) $data['attachment_path']) !== '') {
                $attachmentPath = ExpenseService::validateAttachmentPath($data['attachment_path']);
            }

            $notes = null;
            if (isset($data['notes']) && $data['notes'] !== null && trim((string) $data['notes']) !== '') {
                $notes = trim((string) $data['notes']);
                if (mb_strlen($notes) > 500) {
                    throw new BusinessException('ملاحظات المصروف يجب ألا تتجاوز 500 حرف.');
                }
            }

            $expenseDate = today(config('app.business_timezone', 'UTC'));

            $expenseNumber = $this->settings->generateNextNumber('expense');

            $expense = Expense::create([
                'expense_number' => $expenseNumber,
                'branch_id' => $shift->branch_id,
                'treasury_id' => $shift->treasury_id,
                'shift_id' => $shift->id,
                'category' => $category,
                'amount' => (string) $amount,
                'expense_date' => $expenseDate,
                'attachment_path' => $attachmentPath,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $this->treasury->record([
                'treasury_id' => $shift->treasury_id,
                'shift_id' => $shift->id,
                'type' => TreasuryTransactionType::Expense,
                'amount' => (string) $amount->negated(),
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'created_by' => $userId,
                'notes' => "مصروف: {$category} #{$expenseNumber}",
            ]);

            return $expense;
        });
    }

    private function lockOpenShift(PosShift $shift): PosShift
    {
        // M5: re-check the open status under the row lock inside the sale
        // transaction — a supervisor close racing the sale must abort it
        // instead of posting to a closed drawer.
        app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
        $locked = PosShift::query()->whereKey($shift->id)->where('status', PosShiftStatus::Open)->lockForUpdate()->first();
        if ($locked === null) {
            throw new BusinessException('الوردية غير مفتوحة.');
        }

        return $locked;
    }

    /**
     * Convert decimal amount to integer cents, forbidding unrepresented fractional cents.
     */
    private function minor(mixed $value): int
    {
        try {
            // RoundingMode::Unnecessary rejects sub-penny fractions to enforce strict 2-decimal financial bounds.
            return BigDecimal::of((string) $value)->toScale(2, RoundingMode::Unnecessary)->getUnscaledValue()->toInt();
        } catch (\Throwable) {
            throw new BusinessException('المبلغ المالي غير صالح؛ يُسمح بخانتين عشريتين فقط.');
        }
    }
}
