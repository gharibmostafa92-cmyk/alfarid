<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class DocumentNumberingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    #[TestWith(['invoice', false])]
    #[TestWith(['invoice', true])]
    #[TestWith(['order', false])]
    #[TestWith(['pos_invoice', false])]
    #[TestWith(['movement', false])]
    public function test_fifty_processes_generate_unique_numbers_and_invoice_rollbacks_leave_no_gaps(string $type, bool $rollbackHalf): void
    {
        $this->seed(SettingSeeder::class);
        $base = (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value');
        $jobs = [];
        for ($i = 0; $i < 50; $i++) {
            $rollback = $rollbackHalf && $i % 2 === 0;
            $jobs[] = '$ready(); Illuminate\\Support\\Facades\\DB::beginTransaction();'
                .'$number = app(App\\Services\\Shared\\SettingService::class)->generateNextNumber('.var_export($type, true).');'
                .'Illuminate\\Support\\Facades\\DB::'.($rollback ? 'rollBack' : 'commit').'(); return '.($rollback ? 'null' : '$number').';';
        }
        // F16: every worker must resolve the service (wrong namespace fails here, not silently).
        // Rollback workers intentionally return null; all others must return a number.
        $raw = $this->concurrentJobs($jobs);
        $this->assertCount(50, $raw);
        $this->assertCount($rollbackHalf ? 25 : 0, array_filter($raw, fn ($value) => $value === null));
        $numbers = array_values(array_filter($raw, fn ($value) => $value !== null));
        $this->assertCount($rollbackHalf ? 25 : 50, $numbers);
        $this->assertCount(count($numbers), array_unique($numbers));
        if ($type === 'invoice') {
            $prefix = (string) app(SettingService::class)->get('invoice_prefix');
            $numeric = array_map(fn ($number) => (int) substr($number, strlen($prefix)), $numbers);
            sort($numeric);
            $this->assertSame(range($base, $base + count($numbers) - 1), $numeric);
            $this->assertSame($base + count($numbers), (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value'));
        }
    }

    #[TestWith(['SELECT inventory_movement_number()', 'SELECT inventory_movement_number(1000)'])]
    #[TestWith(["SELECT document_next_number('order')", "SELECT document_next_number('order', 1000)"])]
    public function test_administrative_number_sync_fails_when_an_allocation_holds_the_shared_lock(string $allocate, string $sync): void
    {
        $other = DB::build([...DB::connection()->getConfig(), 'name' => 'numbering_contention_test']);
        DB::beginTransaction();

        try {
            $this->assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $other->scalar('SELECT pg_backend_pid()'));
            DB::selectOne($allocate);
            $this->expectException(QueryException::class);
            $this->expectExceptionCode('55P03');
            $other->selectOne($sync);
        } finally {
            DB::rollBack();
            $other->disconnect();
        }
    }

    public function test_runtime_role_can_synchronize_numbering_sequences(): void
    {
        DB::transaction(function (): void {
            DB::statement('SET LOCAL ROLE alfarid_app');
            $this->assertSame(1000, (int) DB::scalar('SELECT inventory_movement_number(1000)'));
            $this->assertSame(1000, (int) DB::scalar("SELECT document_next_number('order', 1000)"));
        });
    }

    public function test_one_thousand_invoice_numbers_across_fifty_workers_are_unique_and_contiguous(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $prefix = (string) $service->get('invoice_prefix');
        $base = (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value');
        $job = '$ready(); $numbers = []; $service = app(App\\Services\\Shared\\SettingService::class);'
            .'for ($i = 0; $i < 20; $i++) { $numbers[] = $service->generateNextNumber("invoice"); } return $numbers;';
        $rawBatches = $this->concurrentJobs(array_fill(0, 50, $job));
        foreach ($rawBatches as $batch) {
            $this->assertIsArray($batch);
            $this->assertCount(20, $batch);
        }
        $numbers = array_merge(...$rawBatches);
        $this->assertCount(1000, $numbers);
        $this->assertCount(1000, array_unique($numbers));
        $numeric = array_map(fn ($number) => (int) substr($number, strlen($prefix)), $numbers);
        sort($numeric);
        $this->assertSame(range($base, $base + 999), $numeric);
        $this->assertSame($base + 1000, (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value'));
    }
}
