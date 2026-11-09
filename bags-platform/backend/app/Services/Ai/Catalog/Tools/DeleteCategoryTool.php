<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Exceptions\BusinessException;
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
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class DeleteCategoryTool implements LocksTargets, WriteTool
{
    public function __construct(
        private CategoryService $categories,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'delete_category';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['categories.delete'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حذف قسم فاضي من الكتالوج (يرفض لو فيه أقسام فرعية أو منتجات).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category_id'],
                'properties' => (object) [
                    'category_id' => ['type' => 'INTEGER'],
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

        $childrenCount = $category->children()->count();
        $productsCount = $category->products()->count();

        if ($childrenCount > 0 || $productsCount > 0) {
            $reasons = [];
            if ($childrenCount > 0) {
                $reasons[] = "{$childrenCount} قسم فرعي";
            }
            if ($productsCount > 0) {
                $reasons[] = "{$productsCount} منتج";
            }
            $joined = implode(' و ', $reasons);

            throw ValidationException::withMessages([
                'category_id' => ["ما ينفعش نحذف القسم «{$category->name_ar}» لأن تحته {$joined}. البديل: عطّل القسم بـ set_category_status أو انقل الأقسام الفرعية والمنتجات الأول."],
            ]);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['category_id' => $category->id],
            risk: $this->risk(),
            title: "حذف القسم: {$category->name_ar} ({$category->slug})",
            before: [
                'id' => $category->id,
                'name_ar' => $category->name_ar,
                'name_en' => $category->name_en,
                'slug' => $category->slug,
            ],
            after: null,
            warnings: ['الحذف هيشيل القسم؛ اتأكد إنه مش مطلوب.'],
            expectedVersions: ['category:'.$category->id => (int) $category->lock_version],
            typedConfirmation: $category->slug,
            typedConfirmationHint: 'اكتب slug القسم للتأكيد',
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: false);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = Category::findOrFail((int) $action->args['category_id']);
        $expected = $action->expected_versions['category:'.$category->id] ?? null;
        if ($expected !== null && (int) $category->lock_version !== (int) $expected) {
            throw new BusinessException('القسم تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
        }

        $name = $category->name_ar;
        $id = $category->id;
        $before = ['name_ar' => $category->name_ar, 'name_en' => $category->name_en, 'slug' => $category->slug];

        $this->categories->delete($category, $expected === null ? null : (int) $expected);

        StaffAdministration::audit(
            $actor,
            'category.deleted',
            'category',
            $id,
            ['id', 'slug'],
            $before,
            [],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'category:'.$id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتحذف القسم «{$name}».", [
            'category_id' => $id,
            'deep_link' => '#/categories',
        ]);
    }
}
