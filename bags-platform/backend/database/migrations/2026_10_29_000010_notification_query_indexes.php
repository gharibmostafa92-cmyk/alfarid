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

        foreach ([
            'notifications_recipient_created_idx' => 'ON notifications (notifiable_type, notifiable_id, created_at DESC)',
            'notifications_unread_recipient_created_idx' => 'ON notifications (notifiable_type, notifiable_id, created_at DESC) WHERE read_at IS NULL',
        ] as $name => $definition) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
            // An interrupted concurrent build leaves an unusable index whose
            // name would otherwise make IF NOT EXISTS silently skip a retry.
            if ($index !== null && ! $index->indisvalid) {
                DB::statement("DROP INDEX CONCURRENTLY {$name}");
            }
            if ($index === null || ! $index->indisvalid) {
                DB::statement("CREATE INDEX CONCURRENTLY {$name} {$definition}");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_unread_recipient_created_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_recipient_created_idx');
    }
};
