<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use Tests\TestCase;

class Task7RouteConstraintsTest extends TestCase
{
    public function test_cart_item_routes_reject_non_numeric_identifiers(): void
    {
        $this->putJson('/api/v1/store/cart/items/abc')->assertNotFound();
        $this->deleteJson('/api/v1/store/cart/items/abc')->assertNotFound();
    }

    public function test_cancel_route_rejects_non_numeric_order_identifier(): void
    {
        $this->postJson('/api/v1/store/account/orders/abc/cancel')->assertNotFound();
    }
}
