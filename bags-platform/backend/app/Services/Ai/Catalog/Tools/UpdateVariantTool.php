<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\AiPendingAction;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Catalog\CatalogWarnings;
use App\Services\Ai\Catalog\UpdateVariantRules;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\VariantService;
use App\Services\CostAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Arr;

final class UpdateVariantTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private VariantService $variants,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'update_variant';
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
            'description' => 'تعديل متغير منتج واحد (سعر/لون/مقاس/باركود/حالة). للأسعار الجماعية استخدم bulk_update_prices.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['variant_id'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'barcode' => ['type' => 'STRING'],
                    'retail_price' => ['type' => 'NUMBER'],
                    'wholesale_price' => ['type' => 'NUMBER'],
                    'compare_at_price' => ['type' => 'NUMBER'],
                    'cost_price' => ['type' => 'NUMBER'],
                    'color' => ['type' => 'STRING'],
                    'size' => ['type' => 'STRING'],
                    'capacity' => ['type' => 'STRING'],
                    'reorder_level' => ['type' => 'INTEGER'],
                    'weight_kg' => ['type' => 'NUMBER'],
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

        if (! CostAccess::canView($actor)) {
            unset($args['cost_price']);
        }

        $data = CatalogArgs::normalizeVariant($args);
        CatalogArgs::validate($data, UpdateVariantRules::for($variant));
        if ($data === []) {
            return ToolOutcome::denied('مفيش حقول مطلوبة للتعديل.', auditable: false);
        }

        $warnings = CatalogWarnings::forVariantChange($variant, $data);
        $inverse = Arr::only($variant->getAttributes(), array_keys($data));
        if (! CostAccess::canView($actor)) {
            unset($inverse['cost_price']);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['variant_id' => $variant->id],
            risk: $this->risk(),
            title: "تعديل {$variant->sku}",
            before: $this->presenter->variant($variant, $actor),
            after: $this->presenter->variant($variant->replicate()->fill($data), $actor),
            warnings: $warnings,
            expectedVersions: ['variant:'.$variant->id => (int) $variant->lock_version],
            inverse: $inverse,
        );
    }

    public function lockTargets(AiPendingAction $a): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $a): ToolOutcome
    {
        $variant = ProductVariant::findOrFail((int) $a->args['variant_id']);
        $fields = Arr::except($a->args, ['variant_id']);
        if (! CostAccess::canView($actor)) {
            unset($fields['cost_price']);
        }
        $before = Arr::only($variant->getAttributes(), array_keys($fields));
        $data = $fields + ['expected_version' => $a->expected_versions['variant:'.$variant->id] ?? null];
        $variant = $this->variants->update($variant, $data);

        StaffAdministration::audit(
            $actor,
            'variant.updated',
            'variant',
            $variant->id,
            array_keys($fields),
            $before,
            Arr::only($variant->getAttributes(), array_keys($fields)),
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'variant:'.$variant->id, 'conversation_id' => $a->conversation_id, 'action_id' => $a->id],
        );

        return ToolOutcome::result(true, 'اتعدل المتغير.', [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'deep_link' => "#/products/{$variant->product_id}",
        ]);
    }

    public function canUndo(AiPendingAction $a): bool
    {
        return $a->inverse !== null && $a->inverse !== [];
    }

    public function targetState(AiPendingAction $a): string
    {
        $v = ProductVariant::findOrFail((int) $a->args['variant_id']);

        return hash('sha256', json_encode([$v->lock_version, Arr::only($v->getAttributes(), array_keys($a->inverse))], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $a): ToolOutcome
    {
        $variant = ProductVariant::findOrFail((int) $a->args['variant_id']);
        $variant = $this->variants->update($variant, (array) $a->inverse);

        return ToolOutcome::result(true, "تم التراجع عن تعديل المتغير {$variant->sku}.", [
            'variant_id' => $variant->id,
            'deep_link' => "#/products/{$variant->product_id}",
        ]);
    }
}
