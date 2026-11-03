<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * task.txt §4 (parallel decreases)، §5d (parallel same key)، §8b (parallel
 * approves)، §16b (parallel same scan)، §19 (lock timeout → 409)، §20
 * (reversed order, no deadlock).
 *
 * Workers run in separate OS processes against the same test database
 * (DatabaseMigrations: no per-test transaction, everything committed),
 * synchronized through a file barrier like InventoryConcurrencyTest.
 */
class TaskTxtInventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
    }

    protected function tearDown(): void
    {
        // Workers commit real ledger entries; reset before rolling back enum migrations.
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    // ---------- §4: two parallel decreases on on_hand=5 ----------

    public function test_parallel_decreases_allow_only_one(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        app(\App\Services\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 5, 'created_by' => $this->staff->id,
        ]);

        $payload = var_export([
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'decrease', 'quantity' => 3]],
        ], true);
        $job = 'app(App\Services\InventoryService::class)->applyAdjustments('.$payload.', '.$this->staff->id.');';
        $this->race([$job, $job], ['OK', 'REJECTED']);

        $this->assertSame('2.0000', StockLevel::firstOrFail()->on_hand);
        $this->artisan('inventory:reconcile', ['--all-branches' => true])->assertSuccessful();
    }

    // ---------- §5d: same key in two parallel requests executes once ----------

    public function test_parallel_same_key_adjustment_executes_once(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        app(\App\Services\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 10, 'created_by' => $this->staff->id,
        ]);

        $payload = var_export([
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 5]],
        ], true);
        $job = 'app(App\Services\InventoryService::class)->applyAdjustments('.$payload.', '.$this->staff->id.', "parallel-key-5d");';
        $this->race([$job, $job], ['OK', 'OK']);

        $this->assertSame('15.0000', StockLevel::firstOrFail()->on_hand);
        $this->assertSame(2, StockMovement::query()->count());
    }

    // ---------- §8b: parallel approves with the same version ----------

    public function test_parallel_approves_with_same_version_allow_only_one(): void
    {
        $variant = ProductVariant::factory()->create();
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        app(\App\Services\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $from->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 10, 'created_by' => $this->staff->id,
        ]);
        $transfer = app(\App\Services\TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 4]],
        ], $this->staff->id);

        $job = 'app(App\Services\TransferService::class)->approve(App\Models\StockTransfer::findOrFail('.$transfer->id.'), [], '.$this->staff->id.', null, 1);';
        $this->race([$job, $job], ['OK', 'REJECTED']);

        $level = StockLevel::query()->where('product_variant_id', $variant->id)->where('branch_id', $from->id)->firstOrFail();
        $this->assertSame('4.0000', $level->reserved);
        $this->assertSame(1, StockMovement::query()->where('type', 'reserve')->count());
    }

    // ---------- §16b: parallel same scan counts once ----------

    public function test_parallel_same_scan_counts_once(): void
    {
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $take = app(\App\Services\StocktakeService::class)->create(['branch_id' => $branch->id], $this->staff->id);
        $scanId = '11111111-2222-3333-4444-555555555555';

        $job = 'app(App\Services\StocktakeService::class)->incrementItemCount(App\Models\Stocktake::findOrFail('.$take->id.'), '.$variant->id.', 1, '.$this->staff->id.', '.var_export($scanId, true).');';
        $this->race([$job, $job], ['OK', 'OK']);

        $this->assertSame('1.0000', $take->items()->firstOrFail()->counted_qty);
        $this->assertSame(1, \DB::table('stocktake_scans')->where('stocktake_id', $take->id)->count());
    }

    // ---------- §19: lock timeout → retryable 409, no writes ----------

    public function test_lock_timeout_returns_retryable_conflict_without_writes(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        app(\App\Services\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 5, 'created_by' => $this->staff->id,
        ]);

        $key = var_export(['branch_id' => $branch->id, 'product_variant_id' => $variant->id], true);
        $holder = <<<'PHP'
Illuminate\Support\Facades\DB::transaction(function () {
PHP;
        $holder .= 'Illuminate\Support\Facades\DB::table("stock_levels")->where('.$key.')->lockForUpdate()->first();';
        $holder .= <<<'PHP'
    $prefix = getenv('INVENTORY_START_FILE');
    touch($prefix.'.left');
    $deadline = microtime(true) + 12;
    while (!is_file($prefix.'.right')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Contender did not finish'); }
        usleep(10000);
    }
});
PHP;
        $data = var_export([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 1, 'created_by' => $this->staff->id,
        ], true);
        $contender = <<<'PHP'
