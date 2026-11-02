<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\VariantService;
use App\Services\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class DeleteVariantTool implements LocksTargets, WriteTool
{
    public function __construct(
        private VariantService $variants,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'delete_variant';
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
        return ['products.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حذف متغير منتج بشرط عدم وجود رصيد مخزون وألا يكون آخر متغير نشط في منتج منشور.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['variant_id'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
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

        if ($variant->product && $variant->product->status === ProductStatus::Published) {
            $hasOtherActive = $variant->product->variants()->where('is_active', true)->where('id', '!=', $variant->id)->exists();
            if (! $hasOtherActive) {
                throw ValidationException::withMessages([
                    'variant_id' => ['لا يمكن حذف آخر متغير نشط في منتج منشور.'],
                ]);
            }
        }

        if (StockLevel::query()->where('product_variant_id', $variant->id)->hasNonZeroBalance()->exists()) {
            throw ValidationException::withMessages([
                'variant_id' => ['لا يمكن حذف متغير له رصيد مخزون؛ صفِّ رصيده أولًا أو عطّله بدلًا من حذفه.'],
            ]);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['variant_id' => $variant->id],
            risk: $this->risk(),
            title: "حذف المتغير {$variant->sku}",
            before: $this->presenter->variant($variant, $actor),
            after: null,
            warnings: ['لو المتغير عليه حركة سابقة، التعطيل بـ set_variant_status أأمن من الحذف.'],
            expectedVersions: ['variant:'.$variant->id => (int) $variant->lock_version],
            typedConfirmation: $variant->sku,
            typedConfirmationHint: 'اكتب SKU المتغير للتأكيد',
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $variant = ProductVariant::findOrFail((int) $action->args['variant_id']);
        $expected = $action->expected_versions['variant:'.$variant->id] ?? null;
        if ($expected !== null && (int) $variant->lock_version !== (int) $expected) {
            throw new BusinessException('المتغير تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
        }

        $sku = $variant->sku;
        $productId = $variant->product_id;
        $id = $variant->id;
        $before = ['sku' => $variant->sku, 'barcode' => $variant->barcode];

        $this->variants->delete($variant);

        StaffAdministration::audit(
            $actor,
            'variant.deleted',
            'variant',
            $id,
            ['id', 'sku'],
            $before,
            [],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'variant:'.$id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتحذف المتغير {$sku}.", [
            'variant_id' => $id,
            'sku' => $sku,
            'deep_link' => "#/products/{$productId}",
        ]);
    }
}
