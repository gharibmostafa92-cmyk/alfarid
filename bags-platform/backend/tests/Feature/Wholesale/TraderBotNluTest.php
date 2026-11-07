<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TraderBotNluTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function verifiedTrader(): Customer
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 50000,
        ]);
        CustomerChannel::query()->create([
            'customer_id' => $customer->id,
            'phone' => $customer->phone,
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        return $customer;
    }

    private function stockedVariant(string $productName, string $sku, float $price): ProductVariant
    {
        $product = Product::factory()->create(['status' => 'published', 'name_ar' => $productName]);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['sku' => $sku, 'retail_price' => $price, 'is_active' => true]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 1000,
        ]);

        return $variant->fresh();
    }

    public function test_order_text_parses_quantities_and_suggests_cart_or_quote(): void
    {
        $customer = $this->verifiedTrader();
        $this->stockedVariant('شنطة كروس بني', 'CROSS-1', 100);
        $this->stockedVariant('شنطة ظهر أسود', 'BACK-1', 200);

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'عايز 50 كروس بني و٣ دست ظهر');

        $this->assertStringContainsString('50', $reply);
        $this->assertStringContainsString('36', $reply);
        $this->assertStringContainsString('سلة', $reply);
    }

    public function test_ambiguous_product_lists_candidates_to_choose_from(): void
    {
        $customer = $this->verifiedTrader();
        $this->stockedVariant('شنطة كروس بني', 'CROSS-1', 100);
        $this->stockedVariant('شنطة ظهر أسود', 'BACK-1', 200);

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'عايز 10 شنطة');

        $this->assertStringContainsString('اختار', $reply);
        $this->assertStringContainsString('CROSS-1', $reply);
        $this->assertStringContainsString('BACK-1', $reply);
    }
}
