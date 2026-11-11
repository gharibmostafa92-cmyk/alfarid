<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ConcurrentIndex
{
    public static function ensure(string $name, string $definition, bool $unique = false): bool
    {
        self::validateName($name);
        if (! preg_match('/\AON ([a-z_][a-z0-9_]*)\s+(.+)\z/is', $definition, $parts)) {
            throw new \InvalidArgumentException('Expected an index definition beginning with ON and an unqualified table name.');
        }
        [, $table, $attributes] = $parts;
        $index = DB::selectOne(<<<'SQL'
            SELECT i.indisvalid, i.indrelid = to_regclass(?) AS on_target_table,
                pg_get_indexdef(c.oid) AS definition
            FROM pg_class c
            LEFT JOIN pg_index i ON i.indexrelid = c.oid
            WHERE c.relnamespace = current_schema()::regnamespace AND c.relname = ?
            SQL, [$table, $name], false);
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
        if ($index !== null) {
            if (! $index->on_target_table) {
                throw new \RuntimeException("Relation {$name} already exists outside {$table}'s indexes.");
            }
            if ($index->indisvalid) {
                self::verifyDefinition($name, $table, $attributes, $type, $index->definition);

                return false;
            }
            self::drop($name);
        }
        DB::statement("CREATE {$type} ".self::concurrently()."{$name} {$definition}");

        return true;
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

    private static function verifyDefinition(string $name, string $table, string $attributes, string $type, string $actual): void
    {
        // Let PostgreSQL normalize expressions, casts, predicates, and defaults
        // against the same column types without scanning the live table.
        $expected = DB::transaction(function () use ($table, $attributes, $type): string {
            $sample = 'index_check_'.bin2hex(random_bytes(8));
            DB::statement("CREATE TEMP TABLE {$sample} (LIKE {$table}) ON COMMIT DROP");
            DB::statement("CREATE {$type} {$sample}_idx ON {$sample} {$attributes}");
            $definition = DB::scalar("SELECT pg_get_indexdef('pg_temp.{$sample}_idx'::regclass)", [], false);
            DB::statement("DROP TABLE pg_temp.{$sample}");

            return $definition;
        });

        $signature = static fn (string $definition): string => preg_replace(
            '/\A(CREATE (?:UNIQUE )?INDEX) \S+ ON (?:ONLY )?\S+ /',
            '$1 ',
            $definition,
        );
        if ($signature($actual) !== $signature($expected)) {
            throw new \RuntimeException("Existing index {$name} has a different definition: {$actual}");
        }
    }

    private static function concurrently(): string
    {
        if (DB::transactionLevel() === 0) {
            return 'CONCURRENTLY ';
        }
        if (app()->environment('testing')) {
            return '';
        }

        throw new \LogicException('Concurrent index builds require an independent migration.');
    }
}
