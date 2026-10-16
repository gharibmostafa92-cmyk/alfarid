<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Exceptions\BusinessException;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * كتالوج المتجر للقراءة: استعلامات التصفح والفلترة وحساب المتاح أونلاين.
 * المتاح أونلاين = مجموع available (on_hand − reserved − damaged) في الفروع
 * النشطة فقط، بحساب SQL مجمّع (بلا تحميل صفوف في الذاكرة).
 * «منتج نشط» في المتجر = منشور (published).
 */
class StoreCatalogService
{
    public function __construct(private readonly PricingService $pricing) {}

    // ------------------------------------------------------------------ متاح

    /** إرفاق store_available بأي استعلام منتجات: مجموع متاح كل متغيراته في الفروع النشطة. */
    public function withStoreAvailability(Builder $query): Builder
    {
        $sub = DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->where('branches.is_active', true)
            ->where('product_variants.is_active', true)
            ->whereNull('product_variants.deleted_at')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->selectRaw('COALESCE(SUM(stock_levels.available), 0)');

        return $query->select('products.*')->selectSub($sub, 'store_available');
    }

    /**
     * خريطة متاح لمتغيرات بعينها في استعلام واحد مجمّع.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    public function availabilityMap(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return StockLevel::query()
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->where('branches.is_active', true)
            ->whereIn('stock_levels.product_variant_id', $variantIds)
            ->groupBy('stock_levels.product_variant_id')
            ->selectRaw('stock_levels.product_variant_id, COALESCE(SUM(stock_levels.available), 0) as total')
            ->pluck('total', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    // ------------------------------------------------------------------ أساس

    /** استعلام المنتجات المنشورة بعلاقات البطاقة والمتاح المجمّع. */
    public function publishedQuery(): Builder
    {
        $query = Product::query()
            ->where('status', ProductStatus::Published)
            ->whereIn('sales_channel', [SalesChannel::Retail, SalesChannel::Both])
            ->whereHas('category', fn ($q) => $q->where('is_active', true)
                ->whereDoesntHave('parent', fn ($parent) => $parent->where('is_active', false)
                    ->orWhereHas('parent', fn ($ancestor) => $ancestor->where('is_active', false))))
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhereHas('brand', fn ($brand) => $brand->where('is_active', true)))
            ->with([
                'brand', 'category', 'media',
                'variants' => fn ($q) => $this->pricing->withStorePrice($q->getQuery(), $this->customer())
                    ->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
            ]);

