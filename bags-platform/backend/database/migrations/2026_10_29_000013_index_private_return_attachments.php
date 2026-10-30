<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $name = 'return_request_items_attachments_gin_idx';
        $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement("DROP INDEX CONCURRENTLY {$name}");
        }
        if ($index === null || ! $index->indisvalid) {
            // Matches Laravel's whereJsonContains cast for the existing JSON column.
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON return_request_items USING gin ((attachments::jsonb))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS return_request_items_attachments_gin_idx');
        }
    }
};
