<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Wholesale\QuotationStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TaskTxtQuotationDraftDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function createDraftQuotation(): Quotation
    {
        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
        ])->assertCreated()->json('data.id');

        return Quotation::query()->findOrFail($id);
    }

    public function test_delete_removes_draft_quotation_and_its_items(): void
    {
        $quotation = $this->createDraftQuotation();
        $itemIds = $quotation->items()->pluck('id')->all();
        $this->assertNotEmpty($itemIds);

        $this->asTrader()->deleteJson("/api/v1/wholesale/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف مسودة عرض السعر.');

        $this->assertDatabaseMissing('quotations', ['id' => $quotation->id]);
        foreach ($itemIds as $itemId) {
            $this->assertDatabaseMissing('quotation_items', ['id' => $itemId]);
        }
    }

    public function test_delete_rejects_non_draft_quotation_and_keeps_it(): void
    {
        $quotation = $this->createDraftQuotation();
        $quotation->update(['status' => QuotationStatus::Requested]);

        $this->asTrader()->deleteJson("/api/v1/wholesale/quotations/{$quotation->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('quotations', ['id' => $quotation->id]);
        $this->assertSame(1, Quotation::query()->whereKey($quotation->id)->count());
    }
}
