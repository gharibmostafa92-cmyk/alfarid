<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Builder;
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
            ->with([
                'brand', 'category', 'media',
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
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
            $brandIds = collect((array) $filters['brand'])
                ->map(fn ($v) => $this->resolveBrand($v)?->id)
                ->filter()
                ->all();
            $query->whereIn('products.brand_id', $brandIds ?: [0]);
        }

        if (! empty($filters['color'])) {
            $color = $filters['color'];
            $query->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->where(fn ($inner) => $inner
                    ->whereRaw('LOWER(color_name_en) = LOWER(?)', [$color])
                    ->orWhere('color_name_ar', $color)));
        }

        foreach (['price_min' => '>=', 'price_max' => '<='] as $key => $op) {
            if (isset($filters[$key]) && $filters[$key] !== null && $filters[$key] !== '') {
                $query->whereHas('variants', fn ($q) => $q->where('is_active', true)
                    ->where('base_price', $op, $filters[$key]));
            }
        }

        if (! empty($filters['in_stock'])) {
            $query->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('stock_levels')
                ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
                ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
                ->where('branches.is_active', true)
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('stock_levels.available', '>', 0));
        }

        return $this->applySort($query, $filters['sort'] ?? 'newest');
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'best_selling' => $query->orderByDesc('products.sales_count')->orderByDesc('products.id'),
            'price_asc' => $query->orderBy(
                ProductVariant::query()->selectRaw('MIN(base_price)')->whereColumn('product_id', 'products.id')->where('is_active', true)
            )->orderBy('products.id'),
            'price_desc' => $query->orderByDesc(
                ProductVariant::query()->selectRaw('MIN(base_price)')->whereColumn('product_id', 'products.id')->where('is_active', true)
            )->orderBy('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    private function resolveCategory(int|string $value): ?Category
    {
        return Category::query()
            ->when(is_numeric($value), fn ($q) => $q->where('id', (int) $value), fn ($q) => $q->where('slug', $value))
            ->first();
    }

    private function resolveBrand(int|string $value): ?Brand
    {
        return Brand::query()
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

        foreach ($variants->sortBy(fn (ProductVariant $v) => (float) $v->base_price) as $variant) {
            try {
                $resolved = $this->pricing->resolve($variant, $customer, 1);
            } catch (BusinessException) {
                continue;
            }

            $price = (float) $resolved['unit_price'];
            $compareAt = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;
            $basis = ($compareAt !== null && $compareAt > $price) ? $compareAt : (float) $resolved['base_price'];

            return [
                'price' => $price,
                'base_price' => (float) $resolved['base_price'],
                'compare_at' => $compareAt,
                'discount_percentage' => $basis > $price ? (int) round((1 - $price / $basis) * 100) : null,
                'variant_id' => $variant->id,
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------ الرئيسية والتشابه

    /** معرّفات أقسام الصفحة الرئيسية (تُخزَّن مؤقتًا في المتحكم — الأسعار لا تُخزَّن أبدًا). */
    public function homeSectionIds(): array
    {
        $bannerIds = \App\Models\Banner::query()
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
                    ->whereNotNull('compare_at_price')
                    ->whereColumn('compare_at_price', '>', 'base_price'))
                ->orWhereHas('variants', fn ($v) => $v->where('is_active', true)
                    ->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('price_list_items')
                        ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
                        ->whereColumn('price_list_items.product_variant_id', 'product_variants.id')
                        ->where('price_lists.is_active', true)
                        ->where(fn ($w) => $w->whereNull('price_lists.starts_at')->orWhere('price_lists.starts_at', '<=', now()))
                        ->where(fn ($w) => $w->whereNull('price_lists.ends_at')->orWhere('price_lists.ends_at', '>=', now()))
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function similar(Product $product): \Illuminate\Database\Eloquent\Collection
    {
        return $this->publishedQuery()
            ->where('products.id', '!=', $product->id)
            ->where(fn ($q) => $q->where('products.category_id', $product->category_id)
                ->orWhere('products.brand_id', $product->brand_id))
            ->reorder()
            ->orderByRaw('CASE WHEN products.category_id = ? THEN 0 ELSE 1 END', [$product->category_id])
            ->orderByDesc('store_available')
            ->orderByDesc('products.sales_count')
            ->orderByDesc('products.id')
            ->limit(8)
            ->get();
    }
}
