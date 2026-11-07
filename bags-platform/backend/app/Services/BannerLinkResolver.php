<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BannerLinkType;
use App\Models\Banner;
use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Resolves destination URLs for promotional banners.
 * Maps product, category, and external link targets to storefront routes.
 */
final class BannerLinkResolver
{
    public function __construct(private readonly StoreCatalogService $catalog) {}

    /**
     * Resolve and attach valid relative or HTTPS target URLs to banner records.
     *
     * @param  Collection<int, Banner>  $banners
     * @return Collection<int, Banner>
     */
    public function attach(Collection $banners): Collection
    {
        $productIds = self::extractTargetIds($banners, BannerLinkType::Product);
        $categoryIds = self::extractTargetIds($banners, BannerLinkType::Category);

        // Batch-resolve slugs for published products and active categories only
        $productSlugs = $productIds === [] ? collect() : $this->catalog->publishedQuery()
            ->whereIn('products.id', $productIds)->pluck('products.slug', 'products.id');
        $categorySlugs = $categoryIds === [] ? collect() : Category::query()
            ->whereIn('id', $categoryIds)->where('is_active', true)->pluck('slug', 'id');

        return $banners->each(function ($b) use ($productSlugs, $categorySlugs): void {
            $href = match ($b->link_type) {
                BannerLinkType::Product => ($s = $productSlugs->get((int) $b->link_value)) ? '/products/'.rawurlencode($s) : null,
                BannerLinkType::Category => ($s = $categorySlugs->get((int) $b->link_value)) ? '/shop?category='.rawurlencode($s) : null,
                // Only allow secure HTTPS external links to prevent protocol exploitation
                BannerLinkType::External => self::sanitizeExternalUrl($b->link_value),
                default => null,
            };
            $b->setAttribute('resolved_href', $href);
        });
    }

    /**
     * @param  Collection<int, Banner>  $banners
     * @return list<int>
     */
    public static function extractTargetIds(Collection $banners, BannerLinkType $type): array
    {
        return $banners->where('link_type', $type)
            ->pluck('link_value')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Only allow secure HTTPS external links to prevent protocol exploitation.
     */
    public static function sanitizeExternalUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }
}
