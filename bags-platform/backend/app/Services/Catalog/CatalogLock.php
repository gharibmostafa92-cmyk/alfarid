<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

class CatalogLock
{
    public static function tree(bool $shared = false): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $function = $shared ? 'pg_advisory_xact_lock_shared' : 'pg_advisory_xact_lock';
            DB::select("SELECT {$function}(?)", [2026101501]);
        }
    }
}
