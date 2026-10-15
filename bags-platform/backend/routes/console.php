<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:reconcile')->dailyAt('02:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled inventory reconciliation failed.'));
Schedule::command('inventory:prune-idempotency-keys')->dailyAt('03:00')
    ->timezone(config('app.business_timezone', 'Africa/Cairo'))->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled inventory idempotency cleanup failed.'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
