<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task6Slice4RegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'phone' => '01022223333',
            'credit_limit' => 100000,
            'whatsapp_opt_in_at' => now(),
        ]);
        CustomerChannel::forceCreate([
            'customer_id' => $this->trader->id,
            'phone' => '+201022223333',
            'provider' => 'whatsapp_cloud',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $branch = Branch::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'status' => 'published',
            'sales_channel' => 'both',
        ]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'WA-SKU-1',
            'is_active' => true,
            'wholesale_price' => 120,
        ]);
        StockLevel::forceCreate([
            'branch_id' => $branch->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 100,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
        ]);
    }

    public function test_f09_customer_statement_template_exists_and_monthly_statements_command_succeeds(): void
    {
        $template = DB::table('message_templates')
            ->where('name', 'customer_statement')
            ->where('language', 'ar')
            ->first();

        $this->assertNotNull($template, 'customer_statement template must exist in message_templates.');
        $this->assertSame('approved', $template->status);

        $this->artisan('wholesale:monthly-statements')->assertExitCode(0);
        $this->assertDatabaseHas('outbound_messages', [
            'customer_id' => $this->trader->id,
            'template' => 'customer_statement',
        ]);
    }

    public function test_f09_whatsapp_assistant_handles_cart_and_quote_actions_after_order_lookup(): void
    {
        $assistant = app(CustomerWholesaleAssistant::class);

        // 1. Lookup items via order text.
        $lookupReply = $assistant->reply($this->trader, 'عايز 5 WA-SKU-1', null, '+201022223333');
        $this->assertStringContainsString('لقيت:', $lookupReply);

        // 2. Click "سلة" -> must add to cart, not fallback to default help message.
        $cartReply = $assistant->reply($this->trader, 'سلة', 'سلة', '+201022223333');
        $this->assertStringContainsString('السلة', $cartReply);
        $this->assertDatabaseHas('cart_items', [
            'product_variant_id' => $this->variant->id,
        ]);

        // 3. Repeat lookup and click "عرض سعر" -> must create and submit a quotation.
        $assistant->reply($this->trader, 'عايز 3 WA-SKU-1', null, '+201022223333');
        $quoteReply = $assistant->reply($this->trader, 'عرض سعر', 'عرض سعر', '+201022223333');
        $this->assertStringContainsString('عرض السعر', $quoteReply);
        $this->assertSame(1, Quotation::query()->where('customer_id', $this->trader->id)->count());
    }
}
