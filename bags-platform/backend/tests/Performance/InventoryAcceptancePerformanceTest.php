<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StocktakeService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryAcceptancePerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_and_approving_500_counted_items_stay_within_five_seconds_each(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $variants = ProductVariant::factory()->count(500)->create(['product_id' => $product->id, 'is_active' => true]);
        $items = $variants->map(fn ($v) => ['product_variant_id' => $v->id, 'counted_qty' => 1])->all();
        $service = app(StocktakeService::class);
        $started = microtime(true);
        $stocktake = $service->create(['branch_id' => $branch->id, 'items' => $items], $actor->id, 'performance-500');
        $createMs = (microtime(true) - $started) * 1000;
        $started = microtime(true);
        $approved = $service->approve($stocktake, $actor->id);
        $approveMs = (microtime(true) - $started) * 1000;
        file_put_contents(storage_path('logs/inventory-performance-acceptance.json'), json_encode([
            'database' => DB::getDriverName(), 'items' => 500, 'create_ms' => round($createMs, 2),
            'approve_ms' => round($approveMs, 2), 'limit_ms' => 5000,
            'scope' => 'local service benchmark; excludes HTTP, queue drain and production load',
        ], JSON_PRETTY_PRINT));
        $this->assertSame('approved', $approved->status->value);
        $this->assertSame(500, DB::table('stock_movements')->where('reference_id', $stocktake->id)->count());
        $this->assertLessThan(5000, $createMs, 'Creating a 500-item stocktake exceeded the accepted 5-second budget.');
        $this->assertLessThan(5000, $approveMs, 'Approving a 500-item stocktake exceeded the accepted 5-second budget.');
    }
}
