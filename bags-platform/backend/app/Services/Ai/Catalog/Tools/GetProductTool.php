<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class GetProductTool implements AgentTool
{
    public function __construct(private CatalogPresenter $presenter) {}

    public function name(): string
    {
        return 'get_product';
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
            'description' => 'تفاصيل منتج واحد ومتغيراته ونطاق الأسعار وعدد الصور والجاهزية للنشر.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'code' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = null;
        if (! empty($args['product_id'])) {
            $product = Product::with(['category', 'variants.media', 'media'])->find((int) $args['product_id']);
        } elseif (! empty($args['code'])) {
            $product = Product::with(['category', 'variants.media', 'media'])
                ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $args['code'])])
                ->first();
        }

        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        return $this->presenter->present(
            $actor,
            ['product' => $this->presenter->product($product, $actor)],
            $this->presenter->productCardBlock($product, $actor)
        );
    }
}
