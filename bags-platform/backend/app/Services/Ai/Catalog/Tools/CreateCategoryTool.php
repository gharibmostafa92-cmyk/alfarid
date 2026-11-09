<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\SlugService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class CreateCategoryTool implements LocksTargets, WriteTool
{
    public function __construct(
        private CategoryService $categories,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'create_category';
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
        return ['categories.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء قسم جديد في الكتالوج (أقصى عمق 3 مستويات). لازم تبعت name_ar و name_en.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['name_ar', 'name_en'],
                'properties' => (object) [
                    'parent_id' => ['type' => 'INTEGER'],
                    'name_ar' => ['type' => 'STRING'],
                    'name_en' => ['type' => 'STRING'],
                    'slug' => ['type' => 'STRING'],
                    'description_ar' => ['type' => 'STRING'],
                    'description_en' => ['type' => 'STRING'],
                    'sort_order' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        unset($args['image']);

        $rules = StoreCategoryRequest::baseRules();
        unset($rules['image']);
        $data = CatalogArgs::validate($args, $rules);

        if (empty($data['slug']) && ! empty($data['name_en'])) {
            $data['slug'] = SlugService::make(Category::class, (string) $data['name_en']);
        }
        $data['is_active'] = array_key_exists('is_active', $data) && $data['is_active'] !== null ? (bool) $data['is_active'] : true;
        $data['sort_order'] = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = Category::find((int) $data['parent_id']);
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => ['القسم الأب غير موجود.']]);
            }
            if ($parent->depth() >= 3) {
                throw ValidationException::withMessages(['parent_id' => ['أقصى عمق للأقسام 3 مستويات.']]);
            }
        }

        $afterPreview = [
            'parent_id' => $parent?->id,
            'parent_name' => $parent?->name_ar ?? 'جذر (قسم رئيسي)',
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'slug' => $data['slug'],
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'],
        ];

        $warnings = [];
        if ($parent && ! $parent->is_active) {
            $warnings[] = 'تنبيه: القسم الأب معطل حاليًا، فالقسم الجديد مش هيكون في مسار نشط لحد ما الأب يتفعل.';
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data,
            risk: $this->risk(),
            title: "إنشاء قسم: {$data['name_ar']} ({$data['name_en']})",
            before: null,
            after: $afterPreview,
            warnings: $warnings,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = $this->categories->create($action->args, null);

        StaffAdministration::audit(
            $actor,
            'category.created',
            'category',
            $category->id,
            array_keys($action->args),
            [],
            ['name_ar' => $category->name_ar, 'name_en' => $category->name_en, 'slug' => $category->slug, 'parent_id' => $category->parent_id, 'is_active' => $category->is_active],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'category:'.$category->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتعمل القسم «{$category->name_ar}».", [
            'category_id' => $category->id,
            'slug' => $category->slug,
            'deep_link' => '#/categories?focus='.$category->id,
        ]);
    }
}
