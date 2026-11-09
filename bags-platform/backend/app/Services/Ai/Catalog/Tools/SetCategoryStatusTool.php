<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Catalog\ProductStatus;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
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
use Illuminate\Validation\ValidationException;

final class SetCategoryStatusTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private CategoryService $categories,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'set_category_status';
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
            'description' => 'تفعيل أو تعطيل قسم في الكتالوج. لو القسم تحته منتجات منشورة، التعطيل هيترفض ويطلب أرشفة أو نقل المنتجات الأول.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category_id', 'is_active'],
                'properties' => (object) [
                    'category_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
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

        $targetActive = (bool) ($args['is_active'] ?? false);
        $subtreeIds = [$category->id, ...$category->descendantIds()];
        $publishedQuery = Product::query()
            ->whereIn('category_id', $subtreeIds)
            ->where('status', ProductStatus::Published);
        $publishedCount = (clone $publishedQuery)->count();

        if (! $targetActive && $publishedCount > 0) {
            $sample = (clone $publishedQuery)
                ->orderBy('id')
                ->limit(5)
                ->get(['code', 'name_ar'])
                ->map(fn (Product $p) => "{$p->name_ar} ({$p->code})")
                ->implode('، ');

            throw ValidationException::withMessages([
                'is_active' => ["لازم تأرشف أو تنقل الـ {$publishedCount} منتج دول الأول: {$sample}"],
            ]);
        }

        $risk = $publishedCount > 0 ? 'high' : 'medium';

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'category_id' => $category->id,
                'is_active' => $targetActive,
            ],
            risk: $risk,
            title: ($targetActive ? 'تفعيل' : 'تعطيل')." القسم: {$category->name_ar}",
            before: ['is_active' => (bool) $category->is_active],
            after: ['is_active' => $targetActive],
            expectedVersions: ['category:'.$category->id => (int) $category->lock_version],
            inverse: ['is_active' => (bool) $category->is_active],
            typedConfirmation: $risk === 'high' ? $category->slug : null,
            typedConfirmationHint: $risk === 'high' ? 'اكتب slug القسم للتأكيد' : null,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: false);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $category = Category::findOrFail((int) $action->args['category_id']);
        $oldActive = (bool) $category->is_active;
        $updated = $this->categories->update($category, [
            'is_active' => (bool) $action->args['is_active'],
            'expected_version' => $action->expected_versions['category:'.$category->id] ?? null,
        ], null);

        StaffAdministration::audit(
            $actor,
            'category.status_changed',
            'category',
            $updated->id,
            ['is_active'],
            ['is_active' => $oldActive],
            ['is_active' => (bool) $updated->is_active],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'category:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(
            true,
            ($updated->is_active ? 'اتفعل' : 'اتعطل')." القسم «{$updated->name_ar}».",
            [
                'category_id' => $updated->id,
                'is_active' => (bool) $updated->is_active,
                'deep_link' => '#/categories?focus='.$updated->id,
            ]
        );
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('is_active', $action->inverse);
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
            'is_active' => (bool) $action->inverse['is_active'],
            'expected_version' => (int) $category->lock_version,
        ], null);

        return ToolOutcome::result(true, "تم التراجع عن تغيير حالة القسم «{$updated->name_ar}».", [
            'category_id' => $updated->id,
            'deep_link' => '#/categories?focus='.$updated->id,
        ]);
    }
}
