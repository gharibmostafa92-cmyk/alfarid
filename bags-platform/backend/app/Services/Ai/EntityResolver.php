<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Expense;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use App\Services\StaffAdministration;
use App\Support\ArabicDigits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Resolves and scopes domain entity lookups, search term normalization, and masked references for the AI agent.
 */
final class EntityResolver
{
    public function __construct(private BranchAccess $branches) {}

    /**
     * Normalize Eastern Arabic and Persian numerals to Western digits.
     */
    public static function normalizeDigits(string $value): string
    {
        return ArabicDigits::normalize($value);
    }

    /**
     * Expand search query with Arabic spelling normalizations and synonym replacements.
     *
     * @return list<string>
     */
    public static function expandCatalogSearchTerms(string $query): array
    {
        $clean = trim(self::normalizeDigits($query));
        if ($clean === '') {
            return [];
        }
        $terms = [$clean];
        $norm = self::normalizeForSearch($clean);
        if ($norm !== '' && $norm !== $clean) {
            $terms[] = $norm;
        }
        $synonyms = (array) config('ai_catalog_synonyms', []);
        foreach ($synonyms as $phrase => $expansions) {
            $normPhrase = self::normalizeForSearch((string) $phrase);
            if ($normPhrase !== '' && (str_contains($norm, $normPhrase) || str_contains(mb_strtolower($clean), mb_strtolower((string) $phrase)))) {
                foreach ((array) $expansions as $exp) {
                    $terms[] = (string) $exp;
                }
            }
        }

        return array_values(array_unique(array_filter($terms, fn ($t) => trim($t) !== '')));
    }

