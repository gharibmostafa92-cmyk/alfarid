<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\ProductMediaService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ReorderProductMediaTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private ProductMediaService $media,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'reorder_product_media';
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
        return ['products.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إعادة ترتيب صور منتج أو تعيين صورة غلاف (cover_media_id يحط الصورة في الأول).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'cover_media_id' => ['type' => 'INTEGER'],
                    'ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = Product::find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $coverId = isset($args['cover_media_id']) && $args['cover_media_id'] !== null ? (int) $args['cover_media_id'] : null;
        $rawIds = array_values(array_map('intval', (array) ($args['ids'] ?? [])));

        if ($coverId !== null) {
            $coverMedia = Media::find($coverId);
            if (! $coverMedia) {
                throw ValidationException::withMessages(['cover_media_id' => ['الصورة المحددة غير موجودة.']]);
            }
            // Ensure cover image belongs to the product or its variants before altering reorder sequence.
            if (! $this->belongsToProduct($coverMedia, $product)) {
                return ToolOutcome::denied('الصورة لا تتبع هذا المنتج.', auditable: false);
            }
            $ownerCollection = Media::query()
                ->where('model_type', $coverMedia->model_type)
                ->where('model_id', $coverMedia->model_id)
                ->where('collection_name', $coverMedia->collection_name)
                ->orderBy('order_column')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $oldIds = $ownerCollection;
            $newIds = array_values(array_unique([$coverId, ...$ownerCollection]));
        } elseif ($rawIds !== []) {
            $first = Media::find($rawIds[0]);
            if (! $first) {
                throw ValidationException::withMessages(['ids' => ['الصورة الأولى غير موجودة.']]);
            }
            // Ensure first item in reorder list belongs to this product hierarchy.
            if (! $this->belongsToProduct($first, $product)) {
                return ToolOutcome::denied('الصورة لا تتبع هذا المنتج.', auditable: false);
            }
            $oldIds = Media::query()
                ->where('model_type', $first->model_type)
                ->where('model_id', $first->model_id)
                ->where('collection_name', $first->collection_name)
                ->orderBy('order_column')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($rawIds) !== count($oldIds) || array_diff($oldIds, $rawIds) !== [] || count(array_unique($rawIds)) !== count($rawIds)) {
                throw ValidationException::withMessages(['ids' => ['قائمة الترتيب يجب أن تشمل جميع صور المجموعة بدون نقص أو تكرار.']]);
            }
            $newIds = $rawIds;
        } else {
            throw ValidationException::withMessages(['ids' => ['ابعت cover_media_id أو قائمة ids كاملة.']]);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'product_id' => $product->id,
                'ids' => $newIds,
            ],
            risk: $this->risk(),
            title: $coverId !== null
                ? "تعيين الصورة #{$coverId} كغلاف للمنتج {$product->code}"
                : "إعادة ترتيب صور المنتج {$product->code}",
            before: ['ids' => $oldIds],
            after: ['ids' => $newIds],
            inverse: ['ids' => $oldIds],
        );
    }

    /**
     * Check whether media is owned directly by product or one of its variants (including trashed).
     */
    private function belongsToProduct(Media $media, Product $product): bool
    {
        $variantIds = $product->variants()->withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return ($media->model_type === Product::class && (int) $media->model_id === (int) $product->id)
            || ($media->model_type === ProductVariant::class && in_array((int) $media->model_id, $variantIds, true));
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $ids = array_map('intval', (array) $action->args['ids']);
        $this->media->reorder($product, $ids, array_map('intval', (array) $action->inverse['ids']));

        StaffAdministration::audit(
            $actor,
            'product.media_reordered',
            'product',
            $product->id,
            ['media_ids'],
            ['media_ids' => $action->inverse['ids'] ?? []],
            ['media_ids' => $ids],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$product->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اترتبت صور المنتج {$product->code}.", [
            'product_id' => $product->id,
            'ids' => $ids,
            'deep_link' => '#/products/'.$product->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return ! empty($action->inverse['ids']);
    }

    public function targetState(AiPendingAction $action): string
    {
        $ids = array_map('intval', (array) ($action->args['ids'] ?? []));
        $current = Media::whereIn('id', $ids)->orderBy('order_column')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();

        return hash('sha256', json_encode($current, JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $oldIds = array_map('intval', (array) $action->inverse['ids']);
        $this->media->reorder($product, $oldIds, array_map('intval', (array) $action->args['ids']));

        return ToolOutcome::result(true, "تم التراجع عن ترتيب صور المنتج {$product->code}.", [
            'product_id' => $product->id,
            'deep_link' => '#/products/'.$product->id,
        ]);
    }
}
