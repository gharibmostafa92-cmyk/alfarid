<?php

use App\Services\Catalog\RetailPriceSnapshot;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('store:refresh-retail-prices', function (RetailPriceSnapshot $snapshots): void {
    $snapshots->refresh();
    $this->info('Public catalog price snapshots refreshed.');
})->purpose('Refresh public retail sorting prices, including stock and timed price lists');
// Single scheduler container runs these (docker-compose), plus onOneServer() as
// extra protection when the cache store is shared (Redis in production).
// H4: every withoutOverlapping() carries an explicit expiry. The default is
// 1440 minutes — a SIGKILL during deploy (Docker waits only 10s by default)
// would leave the lock in Redis for 24h and silently skip the job, because a
// skipped overlap never fires onFailure. Expiry is set just above the worst
// measured runtime of each cadence (5min -> 10, 15min -> 30, hourly/daily ->
// 120, weekly full reconcile -> 360).
Schedule::command('store:refresh-retail-prices')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:45')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('staff:prune-audit-logs')->dailyAt('04:45')->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('customers:prune-artifacts')->hourly()->withoutOverlapping(120)->onOneServer();
Schedule::command('media:prune-orphans --disk=r2_public --older-than=60')->dailyAt('04:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled orphan media cleanup failed.'));
Schedule::command('media:prune-orphans --disk=r2_public --older-than=60 --staging-only')->hourly()->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled staging media cleanup failed.'));
// M6: the private disk holds uuid/ orphan folders (H2 fallout, interrupted
// uploads) that the public-disk sweep never touches. Same uuid-only matcher,
// so customer_documents/ and other prefixes are never affected.
Schedule::command('media:prune-orphans --disk=r2 --older-than=120')->dailyAt('04:35')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled private-disk orphan media cleanup failed.'));
// M4: notifications table grows ~200k rows/day (10k orders x 20 staff) with no
// prior cleanup. Batched delete keeps the unread-count query fast.
Schedule::command('notifications:prune-read --days=90')->dailyAt('04:50')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('attachments:prune-orphans --hours=24')->dailyAt('05:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Private attachment cleanup failed.'));
Schedule::call(function (): void {
    if (config('cache.default') === 'database') {
        DB::connection(config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', now()->timestamp)->delete();
        DB::connection(config('cache.stores.database.lock_connection') ?? config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.lock_table') ?? 'cache_locks')->where('expiration', '<', now()->timestamp)->delete();
    }
})->name('cache:prune-expired')->hourly()->withoutOverlapping(120)->onOneServer();
// L9: cover the media queue too — the default-only monitor missed it.
Schedule::command('queue:monitor '.config('queue.default', 'database').':default --max=100')
    ->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('queue:monitor '.config('media-library.queue_connection_name', 'database-media').':'.(config('media-library.queue_name') ?: 'media').' --max=1000')
    ->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Schedule::command('inventory:reconcile --incremental --limit=1000')->everyFifteenMinutes()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(30)->onOneServer()
    ->onFailure(fn () => logger()->error('Incremental inventory reconciliation failed.'));
Schedule::command('inventory:reconcile')->weeklyOn(0, '02:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(360)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled inventory reconciliation failed.'));
Schedule::command('orders:expire-pending')->everyFifteenMinutes()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(30)->onOneServer();
Schedule::command('webhooks:prune-logs')->dailyAt('04:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('carts:prune-guests')->dailyAt('04:15')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('inventory:prune-idempotency-keys')->dailyAt('03:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled inventory idempotency cleanup failed.'));
Schedule::command('operations:prune-idempotency-keys')->dailyAt('03:10')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled operation idempotency cleanup failed.'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
