<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        ConcurrentIndex::ensure('users_name_trgm_index', 'ON users USING GIN (lower(name) gin_trgm_ops) WHERE NOT is_system');
        ConcurrentIndex::ensure('users_email_trgm_index', 'ON users USING GIN (lower(email) gin_trgm_ops) WHERE NOT is_system AND email IS NOT NULL');
        ConcurrentIndex::ensure('users_phone_trgm_index', 'ON users USING GIN (phone gin_trgm_ops) WHERE NOT is_system AND phone IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('users_phone_trgm_index');
        ConcurrentIndex::drop('users_email_trgm_index');
        ConcurrentIndex::drop('users_name_trgm_index');
    }
};
