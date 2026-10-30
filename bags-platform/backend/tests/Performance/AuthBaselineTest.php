<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Explicit opt-in: seeds ten million disposable rows; never part of normal CI.
class AuthBaselineTest extends TestCase
{
    use DatabaseMigrations;

    public function test_authentication_search_and_cleanup_baseline_on_representative_row_counts(): void
    {
        $this->assertSame('alfarid_testing', DB::connection()->getDatabaseName());
        $port = @fsockopen('127.0.0.1', 8000, $error, $message, 1);
        if ($port !== false) {
            fclose($port);
            $this->fail('Port 8000 must be free.');
        }
        $output = base_path('../../test-results/task-audit');
        (new Filesystem)->ensureDirectoryExists($output);
        $report = ['environment' => 'Windows local PHP single-worker HTTP server / PostgreSQL; bcrypt cost 12',
            'staff_rows' => 50000, 'staff_audit_rows' => 5000000, 'expired_phone_rows' => 5000000];
        $directory = storage_path('framework/testing/auth-baseline-'.Str::uuid());
        mkdir($directory, 0700, true);
        $server = null;
        try {
            config(['hashing.bcrypt.rounds' => 12]);
            Hash::forgetDrivers();
            $hash = Hash::make('Baseline-password12', ['rounds' => 12]);
            $admin = User::factory()->create(['email' => 'baseline-admin@example.test', 'password' => $hash]);
            $admin->assignRole(Role::findOrCreate('Super Admin', 'staff'));
            DB::insert("INSERT INTO users (name,email,password,is_active,is_system,created_at,updated_at)
                SELECT 'Baseline ' || i, 'baseline-' || i || '@example.test', ?, true, false, now(), now()
                FROM generate_series(1,49999) AS i", [$hash]);
            $this->assertSame(50000, User::where('is_system', false)->count());
            $operator = Role::findOrCreate('Baseline operator', 'staff');
            $customerHash = Hash::make('Customer-password34', ['rounds' => 12]);
            for ($i = 1; $i <= 100; $i++) {
                $email = 'baseline-'.$i.'@example.test';
                User::where('email', $email)->firstOrFail()->assignRole($operator);
                $customer = Customer::factory()->create(['type' => 'retail', 'status' => 'approved',
                    'email' => 'baseline-customer-'.$i.'@example.test', 'email_verified_at' => now(),
                    'password' => $customerHash]);
                DB::table('customers')->where('id', $customer->id)->update(['email' => $email]);
            }
            $report['historical_shared_identities'] = 100;
            DB::statement('ANALYZE users');
            $report['staff_search_explain'] = DB::select("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
                SELECT id FROM users WHERE is_system=false AND
                (lower(name) LIKE '%baseline%' OR lower(email) LIKE '%baseline%' OR phone LIKE '%baseline%') ORDER BY id LIMIT 15");
            $db = config('database.connections.pgsql');
            $server = new Process([PHP_BINARY, '-S', '127.0.0.1:8000', 'tests/Browser/auth-router.php'], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'alfarid_testing',
                'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
                'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'BCRYPT_ROUNDS' => '12',
                'CACHE_STORE' => 'database', 'CACHE_LIMITER' => 'database', 'SESSION_DRIVER' => 'array',
                'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync',
                'UNIFIED_LOGIN_IP_FAILURES_HOUR' => '10', 'UNIFIED_LOGIN_IP_MINUTE' => '10000',
                'AUTH_BROWSER_TEST' => '1', 'AUTH_BROWSER_MODE' => 'performance',
                'AUTH_BROWSER_FILES' => $directory.'/uploads', 'AUTH_BROWSER_SMS' => $directory.'/sms.txt',
            ]);
            $server->start();
            $this->assertTrue($server->waitUntil(fn ($type, $text): bool => str_contains($text, 'Development Server')));
            $load = new Process(['k6', 'run', '--quiet', '--summary-export', $output.'/auth-k6-summary.json',
                '../frontend/tests/task-auth-baseline.k6.js'], base_path(), [
                    'BASELINE_STAFF_TOKEN' => $admin->createToken('baseline')->plainTextToken,
                ], timeout: 120);
            $load->run();
            file_put_contents($output.'/auth-k6-run.log', $load->getOutput().$load->getErrorOutput());
            $this->assertTrue($load->isSuccessful(), $load->getOutput().$load->getErrorOutput());
            $this->assertTrue(DB::table('notifications')->exists(), 'The measured sendNow must persist a real database notification.');
            $server->stop();

            for ($start = 1; $start <= 5000000; $start += 100000) {
                DB::insert("INSERT INTO staff_audit_logs (action,target_type,metadata,created_at)
                    SELECT 'baseline','staff','{}'::jsonb,now()-interval '400 days' FROM generate_series(?::integer,?::integer)", [$start, $start + 99999]);
                DB::insert("INSERT INTO customer_phone_verifications (phone_key,code_hash,created_at,expires_at)
                    SELECT lpad(i::text,64,'0'),repeat('x',64),now()-interval '7 days',now()-interval '6 days'
                    FROM generate_series(?::integer,?::integer) AS i", [$start, $start + 99999]);
            }
            DB::statement('ANALYZE staff_audit_logs');
            DB::statement('ANALYZE customer_phone_verifications');
            $this->assertSame(5000000, DB::table('staff_audit_logs')->where('action', 'baseline')->count());
            $this->assertDatabaseCount('customer_phone_verifications', 5000000);
            $report['staff_cleanup_explain'] = DB::select("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
                SELECT id FROM staff_audit_logs WHERE created_at < now()-interval '365 days' ORDER BY id LIMIT 1000");
            $report['phone_cleanup_explain'] = DB::select("EXPLAIN (FORMAT JSON)
                DELETE FROM customer_phone_verifications WHERE expires_at < now()-interval '1 hour'");
            foreach (['staff:prune-audit-logs', 'customers:prune-artifacts'] as $command) {
                $started = hrtime(true);
                $exit = app(Kernel::class)->call($command);
                $report['cleanup'][$command] = ['duration_ms' => (hrtime(true) - $started) / 1000000, 'exit_code' => $exit];
                $this->assertSame(0, $exit);
            }
            $this->assertSame(0, DB::table('staff_audit_logs')->where('action', 'baseline')->count());
            $this->assertDatabaseCount('customer_phone_verifications', 0);
        } finally {
            $server?->stop();
            file_put_contents($output.'/auth-scale-baseline.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $resolved = realpath($directory);
            $parent = realpath(storage_path('framework/testing'));
            if ($resolved !== false && $parent !== false && dirname($resolved) === $parent) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }
}
