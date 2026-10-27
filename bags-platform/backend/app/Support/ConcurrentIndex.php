<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ConcurrentIndex
{
    public static function ensure(string $name, string $definition): void
    {
        self::validateName($name);
        $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            self::drop($name);
        }
        DB::statement('CREATE INDEX '.self::concurrently().'IF NOT EXISTS '.$name.' '.$definition);
    }

    public static function drop(string $name): void
    {
        self::validateName($name);
        DB::statement('DROP INDEX '.self::concurrently().'IF EXISTS '.$name);
    }

    private static function validateName(string $name): void
    {
        if (! preg_match('/\A[a-z_][a-z0-9_]*\z/', $name)) {
            throw new \InvalidArgumentException('Invalid index name.');
        }
    }

    private static function concurrently(): string
    {
        if (app()->environment('testing')) {
            return '';
        }
        if (DB::transactionLevel() > 0) {
            throw new \LogicException('Concurrent index builds require an independent migration.');
        }

        return 'CONCURRENTLY ';
    }
}
