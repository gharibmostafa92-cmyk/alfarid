<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Webhook deduplication without a DB guarantee is racy — two concurrent
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

        // Keep every delivery and mark later processed attempts as duplicates.
        DB::unprepared(<<<'SQL'
            WITH ranked AS (
                SELECT id, row_number() OVER (PARTITION BY provider, event_id ORDER BY id) AS delivery
                FROM webhook_logs
                WHERE status = 'processed' AND event_id IS NOT NULL
            )
            UPDATE webhook_logs
            SET status = 'duplicate'
            FROM ranked
            WHERE webhook_logs.id = ranked.id AND ranked.delivery > 1
            SQL);

        // PostgreSQL unique indexes allow separate NULLs. Preserve records
        // without an event ID; they do not establish a deduplication key.

        ConcurrentIndex::ensure('webhook_logs_processed_provider_event_unique', "ON webhook_logs (provider, event_id) WHERE status = 'processed'", unique: true);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('webhook_logs_processed_provider_event_unique');
    }
};
