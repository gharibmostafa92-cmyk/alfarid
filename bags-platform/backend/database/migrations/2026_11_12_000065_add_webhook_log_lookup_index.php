<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I1: duplicate/replay checks read (provider, event_id) and filter by status.
 * The partial unique only covers status='processed', so failed/duplicate
 * lookups still fall back to the wider two-column index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::ensure(
            'webhook_logs_provider_event_status_index',
            'ON webhook_logs (provider, event_id, status)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('webhook_logs_provider_event_status_index');
    }
};
