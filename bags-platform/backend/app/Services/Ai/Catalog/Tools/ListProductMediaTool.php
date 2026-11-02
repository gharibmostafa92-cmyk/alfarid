<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\ProductMediaService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ListProductMediaTool implements AgentTool
{
    public function __construct(
        private ProductMediaService $media,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_product_media';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['products.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'عرض صور ووسائط منتج مرتبة مع توضيح صورة الغلاف والمتغير المرتبط بكل صورة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = Product::with('variants')->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $variantsById = $product->variants->keyBy('id');
        $items = $this->media->index($product);
        $imagesOnly = $items->where('collection_name', 'images')->values();
        $coverId = $imagesOnly->first()?->id;

        $formatted = $imagesOnly->map(function (Media $m, int $idx) use ($variantsById, $coverId): array {
            $variantLabel = null;
            if ($m->model_type === ProductVariant::class) {
                $v = $variantsById->get($m->model_id);
                if ($v) {
                    $variantLabel = trim(($v->color_name_ar ?? '').' '.($v->size ?? '')) ?: $v->sku;
                }
            }

            return [
                'id' => (int) $m->id,
                'thumb_url' => $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : $m->getUrl(),
                'order' => (int) ($m->order_column ?? ($idx + 1)),
                'is_cover' => (int) $m->id === (int) $coverId,
                'variant_id' => $m->model_type === ProductVariant::class ? (int) $m->model_id : null,
                'variant_label' => $variantLabel,
            ];
        })->all();

        return $this->presenter->present(
            $actor,
            [
                'product_id' => $product->id,
                'code' => $product->code,
                'count' => count($formatted),
                'items' => $formatted,
                'deep_link' => '#/products/'.$product->id,
            ],
            [
                'type' => 'media_gallery',
                'product_id' => $product->id,
                'code' => $product->code,
                'items' => $formatted,
                'deep_link' => '#/products/'.$product->id,
            ],
        );
    }
}
