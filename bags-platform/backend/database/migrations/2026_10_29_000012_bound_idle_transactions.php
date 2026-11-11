<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Runtime role settings are applied per database by 2026_11_12_000017.
    }

    public function down(): void
    {
        // H-3: a rollback on alfarid_testing must not strip production budgets
        // from the shared role. Scoped settings are handled by 2026_11_12_000017.
    }
};
