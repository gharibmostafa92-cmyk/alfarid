<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Symfony\Component\Process\Process;

trait RunsConcurrentAuditJobs
{
    /** Each job calls $ready() at the point that must overlap with its peers. */
    private function concurrentJobs(array $jobs): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'staff-audit-race-');
        unlink($barrier);
        $db = config('database.connections.pgsql');
        $this->assertSame('alfarid_testing', $db['database']);
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'AUDIT_START_FILE' => $barrier, 'BCRYPT_ROUNDS' => '4'];
        $processes = [];
        try {
            foreach ($jobs as $job) {
                $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '60s'");
$pid = Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$ready = function () {
    echo "READY\n";
    $deadline = microtime(true) + 100;
    while (!is_file(getenv('AUDIT_START_FILE'))) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timed out.'); }
        usleep(10000);
    }
};
app()->instance('audit.concurrent.barrier', $ready);
try {
    $started = microtime(true);
    $result = (function () use ($ready) {
PHP;
                $script .= $job;
                $script .= <<<'PHP'
    })();
    echo 'RESULT:'.json_encode(['value' => $result, 'pid' => $pid, 'seconds' => microtime(true) - $started,
        'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-d', 'display_errors=1', '-r', $script], base_path(), $environment, timeout: 120);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput().$process->getOutput());
            }
            $released = microtime(true);
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $this->assertSame(1, preg_match('/RESULT:([^\r\n]+)/', $process->getOutput(), $match));
                $results[] = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertCount(count($jobs), array_unique(array_column($results, 'pid')), 'Workers must use independent PostgreSQL connections.');
            $this->auditMetric(['case' => $this->toString(), 'workers' => count($jobs), 'wall_seconds' => microtime(true) - $released,
                'max_worker_peak_bytes' => max(array_column($results, 'peak_bytes'))]);

            return array_column($results, 'value');
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }

    private function httpJob(string $token, string $method, string $uri, array $payload = [], array $headers = []): string
    {
        return 'foreach (app("router")->getRoutes() as $route) { $route->middleware(Tests\\Support\\ConcurrentRequestBarrier::class); }'
            .'$request = Illuminate\\Http\\Request::create('.var_export($uri, true).', '.var_export($method, true).', [], [], [], '
            .var_export(array_replace(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $headers), true)
            .', '.var_export(json_encode($payload, JSON_THROW_ON_ERROR), true).');'
            .'$kernel = app(Illuminate\\Contracts\\Http\\Kernel::class); $response = $kernel->handle($request);'
            .'$body = json_decode($response->getContent(), true); $kernel->terminate($request, $response);'
            .'return ["status" => $response->getStatusCode(), "body" => $body];';
    }

    private function auditMetric(array $metric): void
    {
        file_put_contents(storage_path('logs/audit-concurrency-metrics.jsonl'), json_encode($metric, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
