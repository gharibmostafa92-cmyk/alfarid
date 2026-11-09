<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\QuotationStatus;
use App\Events\Wholesale\QuotationExpired;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskTxtExpireCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function pricedQuotation(?string $validUntil): Quotation
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);

        $quotation = Quotation::factory()->create([
            'customer_id' => $customer->id,
            'status' => QuotationStatus::PricedByAdmin,
            'valid_until' => $validUntil,
        ]);
        $quotation->items()->create([
            'product_variant_id' => $variant->id,
            'qty' => 1,
            'offered_unit_price' => 100,
            'line_total' => 100,
        ]);

        return $quotation->fresh();
    }

    public function test_dry_run_counts_without_changing_anything(): void
    {
        $past = $this->pricedQuotation(now()->subDay()->toDateString());
        $future = $this->pricedQuotation(now()->addDay()->toDateString());
        $dateless = $this->pricedQuotation(null);

        Event::fake([QuotationExpired::class]);

        $this->artisan('quotations:expire', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('1');

        $this->assertSame(QuotationStatus::PricedByAdmin, $past->fresh()->status);
        $this->assertSame(QuotationStatus::PricedByAdmin, $future->fresh()->status);
        $this->assertSame(QuotationStatus::PricedByAdmin, $dateless->fresh()->status);
        Event::assertNotDispatched(QuotationExpired::class);
    }

    public function test_run_expires_only_past_priced_and_second_run_is_noop(): void
    {
        $past = $this->pricedQuotation(now()->subDay()->toDateString());
        $future = $this->pricedQuotation(now()->addDay()->toDateString());
        $dateless = $this->pricedQuotation(null);

        Event::fake([QuotationExpired::class]);

        $this->artisan('quotations:expire')->assertSuccessful()->expectsOutputToContain('1');

        $this->assertSame(QuotationStatus::Expired, $past->fresh()->status);
        $this->assertSame(QuotationStatus::PricedByAdmin, $future->fresh()->status);
        $this->assertSame(QuotationStatus::PricedByAdmin, $dateless->fresh()->status);
        Event::assertDispatched(QuotationExpired::class, 1);

        $this->artisan('quotations:expire')->assertSuccessful()->expectsOutputToContain('0');
        $this->assertSame(QuotationStatus::Expired, $past->fresh()->status);
    }
}