    public static function normalize(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $v);
        $v = str_replace(['ة'], 'ه', $v);
        $v = str_replace(['ى'], 'ي', $v);
        $v = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $v) ?? $v;

        return preg_replace('/\s+/u', ' ', $v) ?? $v;
    }

    private function restricted(?User $actor): ?int
    {
        if (! $actor) {
            return null;
        }
        try {
            return $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * Find branches accessible to the actor matching name, code, or city filters.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function branches(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }

        // Filter in SQL with limit to avoid full-table scans and bound memory usage.
        return Branch::query()->withCount('users')
            ->when($restricted !== null, fn ($x) => $x->whereKey($restricted))
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when(isset($filters['type']), fn ($x) => $x->where('type', $filters['type']))
            ->when(isset($filters['city']), fn ($x) => $x->where('city', $filters['city']))
            ->when($query !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('code', 'ilike', SearchTerm::pattern($query))
                ->orWhere('city', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'label' => $b->name.' ('.$b->code.')', 'meta' => trim(($b->city ?? '').' · '.($b->is_active ? 'نشط' : 'متوقف').' · موظفين: '.$b->users_count)])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function users(User $actor, string $query = ''): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }

        return User::query()->where('is_system', false)
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
            ->when($query !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('email', 'ilike', SearchTerm::pattern($query))
                ->orWhere('phone', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name, 'meta' => $this->maskEmail($u->email).' · '.($u->is_active ? 'نشط' : 'معطّل')])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function roles(string $query = ''): array
    {
        return Role::query()->withCount(['users', 'permissions'])
            ->where('guard_name', 'staff')
            ->when($query !== '', fn ($x) => $x->where('name', 'ilike', SearchTerm::pattern($query)))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Role $r) => ['id' => $r->id, 'label' => $r->name, 'meta' => 'موظفين: '.$r->users_count.' · صلاحيات: '.$r->permissions_count])
            ->values()
            ->all();
    }

    /**
     * Resolve inventory storage locations within actor branch scope.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function locations(User $actor, string $query = '', ?int $branchId = null): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $cleanCode = trim(self::normalizeDigits($query));
        // Fast path: exact location code lookup without whitespace.
        if ($cleanCode !== '' && ! preg_match('/\s/u', $cleanCode)) {
            $exact = Location::query()
                ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted), fn ($q) => $q->when($branchId !== null, fn ($w) => $w->where('branch_id', $branchId)))
                ->whereRaw('LOWER(code) = LOWER(?)', [$cleanCode])->limit(2)->get();
            if ($exact->count() === 1) {
                $l = $exact->first();

                return [[
                    'id' => $l->id,
                    'label' => $l->name.' ('.$l->code.')',
                    'meta' => trim(($l->type?->value ?? '').' · '.($l->is_active ? 'نشط' : 'معطّل').' · فرع: '.$l->branch_id),
                ]];
            }
        }

        // Enforce actor restricted branch first to prevent unauthorized cross-branch lookups.
        return Location::query()
            ->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted), fn ($q) => $q->when($branchId !== null, fn ($w) => $w->where('branch_id', $branchId)))
            ->when($query !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('code', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Location $l) => [
                'id' => $l->id,
                'label' => $l->name.' ('.$l->code.')',
                'meta' => trim(($l->type?->value ?? '').' · '.($l->is_active ? 'نشط' : 'معطّل').' · فرع: '.$l->branch_id),
            ])
            ->values()
            ->all();
    }

    /**
     * Search stock transfers originating from or destined to actor branches.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function transfers(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $scope = fn ($x) => $restricted !== null
            ? $x->where(fn ($w) => $w->where('from_branch_id', $restricted)->orWhere('to_branch_id', $restricted))
            : $x;
        $clean = trim(self::normalizeDigits($query));

        // Fast path: exact document number format (e.g. TRF-00012).
        if ($clean !== '' && preg_match('/^[A-Za-z]+-\d+$/', $clean)) {
            $exact = StockTransfer::query()->with(['fromBranch:id,name', 'toBranch:id,name'])
                ->tap($scope)->whereRaw('LOWER(transfer_number) = LOWER(?)', [$clean])->limit(2)->get();
            if ($exact->count() === 1) {
                return $this->mapTransfers($exact)->all();
            }
        }

        $search = $this->inventoryDocumentSearch($clean);
        $route = null;
        if (preg_match('/(?:^|\s)من\s+(.+?)\s+(?:إلى|الى|لـ?|لفرع)\s*(.+)$/u', $search, $parts)) {
            $route = [$this->inventoryDocumentSearch($parts[1]), $this->inventoryDocumentSearch($parts[2])];
        }

        return $this->mapTransfers(StockTransfer::query()->with(['fromBranch:id,name', 'toBranch:id,name'])
            ->tap($scope)
            ->when(isset($filters['status']), fn ($x) => $x->where('status', $filters['status']))
            ->when(self::mentionsYesterday($clean), fn ($x) => $x->whereDate('created_at', today()->subDay()))
            ->when(self::mentionsToday($clean), fn ($x) => $x->whereDate('created_at', today()))
            ->when($route !== null, fn ($x) => $x
                ->whereHas('fromBranch', fn ($b) => $b->where('name', 'ilike', SearchTerm::pattern($route[0])))
                ->whereHas('toBranch', fn ($b) => $b->where('name', 'ilike', SearchTerm::pattern($route[1]))))
            ->when($route === null && $search !== '', function ($x) use ($search): void {
                $pattern = SearchTerm::pattern($search);
                $x->where(fn ($w) => $w
                    ->where('transfer_number', 'ilike', $pattern)
                    ->orWhere('notes', 'ilike', $pattern)
                    ->orWhereHas('fromBranch', fn ($b) => $b->where('name', 'ilike', $pattern))
                    ->orWhereHas('toBranch', fn ($b) => $b->where('name', 'ilike', $pattern)));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get())->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function stocktakes(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $clean = trim(self::normalizeDigits($query));

        if ($clean !== '' && preg_match('/^[A-Za-z]+-\d+$/', $clean)) {
            $exact = Stocktake::query()->with('branch:id,name')
                ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
                ->whereRaw('LOWER(stocktake_number) = LOWER(?)', [$clean])->limit(2)->get();
            if ($exact->count() === 1) {
                return $this->mapStocktakes($exact)->all();
            }
        }

        $search = $this->inventoryDocumentSearch($clean);

        return $this->mapStocktakes(Stocktake::query()->with('branch:id,name')
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
            ->when(isset($filters['status']), fn ($x) => $x->where('status', $filters['status']))
            ->when(self::mentionsYesterday($clean), fn ($x) => $x->whereDate('created_at', today()->subDay()))
            ->when(self::mentionsToday($clean), fn ($x) => $x->whereDate('created_at', today()))
            ->when($search !== '', function ($x) use ($search): void {
                $pattern = SearchTerm::pattern($search);
                $x->where(fn ($w) => $w
                    ->where('stocktake_number', 'ilike', $pattern)
                    ->orWhere('notes', 'ilike', $pattern)
                    ->orWhereHas('branch', fn ($b) => $b->where('name', 'ilike', $pattern)));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get())->all();
    }

    private function inventoryDocumentSearch(string $query): string
    {
        $query = preg_replace('/(?:^|\s)(?:التحويل|تحويل|اللي|جرد|الجرد|بتاع|فرع|امبارح|أمس|النهارده|اليوم)(?=\s|$)/u', ' ', $query) ?? $query;

        return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function productionReceipts(User $actor, string $query = '', ?int $branchId = null): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $clean = trim(self::normalizeDigits($query));

        return ProductionReceipt::query()->with('branch:id,name')
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted), fn ($x) => $x->when($branchId !== null, fn ($w) => $w->where('branch_id', $branchId)))
            ->when($clean !== '', function ($x) use ($clean): void {
                $pattern = SearchTerm::pattern($clean);
                $x->where(fn ($w) => $w
                    ->whereRaw('LOWER(batch_number) = LOWER(?)', [$clean])
                    ->orWhere('batch_number', 'ilike', $pattern)
                    ->orWhere('notes', 'ilike', $pattern));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ProductionReceipt $r) => [
                'id' => $r->id,
                'label' => $r->batch_number.' ('.($r->branch?->name ?? '—').')',
                'meta' => ($r->created_at?->format('Y-m-d') ?? '').' · '.mb_substr((string) $r->notes, 0, 60),
            ])
            ->values()
            ->all();
    }

    /**
     * Search treasuries filtered by branch restrictions and access rights.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function treasuries(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $clean = trim(self::normalizeDigits($query));

        // Fast path: exact treasury code format.
        if ($clean !== '' && preg_match('/^[A-Za-z]+-\d+$/', $clean)) {
            $exact = Treasury::query()->with('branch:id,name')
                ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
                ->whereRaw('LOWER(code) = LOWER(?)', [$clean])->limit(2)->get();
            if ($exact->count() === 1) {
                return $this->mapTreasuries($exact, $actor)->all();
            }
        }

        return $this->mapTreasuries(Treasury::query()->with('branch:id,name')
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
            ->when($restricted === null && isset($filters['branch_id']), fn ($x) => $x->where('branch_id', (int) $filters['branch_id']))
            ->when(isset($filters['type']), fn ($x) => $x->where('type', $filters['type']))
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', (bool) $filters['active']))
            ->when($clean !== '', function ($x) use ($clean): void {
                $pattern = SearchTerm::pattern($clean);
                $x->where(fn ($w) => $w
                    ->where('name', 'ilike', $pattern)
                    ->orWhere('code', 'ilike', $pattern));
            })
            ->orderBy('id')
            ->limit(20)
            ->get(), $actor)->all();
    }

    /**
     * Search internal treasury transfers touching actor branches.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function treasuryTransfers(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $clean = trim(self::normalizeDigits($query));

        // Fast path: exact treasury transfer number (e.g. TRF-00012).
        if ($clean !== '' && preg_match('/^[A-Za-z]+-\d+$/', $clean)) {
            $exact = TreasuryTransfer::query()->with(['fromTreasury.branch:id,name', 'toTreasury.branch:id,name'])
                ->when($restricted !== null, fn ($x) => $x->where(fn ($w) => $w
                    ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $restricted))
                    ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $restricted))))
                ->whereRaw('LOWER(transfer_number) = LOWER(?)', [$clean])->limit(2)->get();
            if ($exact->count() === 1) {
                return $this->mapTreasuryTransfers($exact)->all();
            }
        }

        return $this->mapTreasuryTransfers(TreasuryTransfer::query()->with(['fromTreasury.branch:id,name', 'toTreasury.branch:id,name'])
            ->when($restricted !== null, fn ($x) => $x->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $restricted))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $restricted))))
            ->when(isset($filters['status']), fn ($x) => $x->where('status', $filters['status']))
            ->when(self::mentionsYesterday($clean), fn ($x) => $x->whereDate('created_at', today()->subDay()))
            ->when(self::mentionsToday($clean), fn ($x) => $x->whereDate('created_at', today()))
            ->when($clean !== '', function ($x) use ($clean): void {
                $pattern = SearchTerm::pattern($clean);
                $x->where(fn ($w) => $w
                    ->where('transfer_number', 'ilike', $pattern)
                    ->orWhere('notes', 'ilike', $pattern));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get())->all();
    }

    /**
     * Search branch expense records matching actor permissions.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function expenses(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }
        $clean = trim(self::normalizeDigits($query));

        // Fast path: exact expense document number (e.g. EXP-00012).
        if ($clean !== '' && preg_match('/^[A-Za-z]+-\d+$/', $clean)) {
            $exact = Expense::query()->with(['branch:id,name', 'treasury:id,name'])
                ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
                ->whereRaw('LOWER(expense_number) = LOWER(?)', [$clean])->limit(2)->get();
            if ($exact->count() === 1) {
                return $this->mapExpenses($exact)->all();
            }
        }

        return $this->mapExpenses(Expense::query()->with(['branch:id,name', 'treasury:id,name'])
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
            ->when($restricted === null && isset($filters['branch_id']), fn ($x) => $x->where('branch_id', (int) $filters['branch_id']))
            ->when(isset($filters['category']), function ($x) use ($filters): void {
                $x->where('category', 'ilike', SearchTerm::pattern((string) $filters['category']));
            })
            ->when(self::mentionsYesterday($clean), fn ($x) => $x->whereDate('expense_date', today()->subDay()))
            ->when(self::mentionsToday($clean), fn ($x) => $x->whereDate('expense_date', today()))
            ->when($clean !== '', function ($x) use ($clean): void {
                $pattern = SearchTerm::pattern($clean);
                $x->where(fn ($w) => $w
                    ->where('expense_number', 'ilike', $pattern)
                    ->orWhere('category', 'ilike', $pattern)
                    ->orWhere('notes', 'ilike', $pattern));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get())->all();
    }

    /** @param Collection<int, Treasury> $items */
    private function mapTreasuries($items, User $actor): Collection
    {
        $canViewBalance = $actor->can('treasuries.view');

        return $items->map(fn (Treasury $t) => [
            'id' => $t->id,
            'label' => $t->name.' ('.$t->code.')',
            'meta' => trim(($t->branch?->name ?? 'مركزية').' · '.self::treasuryTypeLabel($t->type instanceof \BackedEnum ? $t->type->value : (string) $t->type).' · '.($t->is_active ? 'نشطة' : 'متوقفة').($canViewBalance ? ' · رصيد: '.number_format((float) $t->current_balance, 2) : '')),
        ])->values();
    }

    /** @param Collection<int, TreasuryTransfer> $items */
    private function mapTreasuryTransfers($items): Collection
    {
        return $items->map(fn (TreasuryTransfer $t) => [
            'id' => $t->id,
            'label' => $t->transfer_number.' · '.($t->fromTreasury?->name ?? '—').' ← '.($t->toTreasury?->name ?? '—'),
            'meta' => ($t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status).' · '.number_format((float) $t->amount, 2).' · '.($t->created_at?->format('Y-m-d') ?? ''),
        ])->values();
    }

    /** @param Collection<int, Expense> $items */
    private function mapExpenses($items): Collection
    {
        return $items->map(fn (Expense $e) => [
            'id' => $e->id,
            'label' => $e->expense_number.' · '.$e->category,
            'meta' => trim(($e->branch?->name ?? '—').' · '.($e->treasury?->name ?? '—').' · '.($e->voided_at !== null ? 'ملغي' : 'ساري')),
        ])->values();
    }

    public static function treasuryTypeLabel(string $type): string
    {
        return match ($type) {
            'main_safe' => 'خزينة رئيسية',
            'pos_drawer' => 'درج كاشير',
            'bank_account' => 'حساب بنك',
            default => $type,
        };
    }

    private static function mentionsYesterday(string $query): bool
    {
        $norm = self::normalizeForSearch($query);

        return str_contains($norm, 'مبارح') || preg_match('/(^|\s)امس($|\s)/u', $norm) === 1;
    }

    private static function mentionsToday(string $query): bool
    {
        $norm = self::normalizeForSearch($query);

        return str_contains($norm, 'نهارده') || preg_match('/(^|\s)يوم($|\s)/u', $norm) === 1;
    }

    /** @param Collection<int, StockTransfer> $items */
    private function mapTransfers($items): Collection
    {
        return $items->map(fn (StockTransfer $t) => [
            'id' => $t->id,
            'label' => $t->transfer_number.' · '.($t->fromBranch?->name ?? '—').' ← '.($t->toBranch?->name ?? '—'),
            'meta' => $t->status->value.' · '.($t->created_at?->format('Y-m-d') ?? ''),
        ])->values();
    }

    /** @param Collection<int, Stocktake> $items */
    private function mapStocktakes($items): Collection
    {
        return $items->map(fn (Stocktake $s) => [
            'id' => $s->id,
            'label' => $s->stocktake_number.' ('.($s->branch?->name ?? '—').')',
            'meta' => $s->status->value.' · '.($s->created_at?->format('Y-m-d') ?? ''),
        ])->values();
    }

    /**
     * Mask staff email address for privacy-preserving displays.
     */
    public function maskEmail(?string $email): string
    {
        // Centralize masking via StaffAdministration to maintain consistency between audit trails and entity views.
        return StaffAdministration::maskEmail($email);
    }

    /**
     * Mask phone number displaying only leading 3 and trailing 3 digits.
     */
    public static function maskPhone(?string $p): string
    {
        return $p && strlen($p) === 11 ? substr($p, 0, 3).'*****'.substr($p, -3) : '***';
    }

    /**
     * Mask tax and commercial registry numbers showing leading and trailing characters only.
     */
    public static function maskLegal(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = trim($v);

        return mb_strlen($v) <= 4 ? '***' : mb_substr($v, 0, 2).'***'.mb_substr($v, -2);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'قيد المراجعة',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
            'suspended' => 'موقوف',
            default => $status,
        };
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'retail' => 'قطاعي',
            'wholesale' => 'جملة',
            'distributor' => 'موزع',
            default => $type,
        };
    }

    /**
     * Build customer search query with common filters and phone / trigram text matching.
     *
     * @param  array<string,mixed>  $filters
     */
    public static function buildCustomerQuery(string $query = '', array $filters = []): Builder
    {
        $q = Customer::query()->with('customerGroup')
            ->when(($filters['business_only'] ?? false), fn ($x) => $x->whereIn('type', ['wholesale', 'distributor']))
            ->when(isset($filters['type']), fn ($x) => $x->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($x) => $x->where('status', $filters['status']))
            ->when(isset($filters['customer_group_id']), fn ($x) => $x->where('customer_group_id', $filters['customer_group_id']))
            ->when(isset($filters['pending_older_than_hours']), fn ($x) => $x->where('status', 'pending')->where('created_at', '<', now()->subHours((int) $filters['pending_older_than_hours'])));

        if (trim($query) !== '') {
            if (preg_match('/^[0-9٠-٩۰-۹\s().+-]+$/u', trim($query))) {
                // Use case-sensitive LIKE for phone prefixes to leverage the Postgres text_pattern_ops btree index.
                $q->whereLike('phone', SearchTerm::prefix(Customer::normalizePhone($query)), caseSensitive: true);
            } else {
                $q->whereLike('search_text', SearchTerm::pattern($query), caseSensitive: false);
            }
        }

        return $q;
    }

    /**
     * Search customer records without branch restriction (customers are global across all branches).
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function customers(User $actor, string $query = '', array $filters = []): array
    {
        if (trim($query) !== '' && mb_strlen(trim($query)) < 3) {
            return [];
        }

        return self::buildCustomerQuery($query, $filters)
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'label' => $c->company_name ? $c->name.' — '.$c->company_name : $c->name,
                'meta' => self::maskPhone($c->phone).' · '.self::statusLabel($c->status->value).' · '.self::typeLabel($c->type->value),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function customerGroups(string $query = '', array $filters = []): array
    {
        return CustomerGroup::query()->withCount('customers')
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when(trim($query) !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern($query))
                ->orWhere('code', 'ilike', SearchTerm::pattern($query))))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (CustomerGroup $g) => ['id' => $g->id, 'label' => $g->name.' ('.$g->code.')', 'meta' => 'خصم: '.$g->discount_percentage.'% · عملاء: '.$g->customers_count.($g->is_active ? '' : ' · متوقفة')])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function priceLists(User $actor, string $query = '', array $filters = []): array
    {
        $restricted = $this->restricted($actor);
        if ($restricted === -1) {
            return [];
        }

        return PriceList::query()
            ->when($restricted !== null, fn ($x) => $x->where('branch_id', $restricted))
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when(isset($filters['type']), fn ($x) => $x->where('type', $filters['type']))
            ->when(trim($query) !== '', fn ($x) => $x->where('name', 'ilike', SearchTerm::pattern($query)))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (PriceList $l) => ['id' => $l->id, 'label' => $l->name.' ('.$l->type->value.')', 'meta' => ($l->is_active ? 'نشطة' : 'متوقفة').' · أولوية: '.$l->priority.($l->branch_id ? ' · فرع: '.$l->branch_id : '')])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function brands(string $query = '', array $filters = []): array
    {
        $clean = trim(self::normalizeDigits($query));
        $terms = self::expandCatalogSearchTerms($clean);

        return Brand::query()
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when($terms !== [], function ($x) use ($terms): void {
                $x->where(function ($w) use ($terms): void {
                    foreach ($terms as $term) {
                        $pattern = SearchTerm::pattern($term);
                        $w->orWhereLike('name_ar', $pattern, caseSensitive: false)
                            ->orWhereLike('name_en', $pattern, caseSensitive: false)
                            ->orWhereLike('slug', $pattern, caseSensitive: false);
                    }
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Brand $b) => ['id' => $b->id, 'label' => $b->name_ar.' ('.$b->slug.')', 'meta' => $b->is_active ? 'نشطة' : 'معطّلة'])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function coupons(string $query = '', array $filters = []): array
    {
        $clean = trim(self::normalizeDigits($query));

        return Coupon::query()
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when($clean !== '', fn ($x) => $x->whereRaw('UPPER(code) LIKE ?', [SearchTerm::pattern(mb_strtoupper($clean))]))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Coupon $c) => ['id' => $c->id, 'label' => $c->code.' ('.$c->type.')', 'meta' => 'قيمة: '.$c->value.($c->usage_limit !== null ? ' · مستخدم: '.$c->used_count.'/'.$c->usage_limit : ' · مستخدم: '.$c->used_count).($c->is_active ? '' : ' · متوقف')])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function promotions(string $query = '', array $filters = []): array
    {
        $clean = trim(self::normalizeDigits($query));

        return Promotion::query()
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', $filters['active']))
            ->when($clean !== '', fn ($x) => $x->where('name', 'ilike', SearchTerm::pattern($clean)))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (Promotion $p) => ['id' => $p->id, 'label' => $p->name.' ('.$p->type.')', 'meta' => ($p->is_active ? 'نشط' : 'متوقف').' · غير مُطبَّق تلقائيًا'])
            ->values()
            ->all();
    }

    /** @param list<string> $fields */
    public function matches(string $query, array $fields): bool
    {
        $nq = self::normalizeForSearch($query);
        if ($nq === '') {
            return true;
        }
        foreach ($fields as $f) {
            if (str_contains(self::normalizeForSearch($f), $nq)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeForSearch(string $value): string
    {
        return preg_replace('/(^|\s)ال/u', '$1', self::normalize($value)) ?? self::normalize($value);
    }

    public function exactBranch(User $actor, string $query): ?Branch
    {
        $opts = $this->branches($actor, $query);
        if (count($opts) !== 1) {
            return null;
        }

        return Branch::find($opts[0]['id']);
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function categories(User $actor, string $q = '', array $filters = []): array
    {
        $clean = trim(self::normalizeDigits($q));
        $terms = self::expandCatalogSearchTerms($clean);

        return Category::query()
            ->with('parent:id,name_ar,name_en')
            ->withCount('products')
            ->when(array_key_exists('active', $filters), fn ($x) => $x->where('is_active', (bool) $filters['active']))
            ->when(array_key_exists('parent_id', $filters), fn ($x) => $filters['parent_id'] === null ? $x->whereNull('parent_id') : $x->where('parent_id', (int) $filters['parent_id']))
            ->when(! empty($filters['root_only']), fn ($x) => $x->whereNull('parent_id'))
            ->when($terms !== [], function ($x) use ($terms): void {
                $x->where(function ($w) use ($terms): void {
                    foreach ($terms as $term) {
                        $pattern = SearchTerm::pattern($term);
                        $w->orWhereLike('name_ar', $pattern, caseSensitive: false)
                            ->orWhereLike('name_en', $pattern, caseSensitive: false)
                            ->orWhereLike('slug', $pattern, caseSensitive: false);
                    }
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'label' => $c->name_ar.' ('.$c->slug.')',
                'meta' => ($c->is_active ? 'نشط' : 'معطّل').' · '.$c->products_count.' منتج'.($c->parent ? ' · أب: '.$c->parent->name_ar : ''),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id:int,label:string,meta:string}> */
    public function products(User $actor, string $q = '', array $filters = []): array
    {
        $clean = trim(self::normalizeDigits($q));

        $terms = self::expandCatalogSearchTerms($clean);
        $query = Product::query()
            ->with('category:id,name_ar,name_en')
            ->withCount('variants')
            ->when(isset($filters['status']), fn ($x) => $x->where('status', $filters['status']))
            ->when(isset($filters['category_id']), function ($x) use ($filters): void {
                $cat = Category::find((int) $filters['category_id']);
                if ($cat) {
                    $ids = ! empty($filters['include_children']) ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
                    $x->whereIn('category_id', $ids);
                } else {
                    $x->where('category_id', (int) $filters['category_id']);
                }
            })
            ->when(isset($filters['sales_channel']), fn ($x) => $x->where('sales_channel', $filters['sales_channel']))
            ->when(($filters['missing'] ?? null) === 'images', function ($x): void {
                $x->whereDoesntHave('media', fn ($m) => $m->where('collection_name', 'images'))
                    ->whereDoesntHave('variants', fn ($v) => $v->where('is_active', true)->whereHas('media', fn ($m) => $m->where('collection_name', 'images')));
            })
            ->when(($filters['missing'] ?? null) === 'prices', function ($x): void {
                $x->whereHas('variants', function ($v): void {
                    $v->where('is_active', true)->where(function ($p): void {
                        $p->where(fn ($r) => $r->whereIn('products.sales_channel', ['retail', 'both'])->where(fn ($w) => $w->whereNull('retail_price')->orWhere('retail_price', '<=', 0)))
                            ->orWhere(fn ($r) => $r->whereIn('products.sales_channel', ['wholesale', 'both'])->where(fn ($w) => $w->whereNull('wholesale_price')->orWhere('wholesale_price', '<=', 0)));
                    });
                });
            });

        // Exact identifiers have priority within the same filtered scope.
        if ($clean !== '' && ! preg_match('/\s/u', $clean) && mb_strlen($clean) >= 4) {
            $exact = (clone $query)
                ->where(function ($w) use ($clean): void {
                    $w->whereRaw('LOWER(code) = LOWER(?)', [$clean])
                        ->orWhereIn('products.id', ProductVariant::query()->select('product_id')
                            ->where(fn ($v) => $v->whereRaw('LOWER(sku) = LOWER(?)', [$clean])->orWhereRaw('LOWER(barcode) = LOWER(?)', [$clean])));
                })->limit(2)->get();
            if ($exact->count() === 1) {
                $p = $exact->first();

                return [[
                    'id' => $p->id,
                    'label' => $p->name_ar.' — '.$p->code,
                    'meta' => $p->status->value.' · '.$p->variants_count.' متغير · '.($p->category?->name_ar ?? '—'),
                ]];
            }
        }

        if ($terms !== []) {
            $query->where(function ($w) use ($terms, $clean): void {
                $w->whereRaw('LOWER(code) = LOWER(?)', [$clean])
                    ->orWhereIn('products.id', ProductVariant::query()->select('product_id')
                        ->where(fn ($v) => $v->whereRaw('LOWER(sku) = LOWER(?)', [$clean])->orWhereRaw('LOWER(barcode) = LOWER(?)', [$clean])));
                foreach ($terms as $term) {
                    $pattern = SearchTerm::pattern($term);
                    $w->orWhereLike('name_ar', $pattern, caseSensitive: false)
                        ->orWhereLike('name_en', $pattern, caseSensitive: false);
                }
            });
        }

        return $query->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'label' => $p->name_ar.' — '.$p->code,
                'meta' => $p->status->value.' · '.$p->variants_count.' متغير · '.($p->category?->name_ar ?? '—'),
            ])
            ->values()
            ->all();
    }

    /**
     * Search product variants by SKU, barcode, color, size, capacity, or parent product attributes.
     *
     * @return list<array{id:int,label:string,meta:string}>
     */
    public function variants(User $actor, string $q = '', ?int $productId = null): array
    {
        $clean = trim(self::normalizeDigits($q));
        if ($clean !== '' && ! preg_match('/\s/u', $clean) && mb_strlen($clean) >= 4 && $productId === null) {
            $exact = $this->exactVariantByCode($clean);
            if ($exact) {
                $exact->loadMissing('product:id,name_ar,code');

                return [[
                    'id' => $exact->id,
                    'label' => ($exact->product?->name_ar ?? '').' — '.$exact->sku,
                    'meta' => trim(implode(' · ', array_filter([$exact->color_name_ar ?: $exact->color_name_en, $exact->size, $exact->capacity, $exact->is_active ? 'نشط' : 'معطّل']))),
                ]];
            }
        }

        $terms = self::expandCatalogSearchTerms($clean);

        return ProductVariant::query()
            ->with('product:id,name_ar,name_en,code')
            ->when($productId !== null, fn ($x) => $x->where('product_id', $productId))
            ->when($terms !== [], function ($x) use ($terms, $clean): void {
                $x->where(function ($w) use ($terms, $clean): void {
                    $w->whereRaw('LOWER(sku) = LOWER(?)', [$clean])
                        ->orWhereRaw('LOWER(barcode) = LOWER(?)', [$clean])
                        ->orWhereLike('barcode', SearchTerm::pattern($clean), caseSensitive: false);
                    foreach ($terms as $term) {
                        $pattern = SearchTerm::pattern($term);
                        $w->orWhereLike('sku', $pattern, caseSensitive: false)
                            ->orWhereLike('color_name_ar', $pattern, caseSensitive: false)
                            ->orWhereLike('color_name_en', $pattern, caseSensitive: false)
                            ->orWhereLike('size', $pattern, caseSensitive: false)
                            ->orWhereHas('product', fn ($p) => $p->whereLike('name_ar', $pattern, caseSensitive: false)->orWhereLike('name_en', $pattern, caseSensitive: false)->orWhereLike('code', $pattern, caseSensitive: false));
                    }
                    // Multi-token fallback: matches compound descriptive terms across multiple columns rather than requiring a single full phrase match.
                    $tokens = array_values(array_filter(preg_split('/\s+/u', self::normalizeForSearch($clean)) ?: [], fn ($t) => mb_strlen($t) >= 2));
                    if (count($tokens) > 1) {
                        $w->orWhere(function ($and) use ($tokens): void {
                            foreach ($tokens as $token) {
                                $pattern = SearchTerm::pattern($token);
                                $and->where(fn ($ww) => $ww
                                    ->whereLike('sku', $pattern, caseSensitive: false)
                                    ->orWhereLike('color_name_ar', $pattern, caseSensitive: false)
                                    ->orWhereLike('color_name_en', $pattern, caseSensitive: false)
                                    ->orWhereLike('size', $pattern, caseSensitive: false)
                                    ->orWhereLike('capacity', $pattern, caseSensitive: false)
                                    ->orWhereHas('product', fn ($p) => $p->whereLike('name_ar', $pattern, caseSensitive: false)->orWhereLike('name_en', $pattern, caseSensitive: false)->orWhereLike('code', $pattern, caseSensitive: false)));
                            }
                        });
                    }
                });
            })
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'label' => ($v->product?->name_ar ?? '').' — '.$v->sku,
                'meta' => trim(implode(' · ', array_filter([$v->color_name_ar ?: $v->color_name_en, $v->size, $v->capacity, $v->is_active ? 'نشط' : 'معطّل']))),
            ])
            ->values()
            ->all();
    }

    /**
     * Find variant by exact SKU or barcode match.
     */
    public function exactVariantByCode(string $code): ?ProductVariant
    {
        $clean = trim(self::normalizeDigits($code));
        if ($clean === '') {
            return null;
        }
        $matches = ProductVariant::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(sku) = LOWER(?)', [$clean])->orWhereRaw('LOWER(barcode) = LOWER(?)', [$clean]))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Resolve and sanitize human-readable entity references within branch authorization boundaries.
     *
     * @param  array{entity?:string,id?:mixed}  $reference
     * @return array{entity:string,id:mixed,label:string}
     */
    public function reference(User $actor, array $reference): array
    {
        $type = $reference['entity'] ?? '';
        abort_unless(app(ToolRegistry::class)->canReadEntity($actor, $type), 403, 'المرجع خارج صلاحيات العرض.');
        $id = $reference['id'] ?? null;
        $branch = $this->restricted($actor);
        $model = match ($type) {
            'branch' => Branch::query()->when($branch !== null, fn ($q) => $q->whereKey($branch))->find($id),
            'user' => User::query()->where('is_system', false)->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            'role' => Role::query()->where('guard_name', 'staff')->find($id),
            'permission' => Permission::query()->where('guard_name', 'staff')->where('name', $id)->first(),
            'location' => Location::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            // Customers have global scope matching standard REST authorization.
            'customer' => Customer::query()->find($id),
            'customer_group' => CustomerGroup::query()->find($id),
            'category' => Category::query()->find($id),
            'product' => Product::query()->find($id),
            'variant' => ProductVariant::query()->with('product:id,name_ar,code')->find($id),
            'transfer' => StockTransfer::query()->when($branch !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch)))->find($id),
            'stocktake' => Stocktake::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            'production_receipt' => ProductionReceipt::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            'treasury' => Treasury::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            'treasury_transfer' => TreasuryTransfer::query()->when($branch !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fromTreasury', fn ($t) => $t->where('branch_id', $branch))
                ->orWhereHas('toTreasury', fn ($t) => $t->where('branch_id', $branch))))->find($id),
            'expense' => Expense::query()->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->find($id),
            default => null,
        };
        abort_unless($model, 422, 'الاختيار مش موجود أو خارج نطاقك.');

        $rawLabel = match ($type) {
            'category', 'product' => (string) $model->name_ar,
            'variant' => (($model->product?->name_ar ?? '').' — '.$model->sku),
            'transfer' => (string) $model->transfer_number,
            'stocktake' => (string) $model->stocktake_number,
            'production_receipt' => (string) $model->batch_number,
            'treasury_transfer' => (string) $model->transfer_number,
            'expense' => (string) $model->expense_number,
            default => (string) $model->name,
        };
        // Strip control characters and clamp length to sanitize user-provided text before embedding into model prompts.
        $label = in_array($type, ['customer', 'customer_group', 'category', 'product', 'variant', 'treasury'], true)
            ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $rawLabel), 0, 120)
            : $rawLabel;

        return ['entity' => $type, 'id' => $type === 'permission' ? $model->name : $model->id, 'label' => $label];
    }
}
