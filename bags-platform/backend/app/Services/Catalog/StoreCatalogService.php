<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\Catalog\Occasion;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\PricingService;
use App\Services\Shared\SearchTerm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only store catalog service: queries, facets, filtering, and real-time online stock availability.
 * Variant purchase availability is the largest sellable balance a single branch can fulfill.
 * Only published products are visible on the storefront.
 */
class StoreCatalogService
{
    public const HOME_CACHE_KEY = 'store_home_data:v2';

    public const HOME_BANNERS_CACHE_KEY = 'store:home:banners:v1';

    public const FACETS_VERSION_KEY = 'store:facets:version';

    /** Per-request memo so facets/index resolve the category only once. */
    private array $categoryMemo = [];

    private array $descendantMemo = [];

    public function __construct(private readonly PricingService $pricing) {}

    public static function facetsVersion(): int
    {
        return max(1, (int) Cache::get(self::FACETS_VERSION_KEY, 1));
    }

    /**
     * Drop cached public taxonomy payloads (categories & brands) across active locales.
     */
    public static function forgetTaxonomyCaches(): void
    {
        $locales = array_values(array_unique([
            'ar',
            'en',
            app()->getLocale(),
            (string) config('app.locale', 'en'),
            (string) config('app.fallback_locale', 'en'),
        ]));
        foreach ($locales as $locale) {
            if ($locale !== '') {
                Cache::forget('store:categories:v1:'.$locale);
                Cache::forget('store:brands:v1:'.$locale);
                Cache::forget('store:cities:v1:'.$locale);
            }
        }
    }

    /**
     * Drop cached public catalog ID sets and atomically bump facet cache generation after catalog changes.
     */
    public static function forgetStorefrontCaches(): void
    {
        Cache::forget(self::HOME_CACHE_KEY);
        Cache::forget(self::HOME_BANNERS_CACHE_KEY);
        Cache::forget('store:showcase:ids:'.(int) config('storefront.showcase_limit', 6));
        if (! Cache::add(self::FACETS_VERSION_KEY, 2)) {
            Cache::increment(self::FACETS_VERSION_KEY);
        }
    }

    // ------------------------------------------------------------------ Availability

    /**
     * Attach aggregated store_available subquery to product queries.
     */
    public function withStoreAvailability(Builder $query): Builder
    {
        // Exclude receiving and damaged bins so only customer-shippable on-hand stock is counted as available.
        $sub = $this->productSellableStockSubquery()
            ->selectRaw('COALESCE(SUM(stock_levels.available), 0)');

        return $query->select('products.*')->selectSub($sub, 'store_available');
    }

