<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'notifications_read_retention_idx' => 'read_at IS NOT NULL',
        'notifications_unread_retention_idx' => 'read_at IS NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $predicate) {
            $existing = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$index]);
            if ($existing?->indisvalid) {
                continue;
            }
            if ($existing !== null) {
                DB::statement("DROP INDEX CONCURRENTLY {$index}");
            }

            DB::statement("CREATE INDEX CONCURRENTLY {$index} ON notifications (created_at, id) WHERE {$predicate}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
