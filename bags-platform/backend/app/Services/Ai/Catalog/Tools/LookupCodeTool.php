<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class LookupCodeTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'lookup_code';
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
            'description' => 'بحث مباشر بالـ SKU أو الباركود أو كود المنتج (P00042): يرجع متغير واحد ومنتجه.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['code'],
                'properties' => (object) [
                    'code' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            return ToolOutcome::denied('الكود مطلوب.', auditable: false);
        }

        $variant = $this->resolver->exactVariantByCode($code);
        if ($variant) {
            $product = Product::with(['category', 'variants.media', 'media'])->find($variant->product_id);
            if (! $product) {
                return ToolOutcome::denied('المنتج المرتبط بالمتغير مش موجود.', auditable: false);
            }

            return $this->presenter->present(
                $actor,
                [
                    'matched_by' => 'variant',
                    'variant' => $this->presenter->variant($variant, $actor),
                    'product' => $this->presenter->product($product, $actor),
                    'deep_link' => '#/products/'.$product->id,
                ],
                $this->presenter->productCardBlock($product, $actor)
            );
        }

        $product = Product::with(['category', 'variants.media', 'media'])
            ->whereRaw('LOWER(code) = LOWER(?)', [$code])
            ->first();

        if (! $product) {
            return ToolOutcome::denied('مفيش منتج أو متغير بالكود أو الباركود ده.', auditable: false);
        }

        $firstVariant = $product->variants->first();

        return $this->presenter->present(
            $actor,
            [
                'matched_by' => 'product_code',
                'variant' => $firstVariant ? $this->presenter->variant($firstVariant, $actor) : null,
                'product' => $this->presenter->product($product, $actor),
                'deep_link' => '#/products/'.$product->id,
            ],
            $this->presenter->productCardBlock($product, $actor)
        );
    }
}
