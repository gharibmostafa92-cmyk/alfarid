<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\Occasion;
use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only store catalog service: queries, facets, filtering, and real-time online stock availability.
 * Online availability = aggregated SQL sum of sellable balances across active branches without loading in-memory rows.
 * Only published products are visible on the storefront.
 */
class StoreCatalogService
{
    public const HOME_CACHE_KEY = 'store_home_data:v2';

    /** L4: per-request memo so facets/index resolve the category only once. */
    private array $categoryMemo = [];

    private array $descendantMemo = [];

    public function __construct(private readonly PricingService $pricing) {}

    // ------------------------------------------------------------------ Availability

    /**
     * Apply sellable location constraint: branch must be active, and bin location must be unassigned
     * or active and sellable (excluding receiving and damaged bins).
     */
    private function sellableLocationRule(object $query): void
    {
        $query->where('branches.is_active', true)
            ->where(fn ($q) => $q->whereNull('stock_levels.location_id')
                ->orWhere(fn ($l) => $l->where('locations.is_active', true)->whereNotIn('locations.type', ['receiving', 'damaged'])));
    }

    /**
     * Correlated EXISTS subquery checking for available sellable stock for a product.
     */
    private function sellableExists(object $sub): void
    {
        $sub->select(DB::raw(1))
            ->from('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_levels.location_id');
        $this->sellableLocationRule($sub);
        $sub->where('product_variants.is_active', true)
            ->whereNull('product_variants.deleted_at')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('stock_levels.available', '>', 0);
    }

    /**
     * Attach aggregated store_available subquery to product queries.
     */
    public function withStoreAvailability(Builder $query): Builder
    {
        // L9: sellable stock only — active branches, active sellable locations
        // (receiving bins are not on the shelf yet, damaged bins are not shippable).
        $sub = DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_levels.location_id');
        $this->sellableLocationRule($sub);
        $sub->where('product_variants.is_active', true)
            ->whereNull('product_variants.deleted_at')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->selectRaw('COALESCE(SUM(stock_levels.available), 0)');

        return $query->select('products.*')->selectSub($sub, 'store_available');
    }

