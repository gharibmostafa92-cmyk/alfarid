<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
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
                .'$number = app(App\\Services\\SettingService::class)->generateNextNumber('.var_export($type, true).');'
                .'Illuminate\\Support\\Facades\\DB::'.($rollback ? 'rollBack' : 'commit').'(); return '.($rollback ? 'null' : '$number').';';
        }
        $numbers = array_values(array_filter($this->concurrentJobs($jobs), fn ($value) => $value !== null));
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

    public function test_one_thousand_invoice_numbers_across_fifty_workers_are_unique_and_contiguous(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $prefix = (string) $service->get('invoice_prefix');
        $base = (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value');
        $job = '$ready(); $numbers = []; $service = app(App\\Services\\SettingService::class);'
            .'for ($i = 0; $i < 20; $i++) { $numbers[] = $service->generateNextNumber("invoice"); } return $numbers;';
        $numbers = array_merge(...$this->concurrentJobs(array_fill(0, 50, $job)));
        $this->assertCount(1000, $numbers);
        $this->assertCount(1000, array_unique($numbers));
        $numeric = array_map(fn ($number) => (int) substr($number, strlen($prefix)), $numbers);
        sort($numeric);
        $this->assertSame(range($base, $base + 999), $numeric);
        $this->assertSame($base + 1000, (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value'));
    }
}
