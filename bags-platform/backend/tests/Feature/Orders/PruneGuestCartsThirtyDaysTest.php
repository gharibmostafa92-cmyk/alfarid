<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneGuestCartsThirtyDaysTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_carts_older_than_thirty_days_are_pruned_and_recent_carts_stay(): void
    {
        $customer = Customer::factory()->create();
        $variant = ProductVariant::factory()->create();

        $stale = Cart::factory()->create();
        $stale->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();
        CartItem::factory()->create([
            'cart_id' => $stale->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);

        $recent = Cart::factory()->create();
        $recent->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        $account = Cart::factory()->create(['customer_id' => $customer->id]);
        $account->forceFill(['updated_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('carts:prune-guests')->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertSame(0, CartItem::query()->where('cart_id', $stale->id)->count());
        $this->assertModelExists($recent);
        $this->assertModelExists($account);
    }

    public function test_guest_carts_have_an_updated_at_index_for_pruning(): void
    {
        $indexes = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'carts' AND indexname = 'carts_guest_updated_at_idx'");

        $this->assertNotEmpty($indexes);
        $this->assertStringContainsString('updated_at', strtolower((string) $indexes[0]->indexdef));
        $this->assertStringContainsString('customer_id is null', strtolower((string) $indexes[0]->indexdef));
    }
}
