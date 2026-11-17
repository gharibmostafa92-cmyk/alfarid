<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Central cross-realm email advisory lock.
 *
 * PostgreSQL cannot enforce one UNIQUE index across the three identity
 * tables (users, customers, supplier_users), so every write path that
 * touches an account email must serialize on the same advisory key.
 * A divergent key prefix in any single caller reopens the TOCTOU race.
 */
final class AccountEmailLock
{
    public const KEY_PREFIX = 'account_email:';

    public static function key(string $email): string
    {
        return self::KEY_PREFIX.mb_strtolower(trim($email));
    }

    /**
     * Acquire the shared transactional advisory lock for an email address.
     * No-op on non-PostgreSQL drivers (test environments).
     *
     * @throws \LogicException If called outside a database transaction.
     */
    public static function acquire(?string $email): void
    {
        if ($email === null || trim($email) === '') {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Cross-realm email claims require a transaction.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::key($email)]);
        }
    }
}
