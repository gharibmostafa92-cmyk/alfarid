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

        // H3: readiness answers "can this instance serve traffic?", which the
        // scheduler does not affect. A lagging scheduler is reported through
        // schedulerStatus() and must not pull the API out of rotation.
        $ok = $checks['db'] === 'ok'
            && $checks['redis'] !== 'fail'
            && $checks['storage'] === 'ok';

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Report scheduler liveness independently from HTTP readiness.
     */
    public function schedulerStatus(): JsonResponse
    {
        $ok = false;
        try {
            $heartbeat = Cache::get('scheduler_heartbeat_at');
            $heartbeatAt = is_string($heartbeat) ? Carbon::parse($heartbeat) : null;
            $ok = $heartbeatAt !== null && $heartbeatAt->greaterThan(now()->subMinutes(10));
        } catch (Throwable) {
            $ok = false;
        }

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => ['scheduler' => $ok ? 'ok' : 'fail']], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
