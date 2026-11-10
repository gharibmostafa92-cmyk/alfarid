<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * task3 M-3: a failed CREATE INDEX CONCURRENTLY leaves an INVALID index, and
 * IF NOT EXISTS then skips it forever. Drop that invalid index and rebuild.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $name = 'media_client_upload_id_unique';
        $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
            $index = null;
        }
        if ($index === null || ! $index->indisvalid) {
            DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON media (model_type, model_id, (custom_properties->>'client_upload_id')) WHERE custom_properties->>'client_upload_id' IS NOT NULL");
        }
    }

    public function down(): void
    {
        // L2: the index belongs to 000003; rolling back this repair must not drop it.
    }
};
