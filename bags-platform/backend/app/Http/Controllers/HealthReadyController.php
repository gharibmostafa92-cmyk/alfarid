<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
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
        } catch (Throwable) {
            $checks['db'] = 'fail';
        }

        try {
            Redis::connection('health')->ping();
            $checks['redis'] = 'ok';
        } catch (Throwable) {
            $checks['redis'] = 'fail';
        }

        $checks['storage'] = is_writable(storage_path('framework')) ? 'ok' : 'fail';

        $ok = ! in_array('fail', $checks, true);

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