        return $this->withStoreAvailability($query);
    }

    // ------------------------------------------------------------------ فلاتر

    /**
     * تطبيق فلاتر قائمة المنتجات (ProductFilterRequest) على الاستعلام.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['category'])) {
            $category = $this->resolveCategory($filters['category']);
            if ($category !== null) {
                $query->whereIn('products.category_id', [$category->id, ...$category->descendantIds()]);
            } else {
                $query->whereRaw('1 = 0'); // قسم غير موجود = نتيجة فارغة لا خطأ
            }
        }

        if (! empty($filters['brand'])) {
            $values = (array) $filters['brand'];
            $numericIds = array_map('intval', array_filter($values, fn ($v) => ctype_digit((string) $v)));
            $brandIds = Brand::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereIn('id', $numericIds)->orWhereIn('slug', $values))
                ->pluck('id')->all();
            $query->whereIn('products.brand_id', $brandIds ?: [0]);
        }

        if (! empty($filters['color'])) {
            $color = $filters['color'];
            $query->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->where(fn ($inner) => $inner
                    ->whereRaw('LOWER(color_name_en) = LOWER(?)', [$color])
                    ->orWhere('color_name_ar', $color)));
        }

        if (isset($filters['price_min']) || isset($filters['price_max'])) {
            if (isset($filters['price_min'])) {
                $query->where($this->minimumPriceQuery(), '>=', $filters['price_min']);
            }
            if (isset($filters['price_max'])) {
                $query->where($this->minimumPriceQuery(), '<=', $filters['price_max']);
            }
        }

        if (! empty($filters['in_stock'])) {
            $query->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('stock_levels')
                ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
                ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
                ->where('branches.is_active', true)
                ->where('product_variants.is_active', true)
                ->whereNull('product_variants.deleted_at')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('stock_levels.available', '>', 0));
        }

        return $this->applySort($query, $filters['sort'] ?? 'newest');
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $prices = $this->minimumPriceQuery();

            return $query->orderByRaw('('.$prices->toSql().') '.($sort === 'price_asc' ? 'ASC' : 'DESC').' NULLS LAST', $prices->getBindings())
                ->orderBy('products.id');
        }

        return match ($sort) {
            'best_selling' => $query->orderByDesc('products.sales_count')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    private function resolveCategory(int|string $value): ?Category
    {
        return Category::query()
            ->where('is_active', true)
            ->when(is_numeric($value), fn ($q) => $q->where('id', (int) $value), fn ($q) => $q->where('slug', $value))
            ->first();
    }

    // ------------------------------------------------------------------ أسعار العرض

    /**
     * سعر عرض بطاقة المنتج: أرخص متغير نشط قابل للتسعير عبر PricingService
     * (متغير بلا سعر ولا قائمة يُتخطى بدل كسر القائمة كلها).
     *
     * @return array{price: float, base_price: float, compare_at: float|null, discount_percentage: int|null, variant_id: int}|null
     */
    public function displayPrice(Product $product, ?Customer $customer): ?array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->where('is_active', true)->orderBy('sort_order')->get();

        $best = null;
        foreach ($variants as $variant) {
            try {
                $resolved = $this->variantPrice($variant, $customer);
            } catch (BusinessException) {
                continue;
            }

            $price = (float) $resolved['unit_price'];
            $compareAt = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;
            $basis = max((float) $compareAt, (float) $resolved['base_price']);

            $display = [
                'price' => $price,
                'base_price' => (float) $resolved['base_price'],
                'compare_at' => $basis > $price ? $basis : null,
                'discount_percentage' => $basis > $price ? (int) round((1 - $price / $basis) * 100) : null,
                'variant_id' => $variant->id,
            ];
            if ($best === null || $price < $best['price']) {
                $best = $display;
            }
        }

        return $best;
    }

    public function customer(): ?Customer
    {
        $user = auth('sanctum')->user();

        return $user instanceof Customer && $user->status === CustomerStatus::Approved ? $user : null;
    }

    public function variantPrice(ProductVariant $variant, ?Customer $customer): array
    {
        if (! array_key_exists('store_price', $variant->getAttributes())) {
            return $this->pricing->resolve($variant, $customer, 1);
        }
        if ($variant->store_price === null) {
            throw new BusinessException('الصنف غير مُسعَّر.');
        }

        return ['unit_price' => (float) $variant->store_price, 'base_price' => (float) $variant->base_price];
    }

    private function minimumPriceQuery(): \Illuminate\Database\Query\Builder
    {
        $variants = $this->pricing->withStorePrice(ProductVariant::query(), $this->customer())
            ->where('is_active', true);

        return DB::query()->fromSub($variants, 'priced_variants')
            ->selectRaw('MIN(priced_variants.store_price)')
            ->whereColumn('priced_variants.product_id', 'products.id');
    }

    // ------------------------------------------------------------------ الرئيسية والتشابه

    /** معرّفات أقسام الصفحة الرئيسية (تُخزَّن مؤقتًا في المتحكم — الأسعار لا تُخزَّن أبدًا). */
    public function homeSectionIds(): array
    {
        $bannerIds = Banner::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id')
            ->pluck('id')->all();

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
            'banner_ids' => $bannerIds,
            'latest_ids' => $latest,
            'best_seller_ids' => $best,
            'discounted_ids' => $discounted,
        ];
    }

    /**
     * منتجات مشابهة: نفس القسم الفرعي أولًا ثم نفس العلامة، والمتوفر أولًا.
     *
     * @return Collection<int, Product>
     */
    public function similar(Product $product): Collection
    {
        return $this->publishedQuery()
            ->where('products.id', '!=', $product->id)
            ->where(function ($q) use ($product): void {
                $q->where('products.category_id', $product->category_id);
                if ($product->brand_id !== null) {
                    $q->orWhere('products.brand_id', $product->brand_id);
                }
            })
            ->reorder()
            ->orderByRaw('CASE WHEN products.category_id = ? THEN 0 ELSE 1 END', [$product->category_id])
            ->orderByDesc('store_available')
            ->orderByDesc('products.sales_count')
            ->orderByDesc('products.id')
            ->limit(8)
            ->get();
    }
}
