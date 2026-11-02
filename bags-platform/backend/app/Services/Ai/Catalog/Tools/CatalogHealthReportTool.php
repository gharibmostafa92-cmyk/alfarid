<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\CategoryHierarchy;
use App\Services\CostAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CatalogHealthReportTool implements AgentTool
{
    public function __construct(private CatalogPresenter $presenter) {}

    public function name(): string
    {
        return 'catalog_health_report';
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
            'description' => 'تقرير صحة الكتالوج الشامل: مسودات قديمة، مسودات جاهزة للنشر، منتجات من غير صور، متغيرات ناقصها أسعار، أسعار شاذة، وأقسام فاضية أو معطلة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'draft_older_than_days' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $days = max(1, min(365, (int) ($args['draft_older_than_days'] ?? 14)));
        $canViewCost = CostAccess::canView($actor);

        return DB::transaction(function () use ($actor, $days, $canViewCost): ToolOutcome {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL statement_timeout = '3000ms'");
            }

            // Compute active category IDs (whose full path to root is active)
            $allCategories = Category::query()->get(['id', 'parent_id', 'name_ar', 'slug', 'is_active']);
            $catMap = $allCategories->keyBy('id');
            $activePathCatIds = [];
            $activeUnderInactiveParent = [];

            foreach ($allCategories as $cat) {
                $curr = $cat;
                $seen = [];
                $pathOk = true;
                while ($curr) {
                    if (! $curr->is_active || isset($seen[$curr->id]) || count($seen) >= 3) {
                        $pathOk = false;
                        break;
                    }
                    $seen[$curr->id] = true;
                    if ($curr->parent_id === null) {
                        break;
                    }
                    $curr = $catMap->get($curr->parent_id);
                    if (! $curr) {
                        $pathOk = false;
                        break;
                    }
                }
                if ($pathOk) {
                    $activePathCatIds[] = $cat->id;
                } elseif ($cat->is_active && $cat->parent_id !== null) {
                    $activeUnderInactiveParent[] = $cat;
                }
            }

            // 1. Stale drafts
            $staleDraftsQuery = Product::query()
                ->where('status', ProductStatus::Draft)
                ->where('created_at', '<=', now()->subDays($days));
            $staleDraftsCount = (clone $staleDraftsQuery)->count();
            $staleDraftsExamples = (clone $staleDraftsQuery)
                ->orderBy('created_at')
                ->limit(5)
                ->get(['id', 'code', 'name_ar', 'created_at'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name_ar' => $this->presenter->untrusted($p->name_ar, 150),
                    'days_old' => (int) $p->created_at?->diffInDays(now()),
                    'deep_link' => '#/products/'.$p->id,
                ])->all();

            // Helper closure for products that have at least 1 image (on product or active variant)
            $hasImagesConstraint = function (Builder $q): void {
                $q->where(function (Builder $w): void {
                    $w->whereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('media')
                            ->whereColumn('media.model_id', 'products.id')
                            ->where('media.model_type', Product::class)
                            ->where('media.collection_name', 'images');
                    })->orWhereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('media')
                            ->join('product_variants', function ($join): void {
                                $join->on('media.model_id', '=', 'product_variants.id')
                                    ->where('media.model_type', '=', ProductVariant::class);
                            })
                            ->whereColumn('product_variants.product_id', 'products.id')
                            ->whereNull('product_variants.deleted_at')
                            ->where('product_variants.is_active', true)
                            ->where('media.collection_name', 'images');
                    });
                });
            };

            // Helper closure for products missing required prices on any active variant
            $missingPricesOnProductConstraint = function (Builder $q): void {
                $q->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('product_variants')
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->whereNull('product_variants.deleted_at')
                        ->where('product_variants.is_active', true)
                        ->where(function ($w): void {
                            $w->where(function ($r): void {
                                $r->whereIn('products.sales_channel', [SalesChannel::Retail->value, SalesChannel::Both->value])
                                    ->where(fn ($p) => $p->whereNull('product_variants.retail_price')->orWhere('product_variants.retail_price', '<=', 0));
                            })->orWhere(function ($ws): void {
                                $ws->whereIn('products.sales_channel', [SalesChannel::Wholesale->value, SalesChannel::Both->value])
                                    ->where(fn ($p) => $p->whereNull('product_variants.wholesale_price')->orWhere('product_variants.wholesale_price', '<=', 0));
                            });
                        });
                });
            };

            // 2. Ready drafts (quick wins)
            $readyDraftsQuery = Product::query()
                ->where('status', ProductStatus::Draft)
                ->whereIn('category_id', $activePathCatIds ?: [-1])
                ->whereHas('variants', fn ($v) => $v->where('is_active', true))
                ->whereNot($missingPricesOnProductConstraint)
                ->tap($hasImagesConstraint);
            $readyDraftsCount = (clone $readyDraftsQuery)->count();
            $readyDraftsExamples = (clone $readyDraftsQuery)
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'code', 'name_ar'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name_ar' => $this->presenter->untrusted($p->name_ar, 150),
                    'deep_link' => '#/products/'.$p->id,
                ])->all();

            // 3. Products without images
            $missingImagesQuery = Product::query()->whereNot($hasImagesConstraint);
            $missingImagesCount = (clone $missingImagesQuery)->count();
            $missingImagesExamples = (clone $missingImagesQuery)
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'code', 'name_ar', 'status'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name_ar' => $this->presenter->untrusted($p->name_ar, 150),
                    'status' => $p->status->value,
                    'deep_link' => '#/products/'.$p->id,
                ])->all();

            // 4. Active variants missing required price according to sales_channel
            $missingPricesQuery = ProductVariant::query()
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereNull('products.deleted_at')
                ->where('product_variants.is_active', true)
                ->where(function ($w): void {
                    $w->where(function ($r): void {
                        $r->whereIn('products.sales_channel', [SalesChannel::Retail->value, SalesChannel::Both->value])
                            ->where(fn ($p) => $p->whereNull('product_variants.retail_price')->orWhere('product_variants.retail_price', '<=', 0));
                    })->orWhere(function ($ws): void {
                        $ws->whereIn('products.sales_channel', [SalesChannel::Wholesale->value, SalesChannel::Both->value])
                            ->where(fn ($p) => $p->whereNull('product_variants.wholesale_price')->orWhere('product_variants.wholesale_price', '<=', 0));
                    });
                })
                ->select('product_variants.*', 'products.code as product_code', 'products.name_ar as product_name_ar', 'products.sales_channel as product_sales_channel');
            $missingPricesCount = (clone $missingPricesQuery)->count();
            $missingPricesExamples = (clone $missingPricesQuery)
                ->orderBy('product_variants.id')
                ->limit(5)
                ->get()
                ->map(fn ($v) => [
                    'variant_id' => $v->id,
                    'sku' => $v->sku,
                    'product_id' => $v->product_id,
                    'product_code' => $v->product_code,
                    'product_name_ar' => $this->presenter->untrusted((string) $v->product_name_ar, 150),
                    'sales_channel' => $v->product_sales_channel,
                    'retail_price' => $v->retail_price !== null ? (float) $v->retail_price : null,
                    'wholesale_price' => $v->wholesale_price !== null ? (float) $v->wholesale_price : null,
                    'deep_link' => '#/products/'.$v->product_id,
                ])->all();

            // 5. Price anomalies: wholesale_price >= retail_price, and if canViewCost: retail_price <= cost_price
            $wholesaleGeRetailQuery = ProductVariant::query()
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereNull('products.deleted_at')
                ->where('product_variants.is_active', true)
                ->whereNotNull('product_variants.wholesale_price')
                ->whereNotNull('product_variants.retail_price')
                ->whereColumn('product_variants.wholesale_price', '>=', 'product_variants.retail_price')
                ->select('product_variants.*', 'products.code as product_code', 'products.name_ar as product_name_ar');
            $wholesaleGeRetailCount = (clone $wholesaleGeRetailQuery)->count();
            $wholesaleGeRetailExamples = (clone $wholesaleGeRetailQuery)
                ->orderBy('product_variants.id')
                ->limit(5)
                ->get()
                ->map(fn ($v) => [
                    'variant_id' => $v->id,
                    'sku' => $v->sku,
                    'product_id' => $v->product_id,
                    'product_code' => $v->product_code,
                    'anomaly' => 'wholesale_ge_retail',
                    'retail_price' => (float) $v->retail_price,
                    'wholesale_price' => (float) $v->wholesale_price,
                    'deep_link' => '#/products/'.$v->product_id,
                ])->all();

            $belowCostCount = 0;
            $belowCostExamples = [];
            if ($canViewCost) {
                $belowCostQuery = ProductVariant::query()
                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->whereNull('products.deleted_at')
                    ->where('product_variants.is_active', true)
                    ->whereNotNull('product_variants.cost_price')
                    ->where('product_variants.cost_price', '>', 0)
                    ->whereNotNull('product_variants.retail_price')
                    ->whereColumn('product_variants.retail_price', '<=', 'product_variants.cost_price')
                    ->select('product_variants.*', 'products.code as product_code', 'products.name_ar as product_name_ar');
                $belowCostCount = (clone $belowCostQuery)->count();
                $belowCostExamples = (clone $belowCostQuery)
                    ->orderBy('product_variants.id')
                    ->limit(5)
                    ->get()
                    ->map(fn ($v) => [
                        'variant_id' => $v->id,
                        'sku' => $v->sku,
                        'product_id' => $v->product_id,
                        'product_code' => $v->product_code,
                        'anomaly' => 'retail_le_cost',
                        'retail_price' => (float) $v->retail_price,
                        'cost_price' => (float) $v->cost_price,
                        'deep_link' => '#/products/'.$v->product_id,
                    ])->all();
            }

            $totalAnomaliesCount = $wholesaleGeRetailCount + $belowCostCount;
            $anomalyExamples = array_slice(array_merge($wholesaleGeRetailExamples, $belowCostExamples), 0, 5);

            // 6. Empty categories (no products and no children)
            $emptyCategoriesQuery = Category::query()
                ->whereDoesntHave('products')
                ->whereDoesntHave('children');
            $emptyCategoriesCount = (clone $emptyCategoriesQuery)->count();
            $emptyCategoriesExamples = (clone $emptyCategoriesQuery)
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'name_ar', 'slug', 'is_active'])
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name_ar' => $this->presenter->untrusted($c->name_ar, 150),
                    'slug' => $c->slug,
                    'is_active' => (bool) $c->is_active,
                    'deep_link' => '#/categories?focus='.$c->id,
                ])->all();

            // 7. Active categories under inactive parent
            $activeUnderInactiveCount = count($activeUnderInactiveParent);
            $activeUnderInactiveExamples = collect($activeUnderInactiveParent)
                ->take(5)
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'parent_id' => $c->parent_id,
                    'name_ar' => $this->presenter->untrusted($c->name_ar, 150),
                    'slug' => $c->slug,
                    'deep_link' => '#/categories?focus='.$c->id,
                ])->values()->all();

            // 8. Variants below reorder_level (if stock_levels exists)
            $lowStockCount = 0;
            $lowStockExamples = [];
            if (Schema::hasTable('stock_levels')) {
                $lowStockQuery = StockLevel::query()
                    ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->whereNull('product_variants.deleted_at')
                    ->whereNull('products.deleted_at')
                    ->where('product_variants.is_active', true)
                    ->where('stock_levels.reorder_level', '>', 0)
                    ->whereRaw('(stock_levels.on_hand - stock_levels.reserved - stock_levels.damaged) < stock_levels.reorder_level')
                    ->select(
                        'stock_levels.*',
                        'product_variants.sku',
                        'product_variants.product_id',
                        'products.code as product_code',
                        'products.name_ar as product_name_ar'
                    );
                $lowStockCount = (clone $lowStockQuery)->count();
                $lowStockExamples = (clone $lowStockQuery)
                    ->orderBy('stock_levels.id')
                    ->limit(5)
                    ->get()
                    ->map(fn ($s) => [
                        'variant_id' => (int) $s->product_variant_id,
                        'sku' => $s->sku,
                        'product_id' => (int) $s->product_id,
                        'product_code' => $s->product_code,
                        'available' => round((float) $s->on_hand - (float) $s->reserved - (float) $s->damaged, 2),
                        'reorder_level' => (float) $s->reorder_level,
                        'deep_link' => '#/products/'.$s->product_id,
                    ])->all();
            }

            $sections = [
                'stale_drafts' => [
                    'count' => $staleDraftsCount,
                    'older_than_days' => $days,
                    'examples' => $staleDraftsExamples,
                ],
                'ready_drafts' => [
                    'count' => $readyDraftsCount,
                    'examples' => $readyDraftsExamples,
                ],
                'missing_images' => [
                    'count' => $missingImagesCount,
                    'examples' => $missingImagesExamples,
                ],
                'missing_prices' => [
                    'count' => $missingPricesCount,
                    'examples' => $missingPricesExamples,
                ],
                'price_anomalies' => [
                    'count' => $totalAnomaliesCount,
                    'wholesale_ge_retail_count' => $wholesaleGeRetailCount,
                    ...($canViewCost ? ['below_cost_count' => $belowCostCount] : []),
                    'examples' => $anomalyExamples,
                ],
                'empty_categories' => [
                    'count' => $emptyCategoriesCount,
                    'examples' => $emptyCategoriesExamples,
                ],
                'active_categories_under_inactive_parent' => [
                    'count' => $activeUnderInactiveCount,
                    'examples' => $activeUnderInactiveExamples,
                ],
                'low_stock_variants' => [
                    'count' => $lowStockCount,
                    'examples' => $lowStockExamples,
                ],
            ];

            $checks = [
                [
                    'key' => 'ready_drafts',
                    'ok' => $readyDraftsCount === 0,
                    'severity' => 'warning',
                    'label' => "مسودات جاهزة للنشر ({$readyDraftsCount})",
                    'message' => $readyDraftsCount > 0 ? "فيه {$readyDraftsCount} مسودة مستوفية كل الشروط وجاهزة للنشر." : 'مفيش مسودات معلقة جاهزة للنشر.',
                ],
                [
                    'key' => 'stale_drafts',
                    'ok' => $staleDraftsCount === 0,
                    'severity' => 'warning',
                    'label' => "مسودات أقدم من {$days} يوم ({$staleDraftsCount})",
                    'message' => $staleDraftsCount > 0 ? "فيه {$staleDraftsCount} مسودة بقالها أكتر من {$days} يوم." : 'مفيش مسودات قديمة متروكة.',
                ],
                [
                    'key' => 'missing_images',
                    'ok' => $missingImagesCount === 0,
                    'severity' => 'blocker',
                    'label' => "منتجات من غير صور ({$missingImagesCount})",
                    'message' => $missingImagesCount > 0 ? "فيه {$missingImagesCount} منتج من غير أي صورة." : 'كل المنتجات ليها صور.',
                ],
                [
                    'key' => 'missing_prices',
                    'ok' => $missingPricesCount === 0,
                    'severity' => 'blocker',
                    'label' => "متغيرات نشطة ناقصها أسعار ({$missingPricesCount})",
                    'message' => $missingPricesCount > 0 ? "فيه {$missingPricesCount} متغير نشط ناقصه السعر المطلوب." : 'كل المتغيرات النشطة مسعرة.',
                ],
                [
                    'key' => 'price_anomalies',
                    'ok' => $totalAnomaliesCount === 0,
                    'severity' => 'blocker',
                    'label' => "أسعار شاذة ({$totalAnomaliesCount})",
                    'message' => $totalAnomaliesCount > 0 ? "فيه {$totalAnomaliesCount} متغير بأسعار شاذة (الجملة >= القطاعي".($canViewCost ? ' أو القطاعي <= التكلفة' : '').').' : 'مفيش أسعار شاذة.',
                ],
                [
                    'key' => 'empty_categories',
                    'ok' => $emptyCategoriesCount === 0,
                    'severity' => 'warning',
                    'label' => "أقسام فاضية ({$emptyCategoriesCount})",
                    'message' => $emptyCategoriesCount > 0 ? "فيه {$emptyCategoriesCount} قسم من غير منتجات ولا أقسام فرعية." : 'مفيش أقسام فاضية.',
                ],
                [
                    'key' => 'active_categories_under_inactive_parent',
                    'ok' => $activeUnderInactiveCount === 0,
                    'severity' => 'blocker',
                    'label' => "أقسام نشطة تحت أب معطل ({$activeUnderInactiveCount})",
                    'message' => $activeUnderInactiveCount > 0 ? "فيه {$activeUnderInactiveCount} قسم نشط تحت قسم أب معطل." : 'كل مسارات الأقسام النشطة سليمة.',
                ],
                [
                    'key' => 'low_stock_variants',
                    'ok' => $lowStockCount === 0,
                    'severity' => 'warning',
                    'label' => "متغيرات تحت حد الطلب ({$lowStockCount})",
                    'message' => $lowStockCount > 0 ? "فيه {$lowStockCount} رصيد متغير تحت حد إعادة الطلب." : 'المخزون فوق حد إعادة الطلب.',
                ],
            ];

            $nextActions = [];
            if ($readyDraftsCount > 0) {
                $nextActions[] = [
                    'label' => 'خطط لإصلاح الكتالوج',
                    'prompt' => 'اعمل خطة إصلاح للكتالوج بناءً على التقرير',
                ];
            }
            if ($missingImagesCount > 0) {
                $nextActions[] = [
                    'label' => 'اعرض المنتجات اللي من غير صور',
                    'prompt' => 'وريني المنتجات اللي من غير صور',
                ];
            }
            if ($missingPricesCount > 0) {
                $nextActions[] = [
                    'label' => 'اعرض المنتجات اللي ناقصها أسعار',
                    'prompt' => 'وريني المنتجات اللي ناقصها أسعار',
                ];
            }

            $hasIssues = collect($checks)->contains(fn ($c) => ! $c['ok']);

            return $this->presenter->present(
                $actor,
                $sections,
                [
                    'type' => 'checklist',
                    'title' => 'تقرير صحة الكتالوج',
                    'verdict' => $hasIssues ? 'warning' : 'ready',
                    'checks' => $checks,
                    'next_actions' => $nextActions,
                ],
            );
        });
    }
}
