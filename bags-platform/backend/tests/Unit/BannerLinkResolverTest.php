<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BannerLinkType;
use App\Models\Banner;
use App\Services\BannerLinkResolver;
use App\Services\StoreCatalogService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
        $reflection = new ReflectionClass($this->resolver);
        $sanitize = $reflection->getMethod('sanitizeExternalUrl');

        $this->assertSame('https://example.com/promo', $sanitize->invoke($this->resolver, 'https://example.com/promo'));
        $this->assertNull($sanitize->invoke($this->resolver, 'http://insecure.com'));
        $this->assertNull($sanitize->invoke($this->resolver, 'javascript:alert(1)'));
        $this->assertNull($sanitize->invoke($this->resolver, 'not-a-url'));
        $this->assertNull($sanitize->invoke($this->resolver, null));
        $this->assertNull($sanitize->invoke($this->resolver, 12345));
    }

    public function test_extract_target_ids(): void
    {
        $reflection = new ReflectionClass($this->resolver);
        $extract = $reflection->getMethod('extractTargetIds');

        $b1 = new Banner(['link_type' => BannerLinkType::Product, 'link_value' => '10']);
        $b2 = new Banner(['link_type' => BannerLinkType::Product, 'link_value' => '20']);
        $b3 = new Banner(['link_type' => BannerLinkType::Product, 'link_value' => '10']); // duplicate
        $b4 = new Banner(['link_type' => BannerLinkType::Category, 'link_value' => '5']);

        $banners = collect([$b1, $b2, $b3, $b4]);

        $productIds = $extract->invoke($this->resolver, $banners, BannerLinkType::Product);
        $this->assertSame([10, 20], $productIds);

        $categoryIds = $extract->invoke($this->resolver, $banners, BannerLinkType::Category);
        $this->assertSame([5], $categoryIds);

        $externalIds = $extract->invoke($this->resolver, $banners, BannerLinkType::External);
        $this->assertSame([], $externalIds);
    }
}
