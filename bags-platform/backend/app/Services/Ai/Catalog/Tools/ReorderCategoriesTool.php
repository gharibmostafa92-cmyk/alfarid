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
use App\Services\Catalog\CategoryHierarchy;
use App\Services\Catalog\CategoryService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class ReorderCategoriesTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private CategoryService $categories,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'reorder_categories';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['categories.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إعادة ترتيب الأقسام بتحديد قائمة عناصر فيها id و sort_order و parent_id (اختياري، لو ما اتبعتش بياخد الأب الحالي).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['items'],
                'properties' => (object) [
                    'items' => [
                        'type' => 'ARRAY',
                        'items' => [
                            'type' => 'OBJECT',
                            'required' => ['id', 'sort_order'],
                            'properties' => (object) [
                                'id' => ['type' => 'INTEGER'],
                                'parent_id' => ['type' => 'INTEGER', 'nullable' => true],
                                'sort_order' => ['type' => 'INTEGER'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $rawItems = is_array($args['items'] ?? null) ? $args['items'] : [];
        if ($rawItems === []) {
            throw ValidationException::withMessages(['items' => ['قائمة الأقسام مطلوبة للترتيب.']]);
        }

        $ids = array_map(fn ($item) => (int) ($item['id'] ?? 0), $rawItems);
        $existing = Category::whereIn('id', $ids)->get()->keyBy('id');
        if ($existing->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => ['أحد الأقسام المحددة غير موجود.']]);
        }

        $items = [];
        $inverseItems = [];
        $expectedVersions = [];
        $overrides = [];
        $nodes = CategoryHierarchy::snapshot();

        foreach ($rawItems as $raw) {
            $id = (int) $raw['id'];
            /** @var Category $cat */
            $cat = $existing->get($id);
            $parentId = array_key_exists('parent_id', $raw)
                ? ($raw['parent_id'] === null ? null : (int) $raw['parent_id'])
                : $cat->parent_id;
            $sortOrder = max(0, (int) ($raw['sort_order'] ?? 0));

            $items[] = [
                'id' => $id,
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
            ];
            $inverseItems[] = [
                'id' => $id,
                'parent_id' => $cat->parent_id,
                'sort_order' => (int) $cat->sort_order,
            ];
            $expectedVersions['category:'.$id] = (int) $cat->lock_version;
            $overrides[$id] = [
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
            ];
            $nodes[$id]['parent_id'] = $parentId;
        }

        try {
            CategoryHierarchy::assertValid($nodes);
            CategoryHierarchy::assertPublishedPathsActive($nodes);
        } catch (BusinessException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        $beforeTree = $this->presenter->categoryTreeSnapshot();
        $afterTree = $this->presenter->categoryTreeSnapshot($overrides);

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['items' => $items],
            risk: $this->risk(),
            title: 'إعادة ترتيب '.count($items).' قسم',
            before: $beforeTree,
            after: $afterTree,
            expectedVersions: $expectedVersions,
            inverse: ['items' => $inverseItems],
            extraPreview: [
                'block_type' => 'category_tree_diff',
                'moved_id' => $items[0]['id'] ?? null,
            ],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $before = array_column((array) ($action->inverse['items'] ?? []), 'parent_id', 'id');
        $moves = collect((array) ($action->args['items'] ?? []))->contains(fn ($item) => ($item['parent_id'] ?? null) !== ($before[$item['id']] ?? null));
        CatalogLock::tree(shared: ! $moves);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $items = (array) ($action->args['items'] ?? []);
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $cat = Category::findOrFail($id);
            $expected = $action->expected_versions['category:'.$id] ?? null;
            if ($expected !== null && (int) $cat->lock_version !== (int) $expected) {
                throw new BusinessException('القسم تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
            }
        }

        $items = array_map(fn ($item) => $item + ['expected_version' => $action->expected_versions['category:'.$item['id']] ?? null], $items);
        $this->categories->reorder($items);

        StaffAdministration::audit(
            $actor,
            'category.reordered',
            'category',
            null,
            ['sort_order', 'parent_id'],
            ['count' => count($items)],
            ['count' => count($items)],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'category:reorder', 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'اترتبت الأقسام بنجاح.', [
            'count' => count($items),
            'deep_link' => '#/categories',
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return ! empty($action->inverse['items']);
    }

    public function targetState(AiPendingAction $action): string
    {
        $ids = array_column((array) ($action->args['items'] ?? []), 'id');
        $rows = Category::whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'parent_id', 'sort_order', 'is_active', 'lock_version'])
            ->map(fn (Category $c) => [$c->id, $c->parent_id, $c->sort_order, $c->is_active, $c->lock_version])
            ->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $items = (array) $action->inverse['items'];
        $versions = Category::whereIn('id', array_column($items, 'id'))->pluck('lock_version', 'id');
        CatalogUndoGuard::assertUnchanged($this, $action);
        $items = array_map(fn ($item) => $item + ['expected_version' => (int) $versions[$item['id']]], $items);
        $this->categories->reorder($items);

        return ToolOutcome::result(true, 'تم التراجع عن ترتيب الأقسام.', [
            'deep_link' => '#/categories',
        ]);
    }
}
