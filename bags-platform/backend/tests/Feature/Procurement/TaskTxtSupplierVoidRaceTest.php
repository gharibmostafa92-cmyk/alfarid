<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TaskTxtSupplierVoidRaceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_voids_restore_supplier_balance_once(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['opening_balance' => 0, 'current_balance' => 0]);
        $payment = app(SupplierFinanceService::class)->createPayment([
            'supplier_id' => $supplier->id, 'amount' => 25.50, 'payment_method' => 'cash', 'payment_date' => '2026-04-01',
        ], $actor, 'void-once');
        $job = 'app(App\\Services\\Procurement\\SupplierFinanceService::class)->voidPayment(App\\Models\\SupplierPayment::findOrFail('
            .$payment->id.'), App\\Models\\User::findOrFail('.$actor->id.'), "race");';
        $this->race([$job, $job], ['OK', 'REJECTED']);
        $this->assertSame('0.00', $supplier->fresh()->current_balance);
        $this->assertSame(1, SupplierPayment::query()->whereNotNull('voided_at')->count());
    }

    /** @param  list<string>  $jobs */
    private function race(array $jobs, array $expected): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'supplier-race-');
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
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            $this->assertEqualsCanonicalizing($expected, $results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }
}
