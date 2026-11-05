<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DomainRouter;
use Tests\TestCase;

class PricingDomainRouterTest extends TestCase
{
    public function test_pricing_keywords_route_to_pricing_domain(): void
    {
        $router = app(DomainRouter::class);

        $this->assertContains('pricing', $router->route('عايز كوبون خصم 15% لآخر الشهر') ?? []);
        $this->assertContains('pricing', $router->route('زود قائمة أسعار الموزعين 5%') ?? []);
    }

    public function test_price_question_matches_both_catalog_and_pricing_domains(): void
    {
        $router = app(DomainRouter::class);

        $domains = $router->route('ليه السعر طالع كده للعميل ده؟');
        $this->assertNotNull($domains);
        $this->assertContains('catalog', $domains);
        $this->assertContains('pricing', $domains);
    }

    public function test_free_shipping_matches_inventory_and_pricing(): void
    {
        $router = app(DomainRouter::class);

        $domains = $router->route('اعمل شحن مجاني للطلبات فوق 500');
        $this->assertNotNull($domains);
        $this->assertContains('inventory', $domains);
        $this->assertContains('pricing', $domains);
    }

    public function test_pure_catalog_message_does_not_match_pricing(): void
    {
        $router = app(DomainRouter::class);

        $domains = $router->route('انشر المنتج ده في الكتالوج');
        $this->assertNotNull($domains);
        $this->assertContains('catalog', $domains);
        $this->assertNotContains('pricing', $domains);
    }
}
