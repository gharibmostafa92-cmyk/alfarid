<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");
        // Fail explicitly if legacy rows need remediation; do not silently rewrite identities.
        foreach (['customers_nonnegative_credit', 'customers_approved_business', 'customers_valid_phone'] as $constraint) {
            DB::statement("ALTER TABLE customers VALIDATE CONSTRAINT {$constraint}");
        }
    }

    public function down(): void
    {
        // Validation does not change the constraint or accepted data.
    }
};
