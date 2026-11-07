<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\CategoryHierarchy;
use App\Services\Catalog\ProductPublishabilityChecker;
use App\Services\CostAccess;
use App\Services\SettingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CatalogPresenter
{
    public function __construct(
        private SettingService $settings,
        private ProductPublishabilityChecker $publishability,
    ) {}

    /** @return array{untrusted_text: string} */
    public function untrusted(?string $value, int $max = 300): array
    {
        return ToolOutcome::untrusted($value, $max);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $uiBlock
     */
    public function present(User $actor, array $data, ?array $uiBlock = null, ?string $message = null): ToolOutcome
    {
        $canViewCost = CostAccess::canView($actor);
        if (! $canViewCost) {
            $data = $this->stripCost($data);
            if ($uiBlock !== null) {
                $uiBlock = $this->stripCost($uiBlock);
            }
        }

        return ToolOutcome::present($data, $uiBlock, $message, (int) $this->settings->get('ai_tool_result_max_chars', 16000));
    }

    /** @return array<string,mixed> */
    public function stripCost(array $payload): array
    {
        return CostAccess::strip($payload);
    }

    /** @return array<string,mixed> */
    public function category(Category $category, ?User $actor = null): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name_ar' => $this->untrusted($category->name_ar, 150),
            'name_en' => $this->untrusted($category->name_en, 150),
            'slug' => $category->slug,
            'description_ar' => $category->description_ar !== null ? $this->untrusted($category->description_ar, 300) : null,
            'description_en' => $category->description_en !== null ? $this->untrusted($category->description_en, 300) : null,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'active_path' => CategoryHierarchy::activePath($category),
            'deep_link' => '#/categories?focus='.$category->id,
        ];
    }

    /** @return array<string,mixed> */
    public function product(Product $product, User $actor): array
    {
        $product->loadMissing(['category', 'variants', 'media']);
        $canViewCost = CostAccess::canView($actor);
        $variants = $product->variants->map(fn (ProductVariant $v) => $this->variant($v, $actor))->values()->all();
        $activeVariants = $product->variants->where('is_active', true);
        $retailPrices = $activeVariants->pluck('retail_price')->filter(fn ($p) => $p !== null && $p > 0)->map(fn ($p) => (float) $p);
        $wholesalePrices = $activeVariants->pluck('wholesale_price')->filter(fn ($p) => $p !== null && $p > 0)->map(fn ($p) => (float) $p);

        $imagesCount = $product->media->where('collection_name', 'images')->count()
            + $product->variants->sum(fn (ProductVariant $v) => $v->relationLoaded('media') ? $v->media->where('collection_name', 'images')->count() : $v->media()->where('collection_name', 'images')->count());

        $checks = $this->publishability->evaluate($product);
        $ready = ! collect($checks)->contains(fn ($c) => ! $c['ok']);

        $out = [
            'id' => $product->id,
            'code' => $product->code,
            'category_id' => $product->category_id,
            'category_name' => $product->category ? $this->untrusted($product->category->name_ar, 150) : null,
            'name_ar' => $this->untrusted($product->name_ar, 255),
            'name_en' => $this->untrusted($product->name_en, 255),
            'slug' => $product->slug,
            'description_ar' => $product->description_ar !== null ? $this->untrusted($product->description_ar, 300) : null,
            'description_en' => $product->description_en !== null ? $this->untrusted($product->description_en, 300) : null,
            'material_ar' => $product->material_ar !== null ? $this->untrusted($product->material_ar, 150) : null,
            'material_en' => $product->material_en !== null ? $this->untrusted($product->material_en, 150) : null,
            'status' => $product->status instanceof ProductStatus ? $product->status->value : (string) $product->status,
            'sales_channel' => $product->sales_channel instanceof \BackedEnum ? $product->sales_channel->value : (string) $product->sales_channel,
            'is_featured' => (bool) $product->is_featured,
            'occasions' => $product->occasions ?? [],
            'badge_ar' => $product->badge_ar,
            'badge_en' => $product->badge_en,
            'dimensions' => [
                'length_cm' => $product->length_cm !== null ? (float) $product->length_cm : null,
                'width_cm' => $product->width_cm !== null ? (float) $product->width_cm : null,
                'height_cm' => $product->height_cm !== null ? (float) $product->height_cm : null,
                'weight_kg' => $product->weight_kg !== null ? (float) $product->weight_kg : null,
            ],
            'price_range' => [
                'min_retail' => $retailPrices->isNotEmpty() ? $retailPrices->min() : null,
                'max_retail' => $retailPrices->isNotEmpty() ? $retailPrices->max() : null,
                'min_wholesale' => $wholesalePrices->isNotEmpty() ? $wholesalePrices->min() : null,
                'max_wholesale' => $wholesalePrices->isNotEmpty() ? $wholesalePrices->max() : null,
            ],
            'images_count' => $imagesCount,
            'variants_count' => count($variants),
            'variants' => $variants,
            'publishability' => [
                'ready' => $ready,
                'checks' => $checks,
            ],
            'deep_link' => '#/products/'.$product->id,
        ];

        return $canViewCost ? $out : $this->stripCost($out);
    }

    /** @return array<string,mixed> */
    public function productCardBlock(Product $product, User $actor): array
    {
        $product->loadMissing(['category', 'variants.media', 'media']);
        $activeVariants = $product->variants->where('is_active', true);
        $retailPrices = $activeVariants->pluck('retail_price')->filter(fn ($p) => $p !== null && $p > 0)->map(fn ($p) => (float) $p);
        $wholesalePrices = $activeVariants->pluck('wholesale_price')->filter(fn ($p) => $p !== null && $p > 0)->map(fn ($p) => (float) $p);
        $cover = $product->media->where('collection_name', 'images')->sortBy('order_column')->first()
            ?? $product->variants->flatMap(fn ($v) => $v->media->where('collection_name', 'images'))->sortBy('order_column')->first();
        $checks = $this->publishability->evaluate($product);

        return [
            'type' => 'product_card',
            'id' => $product->id,
            'code' => $product->code,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'status' => $product->status instanceof ProductStatus ? $product->status->value : (string) $product->status,
            'sales_channel' => $product->sales_channel instanceof \BackedEnum ? $product->sales_channel->value : (string) $product->sales_channel,
            'category_name' => $product->category?->name_ar,
            'thumb_url' => $cover ? $cover->getUrl() : null,
            'price_range' => [
                'min_retail' => $retailPrices->isNotEmpty() ? $retailPrices->min() : null,
                'max_retail' => $retailPrices->isNotEmpty() ? $retailPrices->max() : null,
                'min_wholesale' => $wholesalePrices->isNotEmpty() ? $wholesalePrices->min() : null,
                'max_wholesale' => $wholesalePrices->isNotEmpty() ? $wholesalePrices->max() : null,
            ],
            'variants' => $product->variants->map(fn (ProductVariant $v) => $this->variant($v, $actor))->values()->all(),
            'readiness' => [
                'ready' => ! collect($checks)->contains(fn ($c) => ! $c['ok']),
                'checks' => $checks,
            ],
            'deep_link' => '#/products/'.$product->id,
        ];
    }

    /** @return array<string,mixed> */
    public function variant(ProductVariant $variant, User $actor): array
    {
        $out = [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'color_name_ar' => $variant->color_name_ar,
            'color_name_en' => $variant->color_name_en,
            'color_hex' => $variant->color_hex,
            'size' => $variant->size,
            'capacity' => $variant->capacity,
            'retail_price' => $variant->retail_price !== null ? (float) $variant->retail_price : null,
            'wholesale_price' => $variant->wholesale_price !== null ? (float) $variant->wholesale_price : null,
            'compare_at_price' => $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null,
            'reorder_level' => $variant->reorder_level !== null ? (int) $variant->reorder_level : null,
            'weight_kg' => $variant->weight_kg !== null ? (float) $variant->weight_kg : null,
            'is_active' => (bool) $variant->is_active,
            'deep_link' => '#/products/'.$variant->product_id,
        ];

        if (CostAccess::canView($actor)) {
            $out['cost_price'] = $variant->cost_price !== null ? (float) $variant->cost_price : null;
        }

        return $out;
    }

    /**
     * Build category path breadcrumb string (e.g. "Women > Handbags").
     */
    public function categoryPathString(?Category $category): string
    {
        if (! $category) {
            return 'جذر (قسم رئيسي)';
        }
        $parts = [];
        $seen = [];
        $curr = $category;
        while ($curr && ! isset($seen[$curr->id]) && count($seen) < 5) {
            $seen[$curr->id] = true;
            array_unshift($parts, $curr->name_ar);
            $curr = $curr->parent_id ? Category::find($curr->parent_id) : null;
        }

        return implode(' › ', $parts);
    }

    /**
     * Build UI category tree array for category_tree / category_tree_diff.
     *
     * @param  array<int, array<string,mixed>>  $overrides
     * @return list<array<string,mixed>>
     */
    public function categoryTreeSnapshot(array $overrides = []): array
    {
        $all = Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($all as $cat) {
            $row = [
                'id' => (int) $cat->id,
                'parent_id' => $cat->parent_id !== null ? (int) $cat->parent_id : null,
                'name' => $cat->name_ar,
                'slug' => $cat->slug,
                'active' => (bool) $cat->is_active,
                'sort_order' => (int) $cat->sort_order,
                'products_count' => (int) ($cat->products_count ?? 0),
                'deep_link' => '#/categories?focus='.$cat->id,
            ];
            if (isset($overrides[$cat->id])) {
                $row = array_replace($row, $overrides[$cat->id]);
            }
            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: ($a['id'] <=> $b['id']));

        $grouped = [];
        foreach ($rows as $r) {
            $key = $r['parent_id'] ?? 0;
            $grouped[$key][] = $r;
        }

        $build = function (int $parentKey) use (&$build, $grouped): array {
            $list = $grouped[$parentKey] ?? [];
            $out = [];
            foreach ($list as $item) {
                $item['children'] = $build((int) $item['id']);
                $out[] = $item;
            }

            return $out;
        };

        return $build(0);
    }
}
