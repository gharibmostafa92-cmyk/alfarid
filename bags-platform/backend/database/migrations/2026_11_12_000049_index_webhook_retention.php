<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('webhook_logs_retention_idx')");
        if ($index !== null && ! $index->indisvalid) {
            $this->down();
        }
        if (! $index?->indisvalid) {
            DB::statement('CREATE INDEX CONCURRENTLY webhook_logs_retention_idx ON webhook_logs (created_at, id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS webhook_logs_retention_idx');
    }
};
