<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Catalog\ProductStatus;
use App\Models\AiPendingAction;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Catalog\CatalogUndoGuard;
use App\Services\Ai\Catalog\CatalogWarnings;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\VariantService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class SetVariantStatusTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private VariantService $variants,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'set_variant_status';
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
        return ['products.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تفعيل أو تعطيل متغير منتج.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['variant_id', 'is_active'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $variant = ProductVariant::with('product')->find((int) ($args['variant_id'] ?? 0));
        if (! $variant) {
            return ToolOutcome::denied('المتغير مش موجود.', auditable: false);
        }

        $targetActive = (bool) ($args['is_active'] ?? false);
        if (! $targetActive && $variant->product && $variant->product->status === ProductStatus::Published) {
            $hasOtherActive = $variant->product->variants()->where('is_active', true)->where('id', '!=', $variant->id)->exists();
            if (! $hasOtherActive) {
                throw ValidationException::withMessages([
                    'is_active' => ['لا يمكن تعطيل آخر متغير نشط في منتج منشور؛ أرشف المنتج أولًا.'],
                ]);
            }
        }

        $warnings = CatalogWarnings::forVariantChange($variant, ['is_active' => $targetActive]);

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'variant_id' => $variant->id,
                'is_active' => $targetActive,
            ],
            risk: $this->risk(),
            title: ($targetActive ? 'تفعيل' : 'تعطيل')." المتغير {$variant->sku}",
            before: ['is_active' => (bool) $variant->is_active],
            after: ['is_active' => $targetActive],
            warnings: $warnings,
            expectedVersions: ['variant:'.$variant->id => (int) $variant->lock_version],
            inverse: ['is_active' => (bool) $variant->is_active],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $variant = ProductVariant::findOrFail((int) $action->args['variant_id']);
        $oldActive = (bool) $variant->is_active;
        $updated = $this->variants->update($variant, [
            'is_active' => (bool) $action->args['is_active'],
            'expected_version' => $action->expected_versions['variant:'.$variant->id] ?? null,
        ]);

        StaffAdministration::audit(
            $actor,
            'variant.status_changed',
            'variant',
            $updated->id,
            ['is_active'],
            ['is_active' => $oldActive],
            ['is_active' => (bool) $updated->is_active],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'variant:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(
            true,
            ($updated->is_active ? 'اتفعل' : 'اتعطل')." المتغير {$updated->sku}.",
            [
                'variant_id' => $updated->id,
                'sku' => $updated->sku,
                'is_active' => (bool) $updated->is_active,
                'deep_link' => "#/products/{$updated->product_id}",
            ]
        );
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('is_active', $action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $v = ProductVariant::findOrFail((int) $action->args['variant_id']);

        return hash('sha256', json_encode([$v->lock_version, (bool) $v->is_active], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $variant = ProductVariant::findOrFail((int) $action->args['variant_id']);
        CatalogUndoGuard::assertUnchanged($this, $action);
        $updated = $this->variants->update($variant, [
            'is_active' => (bool) $action->inverse['is_active'],
            'expected_version' => (int) $variant->lock_version,
        ]);

        return ToolOutcome::result(true, "تم التراجع عن تغيير حالة المتغير {$updated->sku}.", [
            'variant_id' => $updated->id,
            'deep_link' => "#/products/{$updated->product_id}",
        ]);
    }
}
