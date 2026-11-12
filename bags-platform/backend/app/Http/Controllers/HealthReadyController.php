<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthReadyController
{
    public function __invoke(): JsonResponse
    {
        $checks = [];

        try {
            $connection = DB::connection('pgsql-health');
            $connection->transaction(function () use ($connection): void {
                $connection->statement("SET LOCAL statement_timeout = '1s'");
                $connection->select('select 1');
            });
            $checks['db'] = 'ok';
        } catch (Throwable $e) {
            logger()->warning('readiness.db_failed', ['error' => $e->getMessage()]);
            $checks['db'] = 'fail';
        }

        $checks['redis'] = 'skipped';
        if (config('app.readiness_check_redis')) {
            try {
                Redis::connection('health')->ping();
                $checks['redis'] = 'ok';
            } catch (Throwable $e) {
                logger()->warning('readiness.redis_failed', ['error' => $e->getMessage()]);
                $checks['redis'] = 'fail';
            }
        }

        $checks['storage'] = is_writable(storage_path('framework')) ? 'ok' : 'fail';

        // H6: without a recent scheduler heartbeat, expirations,
        // reconciliations and queue monitoring are silently dead.
        try {
            $heartbeat = Cache::get('scheduler_heartbeat_at');
            $heartbeatAt = is_string($heartbeat) ? Carbon::parse($heartbeat) : null;
            $checks['scheduler'] = ($heartbeatAt !== null && $heartbeatAt->greaterThan(now()->subMinutes(10))) ? 'ok' : 'fail';
        } catch (Throwable) {
            $checks['scheduler'] = 'fail';
        }

        $ok = ! in_array('fail', $checks, true);

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
