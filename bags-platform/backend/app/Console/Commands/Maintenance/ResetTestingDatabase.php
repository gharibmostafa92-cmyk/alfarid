<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use PDO;

class ResetTestingDatabase extends Command
{
    protected $signature = 'db:reset-testing';

    protected $description = 'Resets the PostgreSQL alfarid_testing schema to a clean state';

    public function handle(): int
    {
        // M10: never drop schemas outside local/testing — the connection this
        // command opens uses production-shaped credentials on the same host.
        if (! app()->environment('local', 'testing')) {
            $this->error('Refusing to reset: db:reset-testing runs only in local/testing environments.');

            return self::FAILURE;
        }

        $host = config('database.connections.pgsql.host', '127.0.0.1');
        $port = config('database.connections.pgsql.port', '5432');
        $user = config('database.connections.pgsql.username', 'postgres');
        // M10: no default password — fail loud instead of guessing '123456'.
        $pass = config('database.connections.pgsql.password');
        if (! is_string($pass) || $pass === '') {
            $this->error('Refusing to reset: database password is not configured.');

            return self::FAILURE;
        }

        $pdo = new PDO("pgsql:host={$host};port={$port};dbname=alfarid_testing", $user, $pass);
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE;');
        $pdo->exec('CREATE SCHEMA public;');
        $pdo->exec('GRANT ALL ON SCHEMA public TO postgres;');
        $pdo->exec('GRANT ALL ON SCHEMA public TO public;');

        $this->info('alfarid_testing schema successfully reset.');

        return self::SUCCESS;
    }
}
