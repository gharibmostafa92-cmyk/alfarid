<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Run from the backend root with a dedicated, disposable PostgreSQL database.
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! app()->environment('testing') || ! str_starts_with(DB::connection()->getDatabaseName(), 'alfarid_migration_audit_')) {
        throw new RuntimeException('Use APP_ENV=testing and a dedicated alfarid_migration_audit_* database.');
    }

    $run = static function (string $command, array $arguments): void {
        if (Artisan::call($command, $arguments) !== 0) {
            throw new RuntimeException(Artisan::output());
        }
    };
    $run('migrate:fresh', ['--force' => true, '--step' => true]);
    $count = DB::table('migrations')->count();
    if ($count === 0) {
        throw new RuntimeException('No migrations were discovered.');
    }
    for ($remaining = $count; $remaining > 0; $remaining--) {
        $run('migrate:rollback', ['--force' => true, '--step' => 1]);
        if (DB::table('migrations')->count() !== $remaining - 1) {
            throw new RuntimeException('A rollback did not remove exactly one migration.');
        }
    }
    $run('migrate', ['--force' => true, '--step' => true]);
    if (DB::table('migrations')->count() !== $count) {
        throw new RuntimeException('The schema could not be rebuilt after rollback.');
    }
    echo "PASS: {$count} migrations applied, rolled back individually, and reapplied.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
