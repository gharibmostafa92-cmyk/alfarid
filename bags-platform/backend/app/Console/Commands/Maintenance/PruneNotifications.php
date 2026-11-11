<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The notifications table grows ~200k rows/day (10k orders x ~20 staff with
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

        foreach ([true, false] as $read) {
            $expired = DB::table('notifications')->where('created_at', '<', $read ? $cutoff : $unreadCutoff);
            if ($read) {
                $expired->whereNotNull('read_at');
            } else {
                $expired->whereNull('read_at');
            }

            do {
                $ids = (clone $expired)->orderBy('created_at')->orderBy('id')->limit(1000)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }

                // Recheck the read state in case it changed after selection.
                $deleted += (clone $expired)->whereIn('id', $ids)->delete();
            } while ($ids->count() === 1000);
        }

        $this->info("Pruned {$deleted} expired notifications (read: {$days} days; unread: {$unreadDays} days).");

        return self::SUCCESS;
    }
}