    /**
     * Batch-query sellable stock availability map for a list of variant IDs.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float> Map of variant ID to available stock quantity
     */
    public function availabilityMap(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        // L9: same sellable-stock rule as withStoreAvailability.
        $query = StockLevel::query()
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_levels.location_id');
        $this->sellableLocationRule($query);

        return $query
            ->whereIn('stock_levels.product_variant_id', $variantIds)
            ->groupBy('stock_levels.product_variant_id')
            ->selectRaw('stock_levels.product_variant_id, COALESCE(SUM(stock_levels.available), 0) as total')
            ->pluck('total', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    // ------------------------------------------------------------------ Catalog Queries

    /**
     * Base query for published retail products with variant relationships, prices, and stock availability.
     */
    public function publishedQuery(): Builder
    {
        $query = Product::query()
            ->where('status', ProductStatus::Published)
            ->whereIn('sales_channel', [SalesChannel::Retail, SalesChannel::Both])
            ->whereHas('category', fn ($q) => $q->where('is_active', true)
                ->where(fn ($path) => $path->whereNull('parent_id')
                    ->orWhereHas('parent', fn ($parent) => $parent->where('is_active', true)
                        ->where(fn ($path) => $path->whereNull('parent_id')
                            ->orWhereHas('parent', fn ($ancestor) => $ancestor->where('is_active', true)->whereNull('parent_id'))))))
            ->with([
                'category', 'media',
                'variants' => fn ($q) => $this->withVariantAvailability($this->pricing->withStorePrice($q->getQuery(), $this->customer()))
                    ->where('is_active', true)->with('media')->orderBy('sort_order')->orderBy('id'),
            ]);

        return $this->withStoreAvailability($query);
    }

    /**
     * Attach sellable store_available subquery to variant queries.
     */
    private function withVariantAvailability(Builder $query): Builder
    {
        $stock = DB::table('stock_levels')
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_levels.location_id');
        $this->sellableLocationRule($stock);

        return $query->selectSub($stock->whereColumn('stock_levels.product_variant_id', 'product_variants.id')
            ->selectRaw('COALESCE(SUM(stock_levels.available), 0)'), 'store_available');
    }

    // ------------------------------------------------------------------ Filtering & Sorting

    /**
     * Apply storefront filter parameters (category, color, price, availability, occasion, search) to query.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['category'])) {
            $category = $this->resolveCategory($filters['category']);
            if ($category !== null) {
                $query->whereIn('products.category_id', [$category->id, ...$this->cachedDescendantIds($category)]);
            } else {
                // Nonexistent category resolves to empty result set without throwing an exception
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['color'])) {
            $color = $filters['color'];
            $query->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->where(fn ($inner) => $inner
                    ->whereRaw('LOWER(color_name_en) = LOWER(?)', [$color])
                    ->orWhere('color_name_ar', $color)));
        }

        if (isset($filters['price_min']) || isset($filters['price_max']) || in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)) {
            $query->leftJoinLateral($this->minimumPriceQuery(), 'catalog_price');
            if (isset($filters['price_min'])) {
                $query->where('catalog_price.minimum_price', '>=', $filters['price_min']);
            }
            if (isset($filters['price_max'])) {
                $query->where('catalog_price.minimum_price', '<=', $filters['price_max']);
            }
        }

        // stock_levels_valid_balances enforces available >= 0 in PostgreSQL,
        // so a positive row is equivalent to a positive sum on the card.
        if (! empty($filters['in_stock'])) {
            $query->whereExists(fn ($sub) => $this->sellableExists($sub));
        }

        if (! empty($filters['occasions'])) {
            $query->where(function (Builder $q) use ($filters) {
                foreach ($filters['occasions'] as $occasion) {
                    $q->orWhereJsonContains('products.occasions', $occasion);
                }
            });
        }

        if (! empty($filters['search'])) {
            $term = SearchTerm::pattern(mb_strtolower(trim($filters['search'])));
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(products.name_en) LIKE ?', [$term])
                ->orWhereRaw('LOWER(products.name_ar) LIKE ?', [$term])
                ->orWhereHas('variants', fn ($v) => $v->where('is_active', true)->whereRaw('LOWER(sku) LIKE ?', [$term])));
        }

        return $this->applySort($query, $filters['sort'] ?? 'featured');
    }

    /**
     * Apply sorting strategy (featured, price_asc, price_desc, name, best_selling, or newest).
     */
    public function applySort(Builder $query, string $sort): Builder
    {
        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            return $query->orderByRaw('catalog_price.minimum_price '.($sort === 'price_asc' ? 'ASC' : 'DESC').' NULLS LAST')
                ->orderBy('products.id');
        }

        return match ($sort) {
            'featured' => $query->orderByDesc('products.is_featured')
                ->orderByDesc('products.sales_count')
                ->orderByDesc('products.created_at')
                ->orderByDesc('products.id'),
            'name' => $query->orderByRaw("COALESCE(NULLIF(products.name_en, ''), products.name_ar) ASC")
                ->orderByDesc('products.id'),
            'best_selling' => $query->orderByDesc('products.sales_count')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    /**
     * Memoized category lookup by slug or integer ID.
     */
    private function resolveCategory(int|string $value): ?Category
    {
        // L4: one lookup per distinct value per request.
        $key = 'c:'.((string) $value);
        if (array_key_exists($key, $this->categoryMemo)) {
            return $this->categoryMemo[$key];
        }
        $category = Category::query()->where('is_active', true)->where('slug', $value)->first();

        return $this->categoryMemo[$key] = $category ?? (ctype_digit((string) $value) && strlen((string) $value) <= 18
            ? Category::query()->where('is_active', true)->find((int) $value) : null);
    }

    /** L4: descendant ids memoized per category per request. */
    private function cachedDescendantIds(Category $category): array
    {
        return $this->descendantMemo[$category->id] ??= $category->descendantIds();
    }

    // ------------------------------------------------------------------ Display Pricing

    /**
     * F23: Compute storefront discount percentage against compare_at basis price.
     */
    public static function discountPercentage(float $unitPrice, float $basis): ?int
    {
        if ($basis <= $unitPrice) {
            return null;
        }
        $percentage = (int) round((1 - $unitPrice / $basis) * 100);

        return $percentage >= 1 ? $percentage : null;
    }

    /**
     * Compute display price for product card: finds cheapest active purchasable variant via PricingService.
     * Prefers in-stock variant to prevent quick-add actions targeting sold-out items.
     *
     * @return array{price: float, base_price: float, compare_at: float|null, discount_percentage: int|null, variant_id: int}|null
     */
    public function displayPrice(Product $product, ?Customer $customer, ?array $availability = null): ?array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->where('is_active', true)->orderBy('sort_order')->get();

        // F18: Preferred in-stock variant selection for quick add-to-cart actions
        $availability ??= $variants->every(fn ($variant) => array_key_exists('store_available', $variant->getAttributes()))
            ? $variants->mapWithKeys(fn ($variant) => [$variant->id => (float) $variant->store_available])->all()
            : $this->availabilityMap($variants->pluck('id')->all());

        $best = null;
        $bestInStock = null;
        foreach ($variants as $variant) {
            try {
                $resolved = $this->variantPrice($variant, $customer);
            } catch (BusinessException) {
                continue;
            }

            $price = (float) $resolved['unit_price'];
            $compareAt = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;
            $basis = max((float) $compareAt, (float) $resolved['base_price']);
            $discount = self::discountPercentage($price, $basis);

            $display = [
                'price' => $price,
                'base_price' => (float) $resolved['base_price'],
                'compare_at' => $discount !== null ? $basis : null,
                'discount_percentage' => $discount,
                'variant_id' => $variant->id,
            ];
            if ($best === null || $price < $best['price']) {
                $best = $display;
            }
            $inStock = $availability === null || ($availability[$variant->id] ?? 0) > 0;
            if ($inStock && ($bestInStock === null || $price < $bestInStock['price'])) {
                $bestInStock = $display;
            }
        }

        return $bestInStock ?? $best;
    }

    /**
     * Resolve the currently authenticated and approved customer model, if any.
     */
    public function customer(): ?Customer
    {
        $user = auth('sanctum')->user();

        return $user instanceof Customer && $user->status === CustomerStatus::Approved ? $user : null;
    }

    /**
     * Resolve variant unit price and base price for the customer.
     *
     * @return array{unit_price: float, base_price: float}
     */
    public function variantPrice(ProductVariant $variant, ?Customer $customer): array
    {
        if (! array_key_exists('store_price', $variant->getAttributes())) {
            return $this->pricing->resolve($variant, $customer, 1);
        }
        if ($variant->store_price === null) {
            throw new BusinessException('الصنف غير مُسعَّر.');
        }

        return ['unit_price' => (float) $variant->store_price, 'base_price' => (float) ($variant->getAttributes()['store_base_price'] ?? $this->pricing->basePriceForCustomer($variant, $customer) ?? $variant->store_price)];
    }

    private function minimumPriceQuery(): \Illuminate\Database\Query\Builder
    {
        $customer = $this->customer();
        if (! $this->pricing->catalogContext($customer)['public_retail']) {
            return $this->liveMinimumPriceQuery($customer);
        }
        $live = $this->liveMinimumPriceQuery(null);

        // A missing/expired snapshot remains correct before the first warm-up
        // or if workers stop. PostgreSQL evaluates only the selected CASE arm.
        return DB::query()->selectRaw('CASE WHEN products.catalog_price_refreshed_at > ? THEN products.catalog_min_retail_price ELSE ('.$live->toSql().') END AS minimum_price',
            [now()->subMinutes(10), ...$live->getBindings()]);
    }

    /**
     * Build lateral minimum price query across active variants for a customer context.
     */
    public function liveMinimumPriceQuery(?Customer $customer): \Illuminate\Database\Query\Builder
    {
        $variants = $this->withVariantAvailability($this->pricing->withStorePrice(ProductVariant::query(), $customer))
            ->where('is_active', true);

        return DB::query()->fromSub($variants, 'priced_variants')
            ->selectRaw('COALESCE(MIN(priced_variants.store_price) FILTER (WHERE priced_variants.store_available > 0), MIN(priced_variants.store_price)) AS minimum_price')
            ->whereColumn('priced_variants.product_id', 'products.id');
    }

    // ------------------------------------------------------------------ Facets & Aggregations

    /**
     * Calculate structural facets (categories, colors, occasions) and price distribution ranges.
     * Color and price filters are excluded from facet counts to prevent options from disappearing upon selection.
     */
    public function facets(array $filters): array
    {
        if (! empty($filters['search']) || (string) config('cache.default') === 'array') {
            return [...$this->computeStructuralFacets($filters), 'price_ranges' => $this->computePriceRanges($filters)];
        }
        $key = $this->facetsCacheKey($filters);
        $context = $this->pricing->catalogContext($this->customer());

        return [...$this->cachedFacetsPart($key.':structure', fn () => $this->computeStructuralFacets($filters)),
            'price_ranges' => $this->cachedFacetsPart($key.':prices:'.hash('sha256', $context['key']), fn () => $this->computePriceRanges($filters))];
    }

    private function cachedFacetsPart(string $key, \Closure $load): mixed
    {
        $flexible = fn () => Cache::flexible($key, [60, 600], $load, ['seconds' => 120]);
        if (Cache::has($key)) {
            return $flexible();
        }
        try {
            return Cache::lock($key.':build', 120)->block(5, $flexible);
        } catch (LockTimeoutException) {
            throw new BusinessException('جاري تحديث الفلاتر؛ أعد المحاولة بعد لحظات.', [], 503);
        }
    }

    /**
     * H2: normalize the category to its id so slug/id spellings of the same
     * category share one cache entry instead of exploding the key space.
     */
    private function facetsCacheKey(array $filters): string
    {
        $base = Arr::only($filters, ['category', 'search', 'occasions', 'in_stock']);
        if (! empty($base['category'])) {
            $category = $this->resolveCategory($base['category']);
            $base['category'] = $category !== null ? 'id:'.$category->id : 'missing';
        }
        if (isset($base['search'])) {
            $base['search'] = mb_strtolower(trim((string) $base['search']));
        }
        if (isset($base['occasions'])) {
            sort($base['occasions']);
        }
        $base['in_stock'] = ! empty($base['in_stock']);
        ksort($base);

        // v3 stores plain arrays so database-cache unserialization never needs PHP classes.
        return 'store:facets:v3:'.hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $filters */
    private function computeStructuralFacets(array $filters): array
    {
        $base = Arr::only($filters, ['category', 'search', 'occasions', 'in_stock']);
        $scope = fn (array $extra = []) => $this
            ->applyFilters($this->publishedQuery()->withoutEagerLoads(), [...$base, ...$extra])
            ->reorder();

        $colors = DB::table('product_variants')
            ->whereIn('product_id', $scope()->select('products.id'))
            ->where('is_active', true)->whereNull('deleted_at')
            ->whereNotNull('color_name_en')->where('color_name_en', '<>', '')
            ->groupByRaw('LOWER(color_name_en)')
            ->selectRaw('LOWER(color_name_en) AS id, MIN(color_name_en) AS name_en, MIN(color_name_ar) AS name_ar, MIN(color_hex) AS hex, COUNT(DISTINCT product_id) AS count')
            ->orderByDesc('count')->orderBy('id')->limit(24)->get()
            ->map(fn ($c) => [...(array) $c, 'count' => (int) $c->count]);

        // F13: Aggregate occasion counters in a single query using PostgreSQL conditional filtering
        $occasionBase = $this->applyFilters($this->publishedQuery()->withoutEagerLoads(),
            Arr::except($base, 'occasions'))->reorder()->select('products.id', 'products.occasions');
        $occasionCounts = DB::query()->fromSub($occasionBase->toBase(), 'occasion_scope')
            ->selectRaw(collect(Occasion::cases())->map(fn (Occasion $o) => sprintf(
                "COUNT(*) FILTER (WHERE occasion_scope.occasions @> '%s') AS %s",
                json_encode([$o->value]), 'occ_'.$o->value
            ))->implode(', '))
            ->first();
        $occasions = collect(Occasion::cases())->map(fn (Occasion $o) => [
            'id' => $o->value,
            'count' => (int) ($occasionCounts->{'occ_'.$o->value} ?? 0),
        ])->values();

        return [
            'total' => $scope()->count('products.id'),
            'colors' => $colors->all(),
            'occasions' => $occasions->all(),
        ];
    }

    private function computePriceRanges(array $filters): array
    {
        $scope = $this->applyFilters($this->publishedQuery()->withoutEagerLoads(), Arr::only($filters, ['category', 'search', 'occasions', 'in_stock']))
            ->reorder()->select('products.id')->leftJoinLateral($this->minimumPriceQuery(), 'catalog_price')->addSelect('catalog_price.minimum_price');
        $query = DB::query()->fromSub($scope->toBase(), 'price_scope');
        foreach (array_values(config('storefront.price_ranges')) as $index => $range) {
            $bounds = [];
            $bindings = [];
            foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
                if ($range[$bound] !== null) {
                    $bounds[] = 'minimum_price '.$operator.' ?';
                    $bindings[] = $range[$bound];
                }
            }
            $query->selectRaw('COUNT(*) FILTER (WHERE '.($bounds === [] ? 'minimum_price IS NOT NULL' : implode(' AND ', $bounds)).') AS range_'.$index, $bindings);
        }
        $counts = $query->first();

        return collect(config('storefront.price_ranges'))->values()->map(fn (array $range, int $index) => [
            ...$range, 'count' => (int) $counts->{'range_'.$index},
        ])->all();
    }

    /**
     * Retrieve featured and best-selling product IDs for hero showcase carousel.
     *
     * @return list<int>
     */
    public function showcaseIds(): array
    {
        $limit = (int) config('storefront.showcase_limit', 6);
        $load = function () use ($limit): array {
            $featured = $this->applySort($this->publishedQuery()->withoutEagerLoads()->where('products.is_featured', true), 'featured')
                ->limit($limit)->pluck('products.id')->all();
            $fill = count($featured) < $limit
                ? $this->applySort($this->publishedQuery()->withoutEagerLoads()->whereNotIn('products.id', $featured), 'best_selling')
                    ->limit($limit - count($featured))->pluck('products.id')->all() : [];

            return [...$featured, ...$fill];
        };

        return (string) config('cache.default') === 'array' ? $load()
            : Cache::flexible('store:showcase:ids:'.$limit, [300, 1800], $load, ['seconds' => 120]);
    }

    // ------------------------------------------------------------------ Home & Recommendations

    /**
     * Retrieve section product IDs for homepage (latest, bestsellers, discounted).
     * IDs are cached in the controller; prices are always computed fresh.
     *
     * @return array{latest_ids: list<int>, best_seller_ids: list<int>, discounted_ids: list<int>}
     */
    public function homeSectionIds(): array
    {
        $latest = $this->publishedQuery()->reorder()->orderByDesc('products.created_at')->orderByDesc('products.id')->limit(12)->pluck('products.id')->all();
        $best = $this->publishedQuery()->reorder()->orderByDesc('products.sales_count')->orderByDesc('products.id')->limit(12)->pluck('products.id')->all();

        $discounted = $this->publishedQuery()->reorder()
            ->where(fn ($q) => $q
                ->whereHas('variants', fn ($v) => $v->where('is_active', true)
                    ->whereNotNull('retail_price')
                    ->whereNotNull('compare_at_price')
                    ->whereColumn('compare_at_price', '>', 'base_price'))
                ->orWhereHas('variants', fn ($v) => $v->where('is_active', true)
                    ->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('price_list_items')
                        ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
                        ->whereColumn('price_list_items.product_variant_id', 'product_variants.id')
                        ->where('price_lists.is_active', true)
                        ->where('price_lists.type', 'retail')
                        ->whereNull('price_lists.branch_id')
                        ->where('price_list_items.min_qty', '<=', 1)
                        ->where(fn ($w) => $w->whereNull('price_lists.starts_at')->orWhere('price_lists.starts_at', '<=', now()))
                        ->where(fn ($w) => $w->whereNull('price_lists.ends_at')->orWhere('price_lists.ends_at', '>=', now()))
                        ->where(fn ($w) => $w->whereNull('price_list_items.starts_at')->orWhere('price_list_items.starts_at', '<=', now()))
                        ->where(fn ($w) => $w->whereNull('price_list_items.ends_at')->orWhere('price_list_items.ends_at', '>=', now()))
                        ->whereColumn('price_list_items.price', '<', 'product_variants.base_price'))))
            ->orderByDesc('products.sales_count')->orderByDesc('products.id')
            ->limit(12)->pluck('products.id')->all();

        return [
            'latest_ids' => $latest,
            'best_seller_ids' => $best,
            'discounted_ids' => $discounted,
        ];
    }

    /**
     * Retrieve similar products within the same category, prioritizing in-stock items.
     *
     * @return Collection<int, Product>
     */
    public function similar(Product $product): Collection
    {
        // F12: Two-stage retrieval: fetch 40 candidates indexed by sales_count,
        // prioritize available stock in memory, then load complete models for the top 8.
        $candidates = $this->publishedQuery()->withoutEagerLoads()
            ->where('products.id', '!=', $product->id)
            ->where('products.category_id', $product->category_id)
            ->reorder()
            ->orderByDesc('products.sales_count')
            ->orderByDesc('products.id')
            ->limit(40)
            ->get();

        $orderedIds = $candidates
            ->sort(fn ($a, $b) => [((float) $b->store_available > 0 ? 1 : 0), (int) $b->sales_count, (int) $b->id]
                <=> [((float) $a->store_available > 0 ? 1 : 0), (int) $a->sales_count, (int) $a->id])
            ->take(8)
            ->pluck('id')
            ->all();

        $full = $orderedIds === []
            ? new Collection
            : $this->publishedQuery()
                ->whereIn('products.id', $orderedIds)
                ->get()
                ->keyBy('id');

        return new Collection(collect($orderedIds)
            ->map(fn ($id) => $full->get($id))
            ->filter()
            ->all());
    }
}
