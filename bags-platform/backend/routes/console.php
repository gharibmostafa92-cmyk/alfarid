<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:45')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping();
Schedule::command('customers:prune-artifacts')->hourly()->withoutOverlapping();
Schedule::command('media:prune-orphans --disk=r2_public --older-than=60')->dailyAt('04:30')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled orphan media cleanup failed.'));
Schedule::call(function (): void {
    if (config('cache.default') === 'database') {
        DB::connection(config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', now()->timestamp)->delete();
        DB::connection(config('cache.stores.database.lock_connection') ?? config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.lock_table') ?? 'cache_locks')->where('expiration', '<', now()->timestamp)->delete();
    }
})->name('cache:prune-expired')->hourly()->withoutOverlapping();
Schedule::command('queue:monitor '.config('auth.customer_password_reset.connection', 'database').':default --max=100')
    ->everyFiveMinutes()->withoutOverlapping();

Schedule::command('inventory:reconcile --incremental --limit=1000')->everyFifteenMinutes()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Incremental inventory reconciliation failed.'));
Schedule::command('inventory:reconcile')->weeklyOn(0, '02:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled inventory reconciliation failed.'));
Schedule::command('orders:expire-pending')->everyFifteenMinutes()
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping();
Schedule::command('webhooks:prune-logs')->dailyAt('04:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping();
Schedule::command('carts:prune-guests')->dailyAt('04:15')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping();
Schedule::command('inventory:prune-idempotency-keys')->dailyAt('03:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled inventory idempotency cleanup failed.'));
Schedule::command('operations:prune-idempotency-keys')->dailyAt('03:10')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled operation idempotency cleanup failed.'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
