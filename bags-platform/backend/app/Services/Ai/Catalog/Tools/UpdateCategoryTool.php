<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Http\Requests\Admin\Catalog\UpdateCategoryRequest;
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
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Arr;

final class UpdateCategoryTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private CategoryService $categories,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'update_category';
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
            'description' => 'تعديل بيانات قسم (الاسم العربي/الإنجليزي، الوصف، الـ slug، الترتيب). لنقل القسم استخدم move_category ولتغيير الحالة استخدم set_category_status.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category_id'],
                'properties' => (object) [
                    'category_id' => ['type' => 'INTEGER'],
                    'name_ar' => ['type' => 'STRING'],
                    'name_en' => ['type' => 'STRING'],
                    'slug' => ['type' => 'STRING'],
                    'description_ar' => ['type' => 'STRING'],
                    'description_en' => ['type' => 'STRING'],
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

        $input = Arr::only($args, ['name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order']);
        $rules = Arr::only(UpdateCategoryRequest::baseRules($category->id), ['name_ar', 'name_en', 'slug', 'description_ar', 'description_en', 'sort_order']);
        $data = CatalogArgs::validate($input, $rules);

        if ($data === []) {
            return ToolOutcome::denied('مفيش حقول مطلوبة للتعديل.', auditable: false);
        }

        $before = Arr::only($category->getAttributes(), array_keys($data));
        $after = Arr::only($category->replicate()->fill($data)->getAttributes(), array_keys($data));

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['category_id' => $category->id],
            risk: $this->risk(),
            title: "تعديل قسم: {$category->name_ar}",
            before: $before,
            after: $after,
            expectedVersions: ['category:'.$category->id => (int) $category->lock_version],
            inverse: $before,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = Category::findOrFail((int) $action->args['category_id']);
        $before = Arr::only($category->getAttributes(), array_keys(Arr::except($action->args, ['category_id'])));
        $data = Arr::except($action->args, ['category_id']) + [
            'expected_version' => $action->expected_versions['category:'.$category->id] ?? null,
        ];
        $updated = $this->categories->update($category, $data, null);

        StaffAdministration::audit(
            $actor,
            'category.updated',
            'category',
            $updated->id,
            array_keys(Arr::except($action->args, ['category_id'])),
            $before,
            Arr::only($updated->getAttributes(), array_keys($before)),
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'category:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتعدل القسم «{$updated->name_ar}».", [
            'category_id' => $updated->id,
            'deep_link' => '#/categories?focus='.$updated->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && $action->inverse !== [];
    }

    public function targetState(AiPendingAction $action): string
    {
        $c = Category::findOrFail((int) $action->args['category_id']);
        $keys = is_array($action->inverse) ? array_keys($action->inverse) : [];

        return hash('sha256', json_encode([
            $c->parent_id,
            $c->sort_order,
            $c->is_active,
            $c->lock_version,
            Arr::only($c->getAttributes(), $keys),
        ], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = Category::findOrFail((int) $action->args['category_id']);
        CatalogUndoGuard::assertUnchanged($this, $action);
        $updated = $this->categories->update($category, (array) $action->inverse + ['expected_version' => (int) $category->lock_version], null);

        return ToolOutcome::result(true, "تم التراجع عن تعديل القسم «{$updated->name_ar}».", [
            'category_id' => $updated->id,
            'deep_link' => '#/categories?focus='.$updated->id,
        ]);
    }
}
