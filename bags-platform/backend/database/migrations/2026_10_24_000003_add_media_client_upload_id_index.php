<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * M9: idempotent media upload. client_upload_id (UUID, per file) lives in
     * media.custom_properties; a partial unique index turns a retried upload
     * into an idempotent replay instead of a duplicate row.
     */
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS media_client_upload_id_unique ON media (model_type, model_id, (custom_properties->>'client_upload_id')) WHERE custom_properties->>'client_upload_id' IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS media_client_upload_id_unique');
    }
};
