<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskConcurrentIndexRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_notification_migration_rebuilds_an_invalid_concurrent_index(): void
    {
        $migration = require database_path('migrations/2026_10_29_000010_notification_query_indexes.php');
        $migration->down();
        foreach ([1, 2] as $id) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'checklist', 'notifiable_type' => 'same-type', 'notifiable_id' => $id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['notifications_recipient_created_idx', 'notifications_unread_recipient_created_idx'] as $index) {
            try {
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.$index.' ON notifications (notifiable_type)');
                $this->fail('The duplicate values must leave an invalid index.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', $exception->errorInfo[0]);
            }
            $this->assertFalse($this->valid($index));
        }
        $migration->up();
        $migration->up(); // A completed retry is safe to repeat.
        $this->assertTrue($this->valid('notifications_recipient_created_idx'));
        $this->assertTrue($this->valid('notifications_unread_recipient_created_idx'));
    }

    public function test_webhook_migration_repairs_an_invalid_unique_index_and_removes_processed_duplicates(): void
    {
        $migration = require database_path('migrations/2026_10_29_000003_webhook_processed_unique.php');
        $migration->down();
        foreach ([1, 2] as $id) {
            DB::table('webhook_logs')->insert(['provider' => 'checklist', 'event_id' => 'same-event', 'status' => 'processed', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $index = 'webhook_logs_processed_provider_event_unique';
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.$index." ON webhook_logs (provider, event_id) WHERE status = 'processed'");
            $this->fail('Duplicate processed rows must invalidate the concurrent build.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
        $this->assertFalse($this->valid($index));
        $migration->up();
        $migration->up();
        $this->assertTrue($this->valid($index));
        $this->assertSame(1, DB::table('webhook_logs')->where('provider', 'checklist')->count());
    }

    private function valid(string $index): bool
    {
        return DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$index])->indisvalid;
    }
}
