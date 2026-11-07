<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DomainRouter;
use Tests\TestCase;

class ShippingStorefrontDomainRouterTest extends TestCase
{
    public function test_shipping_and_storefront_phrases_stay_in_their_domains(): void
    {
        $router = app(DomainRouter::class);

        $wallet = $router->route('شحن المحفظة بمية جنيه');
        $this->assertContains('finance', $wallet ?? []);
        $this->assertNotContains('shipping', $wallet ?? []);
        $this->assertNotContains('inventory', $wallet ?? []);

        $transfer = $router->route('التحويل اتشحن من المخزن');
        $this->assertContains('inventory', $transfer ?? []);
        $this->assertNotContains('shipping', $transfer ?? []);

        $coupon = $router->route('اعمل كوبون شحن مجاني');
        $this->assertContains('pricing', $coupon ?? []);
        $this->assertNotContains('shipping', $coupon ?? []);
        $this->assertNotContains('inventory', $coupon ?? []);

        $delivery = $router->route('تكلفة توصيل القاهرة');
        $this->assertSame(['shipping'], $delivery);

        $home = $router->route('رتب بانر الصفحة الرئيسية');
        $this->assertContains('storefront', $home ?? []);
        $this->assertNotContains('shipping', $home ?? []);
    }
}
