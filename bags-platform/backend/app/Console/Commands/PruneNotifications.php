<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * M4: notifications table grows ~200k rows/day (10k orders x ~20 staff with
 * orders.view) with a text data column. Delete READ notifications past the
 * retention window in 1000-row batches. Unread notifications have a longer,
 * finite retention window so abandoned accounts cannot grow the table forever.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune-read {--days=90 : Retain read notifications for this many days} {--unread-days=365 : Retain unread notifications for this many days}';

    protected $description = 'Delete expired read and unread notifications in batches';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $unreadDays = max($days, (int) $this->option('unread-days'));
        $unreadCutoff = now()->subDays($unreadDays);
        $deleted = 0;

        do {
            $ids = DB::table('notifications')
                ->where(fn ($query) => $query
                    ->where(fn ($read) => $read->whereNotNull('read_at')->where('created_at', '<', $cutoff))
                    ->orWhere(fn ($unread) => $unread->whereNull('read_at')->where('created_at', '<', $unreadCutoff)))
                ->orderBy('created_at')->orderBy('id')
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('notifications')->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === 1000);

        $this->info("Pruned {$deleted} expired notifications (read: {$days} days; unread: {$unreadDays} days).");

        return self::SUCCESS;
    }
}