    /**
     * Batch-query the total sellable stock for each variant across all active branches.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float> Map of variant ID to available stock quantity
     */
    public function availabilityMap(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return $this->sellableStock()
            ->whereIn('stock_levels.product_variant_id', $variantIds)
            ->groupBy('stock_levels.product_variant_id')
            ->selectRaw('stock_levels.product_variant_id, SUM(stock_levels.available) AS total')
            ->pluck('total', 'product_variant_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }

    /**
     * Compute the maximum single-branch fulfillable quantity for each variant.
     *
     * Orders cannot be split across multiple branches; an order line must be sourced entirely from one location.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float> Map of variant ID to maximum single-branch stock
     */
    public function fulfillableQuantityMap(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $byBranch = $this->variantAvailabilityByBranch()
            ->whereIn('stock_levels.product_variant_id', $variantIds)
            ->addSelect('stock_levels.product_variant_id')
            ->groupBy('stock_levels.product_variant_id');

        return DB::query()->fromSub($byBranch, 'branch_stock')
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, MAX(branch_available) AS available')
            ->pluck('available', 'product_variant_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }

    // ------------------------------------------------------------------ Catalog Queries

    /**
     * Base query for published retail products with variant relationships, prices, and stock availability.
     */
    public function publishedQuery(): Builder
    {
        $query = $this->publishedBaseQuery()->with([
            'category', 'media',
            'variants' => fn ($q) => $this->withVariantAvailability($this->pricing->withStorePrice($q->getQuery(), $this->customer()))
                ->where('is_active', true)->with('media')->orderBy('sort_order')->orderBy('id'),
        ]);

        return $this->withStoreAvailability($query);
    }

    /**
     * Light published-product query for ID/facet aggregation where relationships,
     * prices and stock subqueries are never read.
     */
    public function publishedBaseQuery(): Builder
    {
        return Product::query()
            ->publishedForRetail()
            ->whereHas('category', fn ($q) => $q->activeHierarchy());
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
            $this->applyCategoryFilter($query, $filters['category']);
        }

        if (! empty($filters['color'])) {
            $this->applyColorFilter($query, (string) $filters['color']);
        }

        if (isset($filters['price_min']) || isset($filters['price_max']) || in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true)) {
            $this->applyPriceJoinAndBounds($query, $filters);
        }

        // stock_levels_valid_balances enforces available >= 0 in PostgreSQL,
        // so a positive row is equivalent to a positive sum on the card.
        if (! empty($filters['in_stock'])) {
            $query->whereExists(fn ($sub) => $this->sellableExists($sub));
        }

        if (! empty($filters['occasions'])) {
            $this->applyOccasionsFilter($query, $filters['occasions']);
        }

        if (! empty($filters['search'])) {
            $this->applySearchFilter($query, (string) $filters['search']);
        }

        if (! empty($filters['on_sale']) || ! empty($filters['discounted'])) {
            $this->applyDiscountedFilter($query, $this->customer());
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

    // ------------------------------------------------------------------ Display Pricing

    /**
     * Computes storefront discount percentage against compare_at basis price.
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

        $availability ??= $this->resolveVariantAvailability($variants);

        $best = null;
        $bestInStock = null;
        foreach ($variants as $variant) {
            $display = $this->buildVariantDisplayEntry($variant, $customer);
            if ($display === null) {
                continue;
            }

            if ($best === null || $display['price'] < $best['price']) {
                $best = $display;
            }
            $inStock = $availability === null || ($availability[$variant->id] ?? 0) > 0;
            if ($inStock && ($bestInStock === null || $display['price'] < $bestInStock['price'])) {
                $bestInStock = $display;
            }
        }

        return $bestInStock ?? $best;
    }

    /**
     * Resolve the currently authenticated shopper customer model, if eligible.
     */
    public function customer(): ?Customer
    {
        return Customer::resolveShopper(auth('sanctum')->user());
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

        return [
            'unit_price' => (float) $variant->store_price,
            'base_price' => (float) ($variant->getAttributes()['store_base_price'] ?? $this->pricing->basePriceForCustomer($variant, $customer) ?? $variant->store_price),
        ];
    }

    /**
     * Build lateral minimum price query across active variants for a customer context.
     */
    public function liveMinimumPriceQuery(?Customer $customer): QueryBuilder
    {
        $variants = $this->withVariantAvailability($this->pricing->withStorePrice(ProductVariant::query(), $customer))
            ->where('is_active', true);

        return DB::query()->fromSub($variants, 'priced_variants')
            ->selectRaw('COALESCE(MIN(priced_variants.store_price) FILTER (WHERE priced_variants.store_available > 0), MIN(priced_variants.store_price)) AS minimum_price')
            ->whereColumn('priced_variants.product_id', 'products.id');
    }

    /** Build the discount flag query for the same variant the product card displays. */
    public function liveRetailDiscountQuery(?Customer $customer = null): QueryBuilder
    {
        $variants = $this->withVariantAvailability($this->pricing->withStorePrice(ProductVariant::query(), $customer))
            ->where('is_active', true);
        $basis = 'GREATEST(priced.compare_at_price, priced.store_base_price)';

        return DB::query()->fromSub($variants, 'priced')
            ->whereColumn('priced.product_id', 'products.id')
            ->whereNotNull('priced.store_price')
            ->orderByRaw('(priced.store_available > 0) DESC')
            ->orderBy('priced.store_price')->orderBy('priced.sort_order')->orderBy('priced.id')
            ->limit(1)
            ->selectRaw("ROUND((1 - priced.store_price / NULLIF({$basis}, 0)) * 100) >= 1");
    }

    // ------------------------------------------------------------------ Facets & Aggregations

    /**
     * Calculate structural facets (categories, colors, occasions) and price distribution ranges.
     * Color and price filters are excluded from facet counts to prevent options from disappearing upon selection.
     */
    public function facets(array $filters): array
    {
        $base = $this->normalizeFacetFilters($filters);

        // Arbitrary search strings have near-zero cache reuse and let public
        // callers mint unbounded short-lived keys. Compute these facets without
        // adding attacker-controlled entries to the shared cache.
        if ((string) config('cache.default') === 'array' || trim((string) ($base['search'] ?? '')) !== '') {
            return [...$this->computeStructuralFacets($base), 'price_ranges' => $this->computePriceRanges($base)];
        }

        $key = $this->facetsCacheKey($base);
        $context = $this->pricing->catalogContext($this->customer());
        $ttl = [60, 600];

        return [
            ...$this->cachedFacetsPart($key.':structure'.($base['on_sale'] ? ':'.hash('sha256', $context['key']) : ''), fn () => $this->computeStructuralFacets($base), $ttl),
            'price_ranges' => $this->cachedFacetsPart($key.':prices:'.hash('sha256', $context['key']), fn () => $this->computePriceRanges($base), $ttl),
        ];
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
            $featured = $this->applySort($this->publishedBaseQuery()->where('products.is_featured', true), 'featured')
                ->limit($limit)->pluck('products.id')->all();
            $fill = count($featured) < $limit
                ? $this->applySort($this->publishedBaseQuery()->whereNotIn('products.id', $featured), 'best_selling')
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
        $latest = $this->publishedBaseQuery()->reorder()->orderByDesc('products.created_at')->orderByDesc('products.id')->limit(12)->pluck('products.id')->all();
        $best = $this->publishedBaseQuery()->reorder()->orderByDesc('products.sales_count')->orderByDesc('products.id')->limit(12)->pluck('products.id')->all();

        return [
            'latest_ids' => $latest,
            'best_seller_ids' => $best,
            'discounted_ids' => $this->discountedProductIds(null, 30),
        ];
    }

    public function personalizedDiscountedIds(): ?array
    {
        $customer = $this->customer();
        if ($customer === null || $this->pricing->catalogContext($customer)['public_retail']) {
            return null;
        }

        return $this->discountedProductIds($customer, 12);
    }

    /**
     * Retrieve similar products within the same category, prioritizing in-stock items.
     *
     * @return Collection<int, Product>
     */
    public function similar(Product $product): Collection
    {
        // Rank in-stock products in SQL and hydrate only the eight rows we return.
        return $this->publishedQuery()
            ->where('products.id', '!=', $product->id)
            ->where('products.category_id', $product->category_id)
            ->reorder()
            ->orderByDesc('store_available')
            ->orderByDesc('products.sales_count')
            ->orderByDesc('products.id')
            ->limit(8)
            ->get();
    }

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

    private function productSellableStockSubquery(): QueryBuilder
    {
        return $this->sellableStock()
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->where('product_variants.is_active', true)
            ->whereNull('product_variants.deleted_at')
            ->whereColumn('product_variants.product_id', 'products.id');
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

    private function variantAvailabilityByBranch(): QueryBuilder
    {
        return $this->sellableStock()->groupBy('stock_levels.branch_id')
            ->selectRaw('SUM(stock_levels.available) AS branch_available');
    }

    private function sellableStock(): QueryBuilder
    {
        $stock = DB::table('stock_levels')
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_levels.location_id');
        $this->sellableLocationRule($stock);

        return $stock;
    }

    /**
     * Attach sellable store_available subquery to variant queries.
     */
    private function withVariantAvailability(Builder $query): Builder
    {
        $stock = $this->variantAvailabilityByBranch()
            ->whereColumn('stock_levels.product_variant_id', 'product_variants.id')
            ->orderByDesc('branch_available')
            ->limit(1);

        return $query->selectSub($stock, 'store_available');
    }

    private function applyCategoryFilter(Builder $query, mixed $categoryFilter): void
    {
        $category = $this->resolveCategory($categoryFilter);
        if ($category !== null) {
            $query->whereIn('products.category_id', [$category->id, ...$this->cachedDescendantIds($category)]);

            return;
        }

        // Nonexistent category resolves to empty result set without throwing an exception
        $query->whereRaw('1 = 0');
    }

    private function applyColorFilter(Builder $query, string $color): void
    {
        $query->whereHas('variants', fn ($q) => $q->where('is_active', true)
            ->where(fn ($inner) => $inner
                ->whereRaw('LOWER(color_name_en) = LOWER(?)', [$color])
                ->orWhere('color_name_ar', $color)));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyPriceJoinAndBounds(Builder $query, array $filters): void
    {
        $query->leftJoinLateral($this->minimumPriceQuery(), 'catalog_price');
        if (isset($filters['price_min'])) {
            $query->where('catalog_price.minimum_price', '>=', $filters['price_min']);
        }
        if (isset($filters['price_max'])) {
            $query->where('catalog_price.minimum_price', '<=', $filters['price_max']);
        }
    }

    /**
     * @param  iterable<int, string>  $occasions
     */
    private function applyOccasionsFilter(Builder $query, iterable $occasions): void
    {
        $query->where(function (Builder $q) use ($occasions) {
            foreach ($occasions as $occasion) {
                $q->orWhereJsonContains('products.occasions', $occasion);
            }
        });
    }

    private function applySearchFilter(Builder $query, string $search): void
    {
        $term = SearchTerm::pattern(trim($search));
        $query->where(fn (Builder $q) => $q
            ->whereLike('products.name_en', $term, caseSensitive: false)
            ->orWhereLike('products.name_ar', $term, caseSensitive: false)
            ->orWhereHas('variants', fn ($v) => $v->where('is_active', true)->whereRaw('LOWER(sku) LIKE ?', [mb_strtolower($term)])));
    }

    /** Match the priced variant selected by the product card. */
    private function applyDiscountedFilter(Builder $query, ?Customer $customer): void
    {
        if ($customer === null) {
            $freshAfter = now()->subMinutes(10);
            $live = $this->liveRetailDiscountQuery();
            $query->where(fn (Builder $sale) => $sale
                ->where(fn (Builder $snapshot) => $snapshot
                    ->where('products.catalog_price_refreshed_at', '>', $freshAfter)
                    ->where('products.catalog_has_retail_discount', true))
                ->orWhere(fn (Builder $fallback) => $fallback
                    ->where(fn (Builder $stale) => $stale
                        ->whereNull('products.catalog_price_refreshed_at')
                        ->orWhere('products.catalog_price_refreshed_at', '<=', $freshAfter))
                    ->whereRaw('('.$live->toSql().') IS TRUE', $live->getBindings())));

            return;
        }

        $discount = $this->liveRetailDiscountQuery($customer);
        $query->whereRaw('('.$discount->toSql().') IS TRUE', $discount->getBindings());
    }

    /**
     * Memoized category lookup by slug or integer ID.
     */
    private function resolveCategory(int|string $value): ?Category
    {
        // Memoize category lookup per request to prevent redundant queries during tree traversals.
        $key = 'c:'.((string) $value);
        if (array_key_exists($key, $this->categoryMemo)) {
            return $this->categoryMemo[$key];
        }
        $category = Category::query()->where('is_active', true)->where('slug', $value)->first();

        return $this->categoryMemo[$key] = $category ?? (ctype_digit((string) $value) && strlen((string) $value) <= 18
            ? Category::query()->where('is_active', true)->find((int) $value) : null);
    }

    /** Memoize descendant category IDs per request to prevent redundant recursive tree queries. */
    private function cachedDescendantIds(Category $category): array
    {
        return $this->descendantMemo[$category->id] ??= $category->descendantIds();
    }

    /**
     * @return array<int, float>
     */
    private function resolveVariantAvailability(Collection $variants): array
    {
        // Prefer in-stock variant for card display price to prevent quick-add buttons targeting sold-out variants.
        return $variants->every(fn ($variant) => array_key_exists('store_available', $variant->getAttributes()))
            ? $variants->mapWithKeys(fn ($variant) => [$variant->id => (float) $variant->store_available])->all()
            : $this->fulfillableQuantityMap($variants->pluck('id')->all());
    }

    /**
     * @return array{price: float, base_price: float, compare_at: float|null, discount_percentage: int|null, variant_id: int}|null
     */
    private function buildVariantDisplayEntry(ProductVariant $variant, ?Customer $customer): ?array
    {
        try {
            $resolved = $this->variantPrice($variant, $customer);
        } catch (BusinessException) {
            return null;
        }

        $price = (float) $resolved['unit_price'];
        $compareAt = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;
        $basis = max((float) $compareAt, (float) $resolved['base_price']);
        $discount = self::discountPercentage($price, $basis);

        return [
            'price' => $price,
            'base_price' => (float) $resolved['base_price'],
            'compare_at' => $discount !== null ? $basis : null,
            'discount_percentage' => $discount,
            'variant_id' => $variant->id,
        ];
    }

    private function minimumPriceQuery(): QueryBuilder
    {
        $customer = $this->customer();
        if (! $this->pricing->catalogContext($customer)['public_retail']) {
            return $this->liveMinimumPriceQuery($customer);
        }
        $live = $this->liveMinimumPriceQuery(null);

        // A missing/expired snapshot remains correct before the first warm-up
        // or if workers stop. PostgreSQL evaluates only the selected CASE arm.
        return DB::query()->selectRaw(
            'CASE WHEN products.catalog_price_refreshed_at > ? THEN products.catalog_min_retail_price ELSE ('.$live->toSql().') END AS minimum_price',
            [now()->subMinutes(10), ...$live->getBindings()],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFacetFilters(array $filters): array
    {
        $filters['on_sale'] = ! empty($filters['on_sale']) || ! empty($filters['discounted']);

        return Arr::only($filters, ['category', 'search', 'occasions', 'in_stock', 'on_sale']);
    }

    /** @param array{0: int, 1: int} $ttl */
    private function cachedFacetsPart(string $key, \Closure $load, array $ttl = [60, 600]): mixed
    {
        $stableIdentity = preg_replace('/^store:facets:v3(?::r\d+)?:/', '', $key) ?? $key;
        $staleKey = 'store:facets:last-good:'.hash('sha256', $stableIdentity);
        $refresh = function () use ($load, $staleKey): mixed {
            $value = $load();
            Cache::put($staleKey, $value, 600);

            return $value;
        };
        $flexible = fn () => Cache::flexible($key, $ttl, $refresh, ['seconds' => 15]);
        if (Cache::has($key)) {
            return $flexible();
        }
        try {
            return Cache::lock($key.':build', 15)->block(2, $flexible);
        } catch (LockTimeoutException) {
            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                return $stale;
            }

            throw new BusinessException('جاري تحديث الفلاتر؛ أعد المحاولة بعد لحظات.', [], 503);
        }
    }

    /**
     * Normalizes the category to its ID so slug/id spellings of the same
     * category share one cache entry instead of exploding the key space.
     *
     * @param  array<string, mixed>  $base
     */
    private function facetsCacheKey(array $base): string
    {
        if (! empty($base['category'])) {
            $category = $this->resolveCategory($base['category']);
            $base['category'] = $category !== null ? 'id:'.$category->id : 'missing';
        }
        if (isset($base['search'])) {
            $search = mb_strtolower(trim((string) $base['search']));
            if (mb_strlen($search) > 60) {
                $search = mb_substr($search, 0, 60).':'.substr(hash('sha256', $search), 0, 12);
            }
            $base['search'] = $search;
        }
        if (isset($base['occasions'])) {
            sort($base['occasions']);
        }
        $base['in_stock'] = ! empty($base['in_stock']);
        $base['on_sale'] = ! empty($base['on_sale']);
        ksort($base);

        // v3 stores plain arrays so database-cache unserialization never needs PHP classes.
        $version = self::facetsVersion();
        $prefix = $version === 1 ? 'store:facets:v3:' : 'store:facets:v3:r'.$version.':';

        return $prefix.hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $base */
    private function computeStructuralFacets(array $base): array
    {
        [$total, $occasions] = $this->computeOccasionFacets($base);

        return [
            'total' => $total,
            'colors' => $this->computeColorFacets($base),
            'occasions' => $occasions,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return list<array<string, mixed>>
     */
    private function computeColorFacets(array $base): array
    {
        $scope = $this->applyFilters($this->publishedBaseQuery(), $base)->reorder();

        return DB::table('product_variants')
            ->whereIn('product_id', $scope->select('products.id'))
            ->where('is_active', true)->whereNull('deleted_at')
            ->whereNotNull('color_name_en')->where('color_name_en', '<>', '')
            ->groupByRaw('LOWER(color_name_en)')
            ->selectRaw('LOWER(color_name_en) AS id, MIN(color_name_en) AS name_en, MIN(color_name_ar) AS name_ar, MIN(color_hex) AS hex, COUNT(DISTINCT product_id) AS count')
            ->orderByDesc('count')->orderBy('id')->limit(24)->get()
            ->map(fn ($c) => [...(array) $c, 'count' => (int) $c->count])
            ->all();
    }

    /**
     * Aggregate occasion facets and total count in a single query using PostgreSQL FILTER clauses.
     *
     * @param  array<string, mixed>  $base
     * @return array{0: int, 1: list<array{id: string, count: int}>}
     */
    private function computeOccasionFacets(array $base): array
    {
        $occasionBase = $this->applyFilters($this->publishedBaseQuery(), Arr::except($base, 'occasions'))
            ->reorder()
            ->select('products.id', 'products.occasions');

        $selects = [];
        $bindings = [];
        foreach (Occasion::cases() as $occasion) {
            $selects[] = 'COUNT(*) FILTER (WHERE occasion_scope.occasions @> ?::jsonb) AS "occ_'.$occasion->value.'"';
            $bindings[] = json_encode([$occasion->value], JSON_THROW_ON_ERROR);
        }

        if (empty($base['occasions'])) {
            $selects[] = 'COUNT(*) AS total';
        } else {
            $totalClauses = [];
            foreach ($base['occasions'] as $occ) {
                $totalClauses[] = 'occasion_scope.occasions @> ?::jsonb';
                $bindings[] = json_encode([$occ], JSON_THROW_ON_ERROR);
            }
            $selects[] = 'COUNT(*) FILTER (WHERE '.implode(' OR ', $totalClauses).') AS total';
        }

        $occasionCounts = DB::query()->fromSub($occasionBase->toBase(), 'occasion_scope')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $occasions = collect(Occasion::cases())->map(fn (Occasion $o) => [
            'id' => $o->value,
            'count' => (int) ($occasionCounts->{'occ_'.$o->value} ?? 0),
        ])->values()->all();

        return [(int) ($occasionCounts->total ?? 0), $occasions];
    }

    /** @param array<string, mixed> $base */
    private function computePriceRanges(array $base): array
    {
        $scope = $this->applyFilters($this->publishedBaseQuery(), $base)
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
     * @return list<int>
     */
    private function discountedProductIds(?Customer $customer, int $limit): array
    {
        $discounted = $this->publishedBaseQuery()->reorder();
        $this->applyDiscountedFilter($discounted, $customer);

        return $discounted
            ->orderByDesc('products.sales_count')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->pluck('products.id')
            ->all();
    }
}
