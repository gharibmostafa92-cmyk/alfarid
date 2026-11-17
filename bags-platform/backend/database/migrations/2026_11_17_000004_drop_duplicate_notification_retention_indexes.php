<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops notification indexes that duplicate the *_prune_idx pair byte for
 * byte (same columns, same partial predicates) plus the created_at-only
 * index whose queries are leftmost-prefix covered by the prune pair.
 *
 * Kept: notifications_read_prune_idx, notifications_unread_prune_idx
 * (from 2026_11_12_000055_add_notification_prune_indexes).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    private const DUPLICATES = [
        'notifications_read_retention_idx',
        'notifications_unread_retention_idx',
        'notifications_created_at_idx',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::DUPLICATES as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: the dropped indexes were exact
        // duplicates of the retained prune pair; restoring them would
        // reintroduce the write amplification this migration removes.
    }
};
