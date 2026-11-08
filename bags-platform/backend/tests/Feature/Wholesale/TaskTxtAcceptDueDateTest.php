<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\QuotationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TaskTxtAcceptDueDateTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_accepted_quotation_order_carries_due_at_from_payment_terms(): void
    {
        $trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
            'payment_terms_days' => 30,
        ]);
        $token = $trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 100,
        ]);

        Auth::forgetGuards();
        $id = $this->withToken($token)->postJson('/api/v1/wholesale/quotations', [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 2]],
        ])->assertCreated()->json('data.id');

        Quotation::query()->whereKey($id)->update(['status' => QuotationStatus::PricedByAdmin]);

        // The due date is written by the confirm transition inside accept(),
        // which is what the overdue/reminder/briefing commands read.
        $response = $this->withToken($token)
            ->postJson("/api/v1/wholesale/quotations/{$id}/accept")->assertOk();
        $order = Order::query()->findOrFail($response->json('data.converted_order_id'));

        $this->assertNotNull($order->due_at);
        $this->assertSame(now()->addDays(30)->toDateString(), $order->due_at->toDateString());
    }
}
