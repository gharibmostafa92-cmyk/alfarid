<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\Category;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\CategoryService;

final class ListCategoriesTool implements AgentTool
{
    public function __construct(
        private CategoryService $categories,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_categories';
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
            'description' => 'عرض شجرة الأقسام كلها أو فرع منها، ومعاها عدد المنتجات وحالة كل قسم.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'parent_id' => ['type' => 'INTEGER'],
                    'only_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $onlyActive = (bool) ($args['only_active'] ?? false);
        $tree = $this->categories->tree($onlyActive);
        $parentId = isset($args['parent_id']) ? (int) $args['parent_id'] : null;

        $formatModel = function (Category $cat) use (&$formatModel): array {
            $children = ($cat->relationLoaded('children') ? $cat->children : collect())
                ->map(fn (Category $c) => $formatModel($c))->values()->all();

            return [
                'id' => $cat->id,
                'parent_id' => $cat->parent_id,
                'name_ar' => $this->presenter->untrusted($cat->name_ar, 150),
                'name_en' => $this->presenter->untrusted($cat->name_en, 150),
                'slug' => $cat->slug,
                'active' => (bool) $cat->is_active,
                'products_count' => (int) ($cat->products_count ?? 0),
                'deep_link' => '#/categories?focus='.$cat->id,
                'children' => $children,
            ];
        };

        $formatUi = function (Category $cat) use (&$formatUi): array {
            $children = ($cat->relationLoaded('children') ? $cat->children : collect())
                ->map(fn (Category $c) => $formatUi($c))->values()->all();

            return [
                'id' => $cat->id,
                'name' => $cat->name_ar,
                'slug' => $cat->slug,
                'active' => (bool) $cat->is_active,
                'products_count' => (int) ($cat->products_count ?? 0),
                'deep_link' => '#/categories?focus='.$cat->id,
                'children' => $children,
            ];
        };

        $findBranch = function ($nodes, int $targetId) use (&$findBranch) {
            foreach ($nodes as $node) {
                if ((int) $node->id === $targetId) {
                    return collect([$node]);
                }
                if ($node->relationLoaded('children') && $node->children->isNotEmpty()) {
                    $found = $findBranch($node->children, $targetId);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        $sourceNodes = $parentId !== null ? ($findBranch($tree, $parentId) ?? collect()) : $tree;
        $modelNodes = $sourceNodes->map(fn (Category $c) => $formatModel($c))->values()->all();
        $uiNodes = $sourceNodes->map(fn (Category $c) => $formatUi($c))->values()->all();

        return $this->presenter->present(
            $actor,
            ['categories' => $modelNodes, 'deep_link' => '#/categories'],
            ['type' => 'category_tree', 'nodes' => $uiNodes]
        );
    }
}
