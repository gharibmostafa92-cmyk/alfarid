<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Catalog\BannerLinkType;
use App\Models\Banner;
use App\Services\Catalog\BannerLinkResolver;
use App\Services\Catalog\StoreCatalogService;
use PHPUnit\Framework\TestCase;

class BannerLinkResolverTest extends TestCase
{
    private BannerLinkResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $catalog = $this->createMock(StoreCatalogService::class);
        $this->resolver = new BannerLinkResolver($catalog);
    }

    public function test_sanitize_external_url_accepts_only_valid_https(): void
    {
        $this->assertSame('https://example.com/promo', BannerLinkResolver::sanitizeExternalUrl('https://example.com/promo'));
        $this->assertNull(BannerLinkResolver::sanitizeExternalUrl('http://insecure.com'));
        $this->assertNull(BannerLinkResolver::sanitizeExternalUrl('javascript:alert(1)'));
        $this->assertNull(BannerLinkResolver::sanitizeExternalUrl('not-a-url'));
        $this->assertNull(BannerLinkResolver::sanitizeExternalUrl(null));
        $this->assertNull(BannerLinkResolver::sanitizeExternalUrl(12345));
    }

    public function test_extract_target_ids(): void
    {
        $b1 = new Banner(['link_type' => BannerLinkType::Product, 'link_value' => '10']);
        $b2 = new Banner(['link_type' => BannerLinkType::Product, 'link_value' => '20']);
        $b3 = new Banner(['link_type' => BannerLinkType::Product, 'link_value' => '10']); // duplicate
        $b4 = new Banner(['link_type' => BannerLinkType::Category, 'link_value' => '5']);

        $banners = collect([$b1, $b2, $b3, $b4]);

        $productIds = BannerLinkResolver::extractTargetIds($banners, BannerLinkType::Product);
        $this->assertSame([10, 20], $productIds);

        $categoryIds = BannerLinkResolver::extractTargetIds($banners, BannerLinkType::Category);
        $this->assertSame([5], $categoryIds);

        $externalIds = BannerLinkResolver::extractTargetIds($banners, BannerLinkType::External);
        $this->assertSame([], $externalIds);
    }
}
