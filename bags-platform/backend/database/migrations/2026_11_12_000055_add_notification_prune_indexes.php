<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H4: PruneNotifications walks notifications by created_at, but the only
 * usable index starts with (notifiable_type, notifiable_id) or filters
 * unread rows — so every read-prune batch scans millions of unread rows
 * first. Two partial (created_at, id) indexes make each batch O(1000).
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
            'notifications_read_prune_idx',
            'ON notifications (created_at, id) WHERE read_at IS NOT NULL'
        );
        ConcurrentIndex::ensure(
            'notifications_unread_prune_idx',
            'ON notifications (created_at, id) WHERE read_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('notifications_read_prune_idx');
        ConcurrentIndex::drop('notifications_unread_prune_idx');
    }
};
