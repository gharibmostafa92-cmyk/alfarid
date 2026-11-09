<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\OutboundMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\Wholesale\QuotationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaskTxtRepriceAfterUndoTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_repricing_after_undo_emits_a_second_priced_notification(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
            'whatsapp_opt_in_at' => now(),
        ]);
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);

        $service = app(QuotationService::class);
        $quotation = $service->create($customer, [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 2]],
        ]);
        $service->submit($quotation->fresh());

        $item = $quotation->fresh()->items()->firstOrFail();
        $service->price($quotation->fresh(), [
            'items' => [['id' => $item->id, 'offered_unit_price' => 90]],
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        // AI undo restores the pre-pricing snapshot.
        $service->restorePricedSnapshot($quotation->fresh(), [
            'items' => [['id' => $item->id, 'offered_unit_price' => 100]],
            'valid_until' => null,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]);
        $this->assertSame(QuotationStatus::Requested, $quotation->fresh()->status);

        // Repricing must notify again: the trader needs the new prices.
        $service->price($quotation->fresh(), [
            'items' => [['id' => $item->id, 'offered_unit_price' => 85]],
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertSame(
            2,
            OutboundMessage::query()->where('template', 'quotation_priced')->count(),
            'Each pricing must emit its own quotation_priced notification.'
        );
    }
}
