<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'supplier_users'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'remember_token')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->rememberToken();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customers', 'supplier_users'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'remember_token')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('remember_token');
                });
            }
        }
    }
};
