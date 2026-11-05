<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DomainRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceDomainRouterTest extends TestCase
{
    public static function financeSentences(): array
    {
        return [
            'product wallet stays catalog' => ['عايز محفظة جلد سودا', ['catalog']],
            'customer wallet balance is finance' => ['رصيد محفظة أحمد كام', ['finance']],
            'money transfer is finance' => ['حوّل 5000 من خزنة المعادي للرئيسية', ['finance']],
            'treasury balance is finance' => ['رصيد خزنة المعادي كام', ['finance']],
            'item balance stays inventory' => ['رصيد الصنف ده كام', ['inventory']],
            'cashier drawer is finance' => ['عايز أشوف درج الكاشير', ['finance']],
            'recording an expense is finance' => ['سجل مصروف كهربا 1200 على خزنة المعادي', ['finance']],
            'drawer shortage is finance' => ['عجز الدرج عندي 200 جنيه', ['finance']],
            'yesterday expenses is finance' => ['ليه مصروفات امبارح عالية', ['finance']],
            'liquidity mentions finance' => ['السيولة في فرع الدقي', ['finance', 'admin']],
            'wallet topup is finance plus customers' => ['شحن محفظة العميل أحمد بـ 500', ['finance', 'customers']],
            'refund lookup is finance' => ['استرداد طلب رقم ORD-000123', ['finance']],
            'electricity bill is finance' => ['فاتورة الكهربا جت كام', ['finance']],
            'moving goods stays inventory' => ['انقل 30 قطعة من المخزن الرئيسي للدقي', ['admin', 'inventory']],
            'drawer balance is finance' => ['رصيد الدرج كام', ['finance']],
            'buying a bag stays catalog' => ['عايز أشتري شنطة جلد', ['catalog']],
            'pending approvals is finance' => ['التحويلات اللي مستنية اعتمادي', ['finance']],
        ];
    }

    #[DataProvider('financeSentences')]
    public function test_finance_routing(string $text, array $expected): void
    {
        $domains = app(DomainRouter::class)->route($text);

        $this->assertNotNull($domains);
        $this->assertEqualsCanonicalizing($expected, $domains, "«{$text}»");
    }

    public function test_goods_transfer_stays_inventory_without_finance_or_catalog(): void
    {
        $domains = app(DomainRouter::class)->route('حوّل 20 شنطة لفرع المعادي');

        $this->assertContains('inventory', $domains ?? []);
        $this->assertNotContains('finance', $domains ?? []);
        $this->assertNotContains('catalog', $domains ?? []);
    }

    public function test_money_transfer_with_amount_routes_to_finance_not_inventory(): void
    {
        $domains = app(DomainRouter::class)->route('حول 3000 جنيه لخزنة الفرع');

        $this->assertContains('finance', $domains ?? []);
        $this->assertNotContains('inventory', $domains ?? []);
    }

    public function test_stocktake_routing_is_untouched(): void
    {
        $domains = app(DomainRouter::class)->route('عايز جرد فرع المعادي');

        $this->assertContains('inventory', $domains ?? []);
        $this->assertNotContains('finance', $domains ?? []);
    }

    public function test_finance_mentions_route_to_finance(): void
    {
        $router = app(DomainRouter::class);
        $this->assertContains('finance', $router->route('التفاصيل', [['entity' => 'treasury', 'id' => 1]]) ?? []);
        $this->assertContains('finance', $router->route('التفاصيل', [['entity' => 'treasury_transfer', 'id' => 1]]) ?? []);
        $this->assertContains('finance', $router->route('التفاصيل', [['entity' => 'expense', 'id' => 1]]) ?? []);
    }

    public function test_customer_mention_with_wallet_routes_to_finance_and_customers(): void
    {
        $domains = app(DomainRouter::class)->route('اشحن المحفظة', [['entity' => 'customer', 'id' => 3]]);

        $this->assertContains('finance', $domains ?? []);
        $this->assertContains('customers', $domains ?? []);
    }
}
