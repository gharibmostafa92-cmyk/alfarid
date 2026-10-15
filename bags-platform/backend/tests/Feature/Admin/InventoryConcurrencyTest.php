<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SettingService;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        // مشغّل مركز رئيسي: بلا فرع لكن بامتياز صريح لكل الفروع (fail-closed خلاف ذلك)
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
    }

    protected function tearDown(): void
    {
        // Workers commit real ledger entries. Discard test fixtures before rolling back enum migrations.
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_first_movements_create_exactly_one_stock_row_under_concurrency(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        $data = $this->data($variant, $branch);
        $operation = 'app(App\Services\InventoryService::class)->recordMovement('.var_export($data, true).');';
        $this->race([$operation, $operation]);
        $this->assertSame(1, StockLevel::count());
        $this->assertSame('2.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame(2, StockMovement::distinct()->count('reference_number'));
    }

    public function test_opposite_multi_item_orders_do_not_deadlock(): void
    {
        $variants = ProductVariant::factory()->count(2)->create();
        $branch = Branch::factory()->create();
        $items = $variants->map(fn ($variant) => ['product_variant_id' => $variant->id, 'quantity' => 1, 'adjustment_type' => 'increase'])->all();
        $jobs = [];
        foreach ([$items, array_reverse($items)] as $order) {
            $jobs[] = 'app(App\Services\InventoryService::class)->applyAdjustments('.var_export(['branch_id' => $branch->id, 'items' => $order], true).', '.$this->staff->id.');';
        }
        $this->race($jobs);
        $this->assertSame(['2.0000', '2.0000'], StockLevel::orderBy('product_variant_id')->pluck('on_hand')->all());
    }

    public function test_unrelated_movements_can_reach_ledger_insert_at_the_same_time(): void
    {
        $jobs = [];
        foreach (['left' => 'right', 'right' => 'left'] as $own => $other) {
            $data = $this->data(ProductVariant::factory()->create(), Branch::factory()->create());
            $hook = 'App\Models\StockMovement::creating(function () { $prefix = getenv("INVENTORY_START_FILE"); touch($prefix.'.var_export('.'.$own, true).'); $deadline = microtime(true) + 5; while (!is_file($prefix.'.var_export('.'.$other, true).')) { if (microtime(true) > $deadline) { throw new RuntimeException("Movement numbering serialized unrelated stock rows"); } usleep(10000); } });';
            $jobs[] = $hook.'app(App\Services\InventoryService::class)->recordMovement('.var_export($data, true).');';
        }
        $this->race($jobs);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_opposing_shipments_lock_both_branches_in_the_same_order(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        foreach ([$from, $to] as $branch) {
            app(InventoryService::class)->recordMovement([...$this->data($variant, $branch), 'quantity' => 10]);
        }
        $jobs = [];
        foreach ([[$from, $to], [$to, $from]] as [$source, $destination]) {
            $transfer = app(TransferService::class)->create(['from_branch_id' => $source->id, 'to_branch_id' => $destination->id,
                'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
            ], $this->staff->id);
            app(TransferService::class)->approve($transfer, [], $this->staff->id);
            $jobs[] = 'app(App\Services\TransferService::class)->ship(App\Models\StockTransfer::findOrFail('.$transfer->id.'), [], '.$this->staff->id.');';
        }
        $this->race($jobs);
        foreach (StockLevel::all() as $stock) {
            $this->assertSame('8.0000', $stock->on_hand);
            $this->assertSame('0.0000', $stock->reserved);
            $this->assertSame('2.0000', $stock->in_transit);
        }
    }

    public function test_concurrent_stocktakes_cannot_double_apply_the_same_difference(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        app(InventoryService::class)->recordMovement([...$this->data($variant, $branch), 'quantity' => 10]);
        $jobs = [];
        foreach ([1, 2] as $iteration) {
            $stocktake = app(StocktakeService::class)->create(['branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 7]],
            ], $this->staff->id);
            $jobs[] = 'app(App\Services\StocktakeService::class)->approve(App\Models\Stocktake::findOrFail('.$stocktake->id.'), '.$this->staff->id.');';
        }
        $this->race($jobs, ['OK', 'REJECTED']);
        $this->assertSame('7.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame(1, StockMovement::where('type', 'stocktake_loss')->count());
    }

    public function test_concurrent_duplicate_price_tiers_have_a_business_error(): void
    {
        $variant = ProductVariant::factory()->create();
        $list = PriceList::create(['name' => 'Retail', 'type' => 'retail']);
        $operation = 'app(App\Services\PriceListService::class)->createItem(App\Models\PriceList::findOrFail('.$list->id.'), '.var_export(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 10], true).');';
        $this->race([$operation, $operation], ['OK', 'REJECTED']);
        $this->assertSame(1, $list->items()->count());
    }

    public function test_waiting_for_an_uncommitted_first_stock_row_returns_409(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $key = var_export(['branch_id' => $branch->id, 'product_variant_id' => $variant->id], true);
        $holder = <<<'PHP'
Illuminate\Support\Facades\DB::transaction(function () {
PHP;
        $holder .= 'Illuminate\Support\Facades\DB::table("stock_levels")->insert('.$key.');';
        $holder .= <<<'PHP'
    $prefix = getenv('INVENTORY_START_FILE');
    touch($prefix.'.left');
    $deadline = microtime(true) + 10;
    while (!is_file($prefix.'.right')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Contender did not finish'); }
        usleep(10000);
    }
});
PHP;
        $contender = <<<'PHP'
$prefix = getenv('INVENTORY_START_FILE');
$deadline = microtime(true) + 10;
while (!is_file($prefix.'.left')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Holder did not insert'); }
    usleep(10000);
}
try {
PHP;
        $contender .= 'app(App\Services\InventoryService::class)->recordMovement('.var_export($this->data($variant, $branch), true).');';
        $contender .= <<<'PHP'
    touch($prefix.'.right');
    throw new RuntimeException('Expected a lock conflict');
} catch (App\Exceptions\BusinessException $exception) {
    touch($prefix.'.right');
    if ($exception->status !== 409) { throw new RuntimeException('Expected status 409'); }
    throw $exception;
}
PHP;
        $this->race([$holder, $contender], ['OK', 'REJECTED']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.0000', StockLevel::firstOrFail()->on_hand);
    }

    public function test_movement_counter_updates_do_not_hold_a_sequence_lock_until_commit(): void
    {
        $data = $this->data(ProductVariant::factory()->create(), Branch::factory()->create());
        $holder = <<<'PHP'
Illuminate\Support\Facades\DB::transaction(function () {
    app(App\Services\SettingService::class)->updateBatch(['movement_next_number' => 20000]);
    $prefix = getenv('INVENTORY_START_FILE');
    touch($prefix.'.left');
    $deadline = microtime(true) + 8;
    while (!is_file($prefix.'.right')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Counter change blocked inventory'); }
        usleep(10000);
    }
});
PHP;
        $writer = <<<'PHP'
$prefix = getenv('INVENTORY_START_FILE');
$deadline = microtime(true) + 8;
while (!is_file($prefix.'.left')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Counter update did not start'); }
    usleep(10000);
}
PHP;
        $writer .= 'app(App\Services\InventoryService::class)->recordMovement('.var_export($data, true).'); touch($prefix.".right");';
        $this->race([$holder, $writer]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertStringEndsWith('20000', app(SettingService::class)->generateNextNumber('movement'));
    }

    private function data(ProductVariant $variant, Branch $branch): array
    {
        return ['product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 1, 'created_by' => $this->staff->id];
    }

    private function race(array $jobs, array $expected = ['OK', 'OK']): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'inventory-race-');
        unlink($barrier);
        $db = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'INVENTORY_START_FILE' => $barrier];
        $processes = [];
        try {
            foreach ($jobs as $operation) {
                $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '7s'");
echo "READY\n";
$deadline = microtime(true) + 12;
while (!is_file(getenv('INVENTORY_START_FILE'))) {
    if (microtime(true) > $deadline) { exit(4); }
    usleep(10000);
}
try {
PHP;
                $script .= $operation;
                $script .= <<<'PHP'
echo "OK\n";
} catch (App\Exceptions\BusinessException $exception) {
    echo "REJECTED\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment, timeout: 15);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            $this->assertEqualsCanonicalizing($expected, $results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, $barrier.'.left', $barrier.'.right'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
