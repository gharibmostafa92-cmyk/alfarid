<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DomainRouter;
use Tests\TestCase;

class WholesaleDomainRouterTest extends TestCase
{
    public function test_treasury_transfer_to_a_safe_stays_finance(): void
    {
        $domains = app(DomainRouter::class)->route('حول 3000 جنيه من الخزنة لحساب البنك');

        $this->assertContains('finance', $domains ?? []);
        $this->assertNotContains('wholesale', $domains ?? []);
    }
}
