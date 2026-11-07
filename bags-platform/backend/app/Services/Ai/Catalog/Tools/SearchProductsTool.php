<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\SearchTerm;

final class SearchProductsTool implements AgentTool
{
    public function __construct(private CatalogPresenter $presenter) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['products.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'بحث وفلترة المنتجات بالحالة والقسم وقناة البيع والمميز والنواقص (صور/أسعار/باركود).',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'query' => ['type' => 'STRING'],
                    'status' => ['type' => 'STRING', 'enum' => array_column(ProductStatus::cases(), 'value')],
                    'category_id' => ['type' => 'INTEGER'],
                    'include_children' => ['type' => 'BOOLEAN'],
                    'sales_channel' => ['type' => 'STRING', 'enum' => array_column(SalesChannel::cases(), 'value')],
                    'is_featured' => ['type' => 'BOOLEAN'],
                    'missing' => ['type' => 'STRING', 'enum' => ['images', 'prices', 'barcode']],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $limit = min(20, max(1, (int) ($args['limit'] ?? 20)));
        $rawQuery = trim((string) ($args['query'] ?? ''));

        $q = Product::query()
            ->with(['category:id,name_ar,name_en'])
            ->withCount('variants')
            ->withMin(['variants as min_retail_price' => fn ($v) => $v->where('is_active', true)], 'retail_price')
            ->withMax(['variants as max_retail_price' => fn ($v) => $v->where('is_active', true)], 'retail_price');

        if ($rawQuery !== '') {
            $terms = EntityResolver::expandCatalogSearchTerms($rawQuery);
            $q->where(function ($w) use ($terms, $rawQuery): void {
                $w->whereRaw('LOWER(code) = LOWER(?)', [$rawQuery])
                    ->orWhereIn('products.id', ProductVariant::query()->select('product_id')
                        ->where(fn ($v) => $v->whereRaw('LOWER(sku) = LOWER(?)', [$rawQuery])->orWhereRaw('LOWER(barcode) = LOWER(?)', [$rawQuery])));
                foreach ($terms as $term) {
                    $pattern = SearchTerm::pattern($term);
                    $w->orWhereLike('name_ar', $pattern, caseSensitive: false)
                        ->orWhereLike('name_en', $pattern, caseSensitive: false);
                }
            });
        }

        if (! empty($args['category_id'])) {
            $cat = Category::find((int) $args['category_id']);
            if ($cat) {
                $includeChildren = ! array_key_exists('include_children', $args) || (bool) $args['include_children'];
                $ids = $includeChildren ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
                $q->whereIn('category_id', $ids);
            } else {
                $q->where('category_id', (int) $args['category_id']);
            }
        }

        $q->when($args['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when($args['sales_channel'] ?? null, fn ($x, $v) => $x->where('sales_channel', $v))
            ->when(array_key_exists('is_featured', $args), fn ($x) => $x->where('is_featured', (bool) $args['is_featured']));

        if (($args['missing'] ?? null) === 'images') {
            $q->whereDoesntHave('media', fn ($m) => $m->where('collection_name', 'images'))
                ->whereDoesntHave('variants', fn ($v) => $v->where('is_active', true)->whereHas('media', fn ($m) => $m->where('collection_name', 'images')));
        } elseif (($args['missing'] ?? null) === 'prices') {
            $q->where(function ($outer): void {
                $outer->where(fn ($r) => $r->whereIn('sales_channel', ['retail', 'both'])
                    ->whereHas('variants', fn ($v) => $v->where('is_active', true)->where(fn ($w) => $w->whereNull('retail_price')->orWhere('retail_price', '<=', 0))))
                    ->orWhere(fn ($r) => $r->whereIn('sales_channel', ['wholesale', 'both'])
                        ->whereHas('variants', fn ($v) => $v->where('is_active', true)->where(fn ($w) => $w->whereNull('wholesale_price')->orWhere('wholesale_price', '<=', 0))));
            });
        } elseif (($args['missing'] ?? null) === 'barcode') {
            $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->where(fn ($w) => $w->whereNull('barcode')->orWhere('barcode', '')));
        }

        $products = $q->orderByDesc('id')->limit($limit)->get();

        // Omit lengthy product descriptions from search results to conserve context window.
        $items = $products->map(fn (Product $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name_ar' => $this->presenter->untrusted($p->name_ar, 255),
            'name_en' => $this->presenter->untrusted($p->name_en, 255),
            'status' => $p->status->value,
            'sales_channel' => $p->sales_channel->value,
            'category_id' => $p->category_id,
            'category_name' => $p->category ? $this->presenter->untrusted($p->category->name_ar, 150) : null,
            'variants_count' => (int) $p->variants_count,
            'min_retail_price' => $p->min_retail_price !== null ? (float) $p->min_retail_price : null,
            'max_retail_price' => $p->max_retail_price !== null ? (float) $p->max_retail_price : null,
            'is_featured' => (bool) $p->is_featured,
            'deep_link' => '#/products/'.$p->id,
        ])->values()->all();

        $rows = $products->map(fn (Product $p) => [
            $p->code,
            $p->name_ar,
            $p->category?->name_ar ?? '—',
            $p->status->value,
            (string) $p->variants_count,
            $p->min_retail_price !== null ? (string) ((float) $p->min_retail_price) : '—',
        ])->values()->all();

        return $this->presenter->present(
            $actor,
            ['products' => $items, 'count' => count($items)],
            ['type' => 'table', 'columns' => ['الكود', 'الاسم', 'القسم', 'الحالة', 'المتغيرات', 'أقل قطاعي'], 'rows' => $rows]
        );
    }
}
