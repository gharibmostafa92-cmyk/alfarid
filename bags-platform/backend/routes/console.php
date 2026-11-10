<?php

use App\Console\Commands\RaceWorkerCommand;
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
// Each withoutOverlapping() carries an explicit expiry calibrated to worst-case execution time,
// preventing 24-hour locks caused by abrupt container termination.
Schedule::command('store:refresh-retail-prices')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:45')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('staff:prune-audit-logs')->dailyAt('04:45')->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('ai:prune-conversations')->dailyAt('05:10')->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer();
Schedule::command('whatsapp:watch-connection')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('customers:prune-artifacts')->hourly()->withoutOverlapping(120)->onOneServer();
Schedule::command('media:prune-orphans --disk=r2_public --older-than=60')->dailyAt('04:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled orphan media cleanup failed.'));
Schedule::command('media:prune-orphans --disk=r2_public --older-than=60 --staging-only')->hourly()->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled staging media cleanup failed.'));
// Only delete media with a known staging row on the private disk.
Schedule::command('media:prune-orphans --disk=r2 --older-than=120 --staging-only')->dailyAt('04:35')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled private-disk orphan media cleanup failed.'));
// Prune read notifications older than 90 days to prevent high table cardinality from degrading unread count queries.
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
// Monitor both default and dedicated media queues for backlog accumulation.
Schedule::command('queue:monitor '.config('queue.default', 'database').':default --max=100')
    ->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('queue:monitor '.config('media-library.queue_connection_name', 'database-media').':'.(config('media-library.queue_name') ?: 'media').' --max=1000')
    ->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Schedule::command('inventory:reconcile --incremental --limit=1000')->everyFifteenMinutes()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(30)->onOneServer()
    ->onFailure(fn () => logger()->error('Incremental inventory reconciliation failed.'));
Schedule::command('inventory:reconcile --all-branches')->weeklyOn(0, '02:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(360)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled inventory reconciliation failed.'));
Schedule::command('quotations:expire')->hourly()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled quotation expiry failed.'));
Schedule::command('wholesale:quote-expiry-reminders')->hourly()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled quotation expiry reminders failed.'));
Schedule::command('wholesale:mark-overdue')->dailyAt('08:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled wholesale overdue marking failed.'));
Schedule::command('wholesale:daily-briefing')->dailyAt('09:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled wholesale daily briefing failed.'));
Schedule::command('wholesale:escalate-stale')->hourly()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled wholesale escalation failed.'));
Schedule::command('wholesale:payment-reminders')->dailyAt('10:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled wholesale payment reminders failed.'));
Schedule::command('wholesale:monthly-statements')->monthlyOn(1, '09:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled wholesale monthly statements failed.'));
Schedule::command('wholesale:collection-digest')->dailyAt('09:15')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled wholesale collection digest failed.'));
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
Schedule::command('inventory:detect-anomalies')->weeklyOn(1, '06:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled inventory anomaly detection failed.'));
Schedule::command('finance:reconcile')->dailyAt('02:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping(120)->onOneServer()
    ->onFailure(fn () => logger()->error('Scheduled finance reconciliation failed.'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Test-only worker for multi-process concurrency tests. Never registered
// outside the testing environment so production has no test helpers.
if (app()->environment('testing')) {
    Artisan::command('race:worker {--file=}', function (string $file): int {
        return app(RaceWorkerCommand::class)->runJob($file);
    })->purpose('Test-only concurrency worker');
}
