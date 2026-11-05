<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Catalog\CatalogUndoGuard;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\CategoryService;
use App\Services\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class MoveCategoryTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private CategoryService $categories,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'move_category';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['categories.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'نقل قسم تحت قسم أب آخر أو للجذر (parent_id = null). يفحص الحلقات وأقصى عمق (3 مستويات) والمنتجات المنشورة قبل الاقتراح.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category_id'],
                'properties' => (object) [
                    'category_id' => ['type' => 'INTEGER'],
                    'parent_id' => ['type' => 'INTEGER', 'nullable' => true],
                    'sort_order' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $category = Category::find((int) ($args['category_id'] ?? 0));
        if (! $category) {
            return ToolOutcome::denied('القسم مش موجود.', auditable: false);
        }

        $newParentId = array_key_exists('parent_id', $args) && $args['parent_id'] !== null && $args['parent_id'] !== ''
            ? (int) $args['parent_id']
            : null;

        if ($newParentId !== null && ! Category::whereKey($newParentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => ['القسم الأب غير موجود.']]);
        }

        try {
            $this->categories->assertValidParent($category, $newParentId);
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(['parent_id' => [$e->getMessage()]]);
        }

        $oldParent = $category->parent_id ? Category::find($category->parent_id) : null;
        $newParent = $newParentId ? Category::find($newParentId) : null;

        $oldPath = ($oldParent ? $this->presenter->categoryPathString($oldParent).' › ' : '').$category->name_ar;
        $newPath = ($newParent ? $this->presenter->categoryPathString($newParent).' › ' : '').$category->name_ar;

        $newSortOrder = isset($args['sort_order']) ? max(0, (int) $args['sort_order']) : (int) $category->sort_order;

        $beforeTree = $this->presenter->categoryTreeSnapshot();
        $afterTree = $this->presenter->categoryTreeSnapshot([
            $category->id => [
                'parent_id' => $newParentId,
                'sort_order' => $newSortOrder,
            ],
        ]);

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'category_id' => $category->id,
                'parent_id' => $newParentId,
                'sort_order' => $newSortOrder,
            ],
            risk: $this->risk(),
            title: "نقل القسم: {$oldPath} ← {$newPath}",
            before: $beforeTree,
            after: $afterTree,
            expectedVersions: ['category:'.$category->id => (int) $category->lock_version],
            inverse: [
                'parent_id' => $category->parent_id,
                'sort_order' => (int) $category->sort_order,
            ],
            extraPreview: [
                'block_type' => 'category_tree_diff',
                'moved_id' => $category->id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
            ],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: false);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = Category::findOrFail((int) $action->args['category_id']);
        $before = ['parent_id' => $category->parent_id, 'sort_order' => $category->sort_order];
        $updated = $this->categories->update($category, [
            'parent_id' => $action->args['parent_id'] ?? null,
            'sort_order' => $action->args['sort_order'] ?? $category->sort_order,
            'expected_version' => $action->expected_versions['category:'.$category->id] ?? null,
        ], null);

        StaffAdministration::audit(
            $actor,
            'category.moved',
            'category',
            $updated->id,
            ['parent_id', 'sort_order'],
            $before,
            ['parent_id' => $updated->parent_id, 'sort_order' => $updated->sort_order],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'category:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتنقل القسم «{$updated->name_ar}».", [
            'category_id' => $updated->id,
            'parent_id' => $updated->parent_id,
            'deep_link' => '#/categories?focus='.$updated->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('parent_id', $action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $c = Category::findOrFail((int) $action->args['category_id']);

        return hash('sha256', json_encode([$c->parent_id, $c->sort_order, $c->is_active, $c->lock_version], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = Category::findOrFail((int) $action->args['category_id']);
        CatalogUndoGuard::assertUnchanged($this, $action);
        $updated = $this->categories->update($category, [
            'expected_version' => (int) $category->lock_version,
            'parent_id' => $action->inverse['parent_id'] ?? null,
            'sort_order' => $action->inverse['sort_order'] ?? $category->sort_order,
        ], null);

        return ToolOutcome::result(true, "تم التراجع عن نقل القسم «{$updated->name_ar}».", [
            'category_id' => $updated->id,
            'deep_link' => '#/categories?focus='.$updated->id,
        ]);
    }
}
