<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Auth\StaffHandoffService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AuthConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_staff_and_supplier_creation_claim_the_email_only_once(): void
    {
        $supplier = Supplier::factory()->create();
        $this->race([
            'App\\Models\\User::create(["name" => "Staff", "email" => "race@example.test", "password" => "test-password"]); echo "OK";',
            'App\\Models\\SupplierUser::create(["supplier_id" => '.$supplier->id.', "name" => "Supplier", "email" => "RACE@example.test", "password" => "test-password"]); echo "OK";',
        ]);
        $this->assertSame(1, User::where('email', 'race@example.test')->count() + SupplierUser::where('email', 'race@example.test')->count());
    }

    public function test_customer_email_verification_and_staff_creation_cannot_claim_the_same_email(): void
    {
        $customer = Customer::factory()->create(['email' => 'verify-race@example.test', 'email_verified_at' => null]);
        $this->race([
            'App\\Models\\Customer::findOrFail('.$customer->id.')->forceFill(["email_verified_at" => now()])->save(); echo "OK";',
            'App\\Models\\User::create(["name" => "Staff", "email" => "verify-race@example.test", "password" => "test-password"]); echo "OK";',
        ]);
        $claims = User::where('email', 'verify-race@example.test')->count()
            + Customer::where('email', 'verify-race@example.test')->whereNotNull('email_verified_at')->count();
        $this->assertSame(1, $claims);
    }

    public function test_shared_database_cache_exchanges_a_handoff_code_only_once_across_processes(): void
    {
        Cache::setDefaultDriver('database');
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'staff']));
        $code = app(StaffHandoffService::class)->issue($user);
        $job = '$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);'
            .'$request = Illuminate\\Http\\Request::create("/api/v1/auth/handoff/exchange", "POST", ["code" => "'.$code.'"], [], [], ["HTTP_ACCEPT" => "application/json"]);'
            .'$response = $kernel->handle($request);'
            .'if (!in_array($response->getStatusCode(), [200, 401], true)) { throw new RuntimeException($response->getContent()); }'
            .'echo $response->getStatusCode() === 200 ? "OK" : "REJECTED";';
        $this->race([$job, $job]);
        $this->assertSame(1, $user->tokens()->count());
    }

    /** @param list<string> $jobs */
    private function race(array $jobs): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'auth-race-');
        unlink($barrier);
        $db = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'database',
            'BCRYPT_ROUNDS' => '4', 'AUTH_START_FILE' => $barrier,
        ];
        $processes = [];
        try {
            foreach ($jobs as $job) {
                $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "READY\n";
$deadline = microtime(true) + 12;
while (!is_file(getenv('AUTH_START_FILE'))) {
    if (microtime(true) > $deadline) { exit(4); }
    usleep(10000);
}
try {
PHP;
                $script .= $job;
                $script .= <<<'PHP'
} catch (Illuminate\Validation\ValidationException $exception) {
    echo "REJECTED";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment, timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            $this->assertEqualsCanonicalizing(['OK', 'REJECTED'], $results);
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
}
