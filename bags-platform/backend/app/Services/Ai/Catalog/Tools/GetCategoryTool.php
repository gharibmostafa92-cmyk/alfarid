<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Catalog\ProductStatus;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\CategoryHierarchy;

final class GetCategoryTool implements AgentTool
{
    public function __construct(private CatalogPresenter $presenter) {}

    public function name(): string
    {
        return 'get_category';
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
        return ['categories.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تفاصيل قسم واحد: الأب، الأبناء، عدد المنتجات النشطة والمحذوفة، ومسار النشاط.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category_id'],
                'properties' => (object) [
                    'category_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $category = Category::query()
            ->with(['parent', 'children'])
            ->withCount([
                'products as active_products_count' => fn ($q) => $q->where('status', ProductStatus::Published),
                'products as total_products_count',
                'products as soft_deleted_products_count' => fn ($q) => $q->onlyTrashed(),
            ])
            ->find((int) ($args['category_id'] ?? 0));

        if (! $category) {
            return ToolOutcome::denied('القسم مش موجود.', auditable: false);
        }

        $activePath = CategoryHierarchy::activePath($category);
        $data = [
            'category' => array_merge($this->presenter->category($category, $actor), [
                'parent' => $category->parent ? [
                    'id' => $category->parent->id,
                    'name_ar' => $this->presenter->untrusted($category->parent->name_ar, 150),
                    'slug' => $category->parent->slug,
                    'is_active' => (bool) $category->parent->is_active,
                ] : null,
                'children' => $category->children->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name_ar' => $this->presenter->untrusted($c->name_ar, 150),
                    'slug' => $c->slug,
                    'is_active' => (bool) $c->is_active,
                ])->values()->all(),
                'active_products_count' => (int) $category->active_products_count,
                'total_products_count' => (int) $category->total_products_count,
                'soft_deleted_products_count' => (int) $category->soft_deleted_products_count,
                'active_path' => $activePath,
            ]),
        ];

        $rows = [
            ['الاسم بالعربي', $category->name_ar],
            ['الاسم بالإنجليزي', $category->name_en],
            ['الـ Slug', $category->slug],
            ['القسم الأب', $category->parent?->name_ar ?? 'رئيسي'],
            ['الحالة', $category->is_active ? 'نشط' : 'معطّل'],
            ['مسار النشاط', $activePath ? 'نشط بالكامل' : 'مسار غير نشط'],
            ['المنتجات المنشورة', (string) $category->active_products_count],
            ['إجمالي المنتجات', (string) $category->total_products_count],
            ['المنتجات المحذوفة (Soft)', (string) $category->soft_deleted_products_count],
            ['عدد الأقسام الفرعية', (string) $category->children->count()],
        ];

        return $this->presenter->present(
            $actor,
            $data,
            ['type' => 'table', 'columns' => ['الحقل', 'القيمة'], 'rows' => $rows, 'deep_link' => '#/categories?focus='.$category->id]
        );
    }
}