$prefix = getenv('INVENTORY_START_FILE');
$deadline = microtime(true) + 10;
while (!is_file($prefix.'.left')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Holder did not lock'); }
    usleep(10000);
}
try {
PHP;
        $contender .= 'app(App\Services\InventoryService::class)->recordMovement('.$data.');';
        $contender .= <<<'PHP'
    touch($prefix.'.right');
    throw new RuntimeException('Expected a retryable conflict');
} catch (Throwable $exception) {
    touch($prefix.'.right');
    if (! ($exception instanceof App\Exceptions\RetryableConflict) || $exception->getStatusCode() !== 409) {
        throw $exception;
    }
    throw $exception;
}
PHP;
        $this->race([$holder, $contender], ['OK', 'REJECTED']);

        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('5.0000', StockLevel::firstOrFail()->on_hand);
    }

    public function test_production_receipt_lock_timeout_returns_retryable_conflict(): void
    {
        $variant = ProductVariant::factory()->create();
        $branch = Branch::factory()->create();
        app(\App\Services\InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
            'quantity' => 5, 'created_by' => $this->staff->id,
        ]);

        $key = var_export(['branch_id' => $branch->id, 'product_variant_id' => $variant->id], true);
        $holder = <<<'PHP'
Illuminate\Support\Facades\DB::transaction(function () {
PHP;
        $holder .= 'Illuminate\Support\Facades\DB::table("stock_levels")->where('.$key.')->lockForUpdate()->first();';
        $holder .= <<<'PHP'
    $prefix = getenv('INVENTORY_START_FILE');
    touch($prefix.'.left');
    $deadline = microtime(true) + 12;
    while (!is_file($prefix.'.right')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Contender did not finish'); }
        usleep(10000);
    }
});
PHP;
        $data = var_export([
            'branch_id' => $branch->id, 'batch_number' => 'RACE-19',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '2.0000']],
        ], true);
        $contender = <<<'PHP'
$prefix = getenv('INVENTORY_START_FILE');
$deadline = microtime(true) + 10;
while (!is_file($prefix.'.left')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Holder did not lock'); }
    usleep(10000);
}
try {
PHP;
        $contender .= 'app(App\Services\ProductionReceiptService::class)->receive('.$data.', '.$this->staff->id.', "race-19-key");';
        $contender .= <<<'PHP'
    touch($prefix.'.right');
    throw new RuntimeException('Expected a retryable conflict');
} catch (Throwable $exception) {
    touch($prefix.'.right');
    if (! ($exception instanceof App\Exceptions\RetryableConflict) || $exception->getStatusCode() !== 409) {
        throw $exception;
    }
    throw $exception;
}
PHP;
        $this->race([$holder, $contender], ['OK', 'REJECTED']);

        $this->assertSame(0, \App\Models\ProductionReceipt::query()->count());
        $this->assertSame('5.0000', StockLevel::firstOrFail()->on_hand);
    }

    // ---------- §20: reversed item order does not deadlock ----------

    public function test_reversed_item_orders_do_not_deadlock(): void
    {
        $variants = ProductVariant::factory()->count(2)->create();
        $branch = Branch::factory()->create();
        $items = $variants->map(fn ($variant) => ['product_variant_id' => $variant->id, 'quantity' => 1, 'adjustment_type' => 'increase'])->all();
        $jobs = [];
        foreach ([$items, array_reverse($items)] as $order) {
            $jobs[] = 'app(App\Services\InventoryService::class)->applyAdjustments('.var_export(['branch_id' => $branch->id, 'items' => $order], true).', '.$this->staff->id.');';
        }
        // Global key ordering serializes the two workers; neither deadlocks (no 40P01).
        $this->race($jobs, ['OK', 'OK']);
        $this->artisan('inventory:reconcile', ['--all-branches' => true])->assertSuccessful();
    }

    // ---------- harness ----------

    /**
     * @param list<string> $jobs
     * @param list<string> $expected
     */
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
} catch (App\Exceptions\RetryableConflict $exception) {
    echo "REJECTED\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment, timeout: 20);
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
