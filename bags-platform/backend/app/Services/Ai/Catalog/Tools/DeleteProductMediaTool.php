<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Catalog\ProductStatus;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\ProductMediaService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class DeleteProductMediaTool implements LocksTargets, WriteTool
{
    public function __construct(
        private ProductMediaService $media,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'delete_product_media';
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
            'description' => 'حذف صورة من منتج (يرفض لو دي آخر صورة لمنتج منشور).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id', 'media_id'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'media_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = Product::with('variants')->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $mediaItem = Media::find((int) ($args['media_id'] ?? 0));
        if (! $mediaItem) {
            return ToolOutcome::denied('الصورة مش موجودة.', auditable: false);
        }

        $variantIds = $product->variants()->withTrashed()->pluck('id')->all();
        $belongs = ($mediaItem->model_type === Product::class && (int) $mediaItem->model_id === (int) $product->id)
            || ($mediaItem->model_type === ProductVariant::class && in_array((int) $mediaItem->model_id, $variantIds, true));

        if (! $belongs) {
            return ToolOutcome::denied('الصورة لا تتبع هذا المنتج.', auditable: false);
        }

        $activeVariantIds = $product->variants->where('is_active', true)->pluck('id')->all();
        $hasOtherImages = Media::query()
            ->where('collection_name', 'images')
            ->whereKeyNot($mediaItem->id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('model_type', Product::class)->where('model_id', $product->id))
                ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $activeVariantIds)))
            ->exists();

        if ($product->status === ProductStatus::Published && $mediaItem->collection_name === 'images' && ! $hasOtherImages) {
            throw ValidationException::withMessages([
                'media_id' => ['لا يمكن حذف آخر صورة لمنتج منشور؛ ارفع صورة بديلة أولًا أو أرشف المنتج.'],
            ]);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'product_id' => $product->id,
                'media_id' => $mediaItem->id,
            ],
            risk: $this->risk(),
            title: "حذف الصورة #{$mediaItem->id} من المنتج {$product->code}",
            before: [
                'media_id' => $mediaItem->id,
                'file_name' => $mediaItem->file_name,
            ],
            after: null,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $mediaItem = Media::findOrFail((int) $action->args['media_id']);
        $mediaId = $mediaItem->id;

        $this->media->delete($product, $mediaItem);

        StaffAdministration::audit(
            $actor,
            'product.media_deleted',
            'product',
            $product->id,
            ['media_ids'],
            ['media_ids' => [$mediaId]],
            [],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$product->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتحذفت الصورة #{$mediaId} من المنتج {$product->code}.", [
            'product_id' => $product->id,
            'media_id' => $mediaId,
            'deep_link' => '#/products/'.$product->id,
        ]);
    }
}
