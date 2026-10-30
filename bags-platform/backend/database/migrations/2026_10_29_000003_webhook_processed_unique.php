<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H6: webhook dedup without a DB guarantee is racy — two concurrent
 * deliveries can both pass the "is there a processed row?" read, then both
 * write. A partial unique index on (provider, event_id) WHERE processed
 * makes the second committer fail atomically; the advisory xact lock in
 * PaymentWebhookService already serializes the common path, this is the
 * backstop. PruneWebhookLogs only scrubs payloads of processed rows, so
 * the key survives retention.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Clean pre-existing processed duplicates so the index can build.
        DB::unprepared(<<<'SQL'
            DELETE FROM webhook_logs a USING webhook_logs b
            WHERE a.status = 'processed' AND b.status = 'processed'
              AND a.provider = b.provider
              AND a.event_id = b.event_id
              AND a.id > b.id
            SQL);

        // PostgreSQL unique indexes allow separate NULLs. Preserve records
        // without an event ID; they do not establish a deduplication key.

        $index = DB::selectOne("SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'webhook_logs_processed_provider_event_unique' AND c.relnamespace = current_schema()::regnamespace");
        if ($index !== null && ! $index->indisvalid) {
            DB::unprepared('DROP INDEX CONCURRENTLY webhook_logs_processed_provider_event_unique');
        }

        if ($index === null || ! $index->indisvalid) {
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX CONCURRENTLY webhook_logs_processed_provider_event_unique
                ON webhook_logs (provider, event_id)
                WHERE status = 'processed'
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS webhook_logs_processed_provider_event_unique');
    }
};
