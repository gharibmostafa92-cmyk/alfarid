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

    public function test_prune_keeps_a_guest_cart_refreshed_after_it_was_selected(): void
    {
        $stale = Cart::factory()->create();
        $refreshed = Cart::factory()->create();
        $stale->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();
        $refreshed->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();

        $armed = true;
        DB::listen(function ($query) use (&$armed, $refreshed): void {
            if (! $armed) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'carts') || ! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, 'updated_at')) {
                return;
            }
            $armed = false;
            DB::table('carts')->where('id', $refreshed->id)->update(['updated_at' => now()]);
        });

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('carts:prune-guests')->assertSuccessful();

        $select = collect(DB::getQueryLog())->first(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'carts') && str_contains($sql, 'updated_at');
        });
        $this->assertNotNull($select);
        $sql = strtolower((string) $select['query']);
        $this->assertStringContainsString('order by', $sql);
        $this->assertStringContainsString('updated_at', $sql);
        $this->assertStringContainsString('limit 500', $sql);
        $this->assertModelMissing($stale);
        $this->assertModelExists($refreshed);
    }

    public function test_guest_carts_have_an_updated_at_index_for_pruning(): void
    {
        $indexes = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'carts' AND indexname = 'carts_guest_updated_at_idx'");

        $this->assertNotEmpty($indexes);
        $this->assertStringContainsString('updated_at', strtolower((string) $indexes[0]->indexdef));
        $this->assertStringContainsString('customer_id is null', strtolower((string) $indexes[0]->indexdef));
    }
}
