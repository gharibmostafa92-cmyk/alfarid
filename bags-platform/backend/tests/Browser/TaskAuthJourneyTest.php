<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Explicit opt-in command: php artisan test tests/Browser/TaskAuthJourneyTest.php
// Requires storefront :3003 and backoffice :5174, both using API :8000.
class TaskAuthJourneyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_four_browser_auth_journeys_use_real_postgresql_and_fake_notifications(): void
    {
        $this->assertSame('alfarid_testing', DB::connection()->getDatabaseName());
        $occupied = @fsockopen('127.0.0.1', 8000, $error, $message, 1);
        if ($occupied !== false) {
            fclose($occupied);
            $this->fail('Port 8000 must be free; the browser runner starts its own isolated API.');
        }
        $staff = User::factory()->create(['email' => 'browser-staff@example.test', 'password' => 'Browser-password12']);
        $staff->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $directory = storage_path('framework/testing/auth-browser-'.Str::uuid());
        mkdir($directory, 0700, true);
        $db = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'alfarid_testing',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'database',
            'CACHE_LIMITER' => 'database', 'BCRYPT_ROUNDS' => '4', 'QUEUE_CONNECTION' => 'sync',
            'CUSTOMER_PASSWORD_RESET_QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
            'CUSTOMER_REQUIRE_PHONE_VERIFICATION' => 'false', 'SESSION_DRIVER' => 'array',
            'CORS_ALLOWED_ORIGINS' => 'http://localhost:3003,http://localhost:5174',
            'AUTH_BROWSER_TEST' => '1', 'AUTH_BROWSER_FILES' => $directory.'/uploads',
            'AUTH_BROWSER_SMS' => $directory.'/sms.txt',
        ];
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:8000', 'tests/Browser/auth-router.php'], base_path(), $environment);
        try {
            $server->start();
            $server->waitUntil(fn ($type, $output): bool => str_contains($output, 'Development Server'));
            $browser = new Process(['node', '--test', 'tests/task-auth-journeys.e2e.cjs'], base_path('../frontend'), [
                'AUTH_BROWSER_SMS' => $directory.'/sms.txt', 'STOREFRONT_URL' => 'http://localhost:3003',
                'BACKOFFICE_URL' => 'http://localhost:5174',
            ], timeout: 240);
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput().$server->getErrorOutput());
            $this->assertSame(3, Customer::count());
            $this->assertDatabaseHas('customers', ['phone' => '01033334444', 'type' => 'wholesale', 'status' => 'pending']);
            $this->assertDatabaseCount('customer_documents', 1);
            $this->assertDatabaseCount('password_reset_tokens', 0);
            $this->assertSame(1, $staff->tokens()->count());
        } finally {
            $server->stop();
            // Only this test's freshly created directory can be removed.
            $resolved = realpath($directory);
            $parent = realpath(storage_path('framework/testing'));
            if ($resolved !== false && $parent !== false && dirname($resolved) === $parent) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }
}
