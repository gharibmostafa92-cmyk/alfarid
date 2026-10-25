<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BannerLinkType;
use App\Models\Banner;
use App\Models\Category;
use Illuminate\Support\Collection;

final class BannerLinkResolver
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    /** @param Collection<int, Banner> $banners */
    public function attach(Collection $banners): Collection
    {
        $productIds = $banners->filter(fn ($b) => $b->link_type === BannerLinkType::Product)
            ->pluck('link_value')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $categoryIds = $banners->filter(fn ($b) => $b->link_type === BannerLinkType::Category)
            ->pluck('link_value')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        $productSlugs = $productIds === [] ? collect() : $this->catalog->publishedQuery()
            ->whereIn('products.id', $productIds)->pluck('products.slug', 'products.id');
        $categorySlugs = $categoryIds === [] ? collect() : Category::query()
            ->whereIn('id', $categoryIds)->where('is_active', true)->pluck('slug', 'id');

        return $banners->each(function ($b) use ($productSlugs, $categorySlugs): void {
            $href = match ($b->link_type) {
                BannerLinkType::Product => ($s = $productSlugs->get((int) $b->link_value)) ? "/products/{$s}" : null,
                BannerLinkType::Category => ($s = $categorySlugs->get((int) $b->link_value)) ? "/shop?category={$s}" : null,
                BannerLinkType::External => $b->link_value ?: null,
                default => null,
            };
            $b->setAttribute('resolved_href', $href);
        });
    }
}
