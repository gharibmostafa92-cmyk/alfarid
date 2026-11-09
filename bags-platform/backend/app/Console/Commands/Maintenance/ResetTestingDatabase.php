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
        $host = config('database.connections.pgsql.host', '127.0.0.1');
        $port = config('database.connections.pgsql.port', '5432');
        $user = config('database.connections.pgsql.username', 'postgres');
        $pass = config('database.connections.pgsql.password', '123456');

        $pdo = new PDO("pgsql:host={$host};port={$port};dbname=alfarid_testing", $user, $pass);
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE;');
        $pdo->exec('CREATE SCHEMA public;');
        $pdo->exec('GRANT ALL ON SCHEMA public TO postgres;');
        $pdo->exec('GRANT ALL ON SCHEMA public TO public;');

        $this->info('alfarid_testing schema successfully reset.');

        return self::SUCCESS;
    }
}
